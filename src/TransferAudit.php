<?php
declare(strict_types=1);

/** Reads the Node export dialect only. Never opens a database or executes SQL. */
final class TransferAudit
{
    private const MAX_BYTES = 536870912;
    private const MAX_LINE = 33554432;
    private array $profile;

    public function __construct(?string $profilePath = null)
    {
        $this->profile = json_decode((string)file_get_contents($profilePath ?? dirname(__DIR__).'/docs/TRANSFER-EXPORT-SCHEMA.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Optional trusted observer is for internal audits only; raw values never enter reports. */
    public function run(string $path, ?callable $observeRow = null): array
    {
        if (is_link($path) || !is_file($path) || !is_readable($path)) throw new InvalidArgumentException('Provide a readable regular SQL file.');
        $file = fopen($path, 'rb');
        if ($file === false) throw new InvalidArgumentException('Cannot read SQL file.');
        try {
            $before = fstat($file);
            if (($before['mode'] & 0170000) !== 0100000 || $before['size'] > self::MAX_BYTES) throw new InvalidArgumentException('Expected a regular file no larger than 512 MiB.');
            $report = ['scope'=>'TRANSFER_FILE_AUDIT_ONLY', 'importReady'=>false, 'formatValid'=>false, 'profileMatches'=>false, 'profileSourceCommit'=>$this->profile['sourceCommit'], 'sha256'=>null, 'bytes'=>0, 'tables'=>[], 'missingTables'=>[], 'errors'=>[], 'errorCount'=>0,
                'unchecked'=>['foreign_keys_and_unique_constraints','references_inside_json','runtime_adapters_and_schema','money_and_inventory_reconciliation','runtime_collation','physical_media_and_original_ticket_files']];
            $error = static function(string $code, int $line, ?string $table = null, ?string $column = null) use (&$report): void {
                $report['errorCount']++;
                if (count($report['errors']) < 100) $report['errors'][] = array_filter(['code'=>$code,'line'=>$line,'table'=>$table,'column'=>$column], static fn($v)=>$v!==null);
            };
            $hash = hash_init('sha256'); $lineNo = 0; $state = 'header'; $table = null; $columns = []; $counts = []; $profileMatches = true; $complete = false; $tableMatches = false;
            $preamble = [
                '-- DATA TRANSFER ONLY: jm_transfer_* staging tables, not a runnable PHP application schema.',
                '-- Import into a separate database. No DROP, overwrite, auth sessions or gateway secrets.',
                '-- Upload files are not included; copy media and original ticket files separately.',
                'SET NAMES utf8mb4;', "SET time_zone='+00:00';",
            ];
            $pre = 0;
            while (($raw = fgets($file, self::MAX_LINE + 2)) !== false) {
                $lineNo++; $report['bytes'] += strlen($raw); hash_update($hash, $raw);
                if (strlen($raw) > self::MAX_LINE || $report['bytes'] > self::MAX_BYTES) { $error('SIZE_LIMIT', $lineNo); break; }
                if (!str_ends_with($raw, "\n")) { $error('MISSING_LINE_TERMINATOR', $lineNo); break; }
                $line = rtrim($raw, "\r\n");
                if ($state === 'header') {
                    if (!preg_match('/^-- JinMemo MySQL transfer v1; generated \d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/D', $line)) { $error('INVALID_HEADER', $lineNo); break; }
                    $state = 'preamble'; continue;
                }
                if ($state === 'preamble') {
                    if ($line !== $preamble[$pre++]) { $error('INVALID_PREAMBLE', $lineNo); break; }
                    if ($pre === count($preamble)) $state = 'table';
                    continue;
                }
                if ($state === 'done') { $error('CONTENT_AFTER_COMPLETE', $lineNo); break; }
                if ($state === 'table') {
                    if (str_starts_with($line, '-- TRANSFER COMPLETE ')) {
                        $expected = []; foreach ($counts as $t=>$n) $expected['jinmemo_'.substr($t, 12)] = $n;
                        // Exact exporter JSON also rejects duplicate keys and injected trailing content.
                        if (substr($line, 21) !== json_encode((object)$expected, JSON_THROW_ON_ERROR)) $error('FOOTER_COUNTS_MISMATCH', $lineNo);
                        $complete = true; $state = 'done'; continue;
                    }
                    if (!preg_match('/^CREATE TABLE `(jm_transfer_[a-z_]+)` \($/D', $line, $m) || !isset($this->profile['tables'][$m[1]]) || isset($counts[$m[1]])) { $error('UNEXPECTED_TABLE_OR_SQL', $lineNo); break; }
                    $table = $m[1]; $columns = []; $counts[$table] = 0; $tableMatches = false; $state = 'columns'; continue;
                }
                if ($state === 'columns') {
                    if ($line === ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;') {
                        if ($columns === [] || end($columns)['comma']) { $error('INVALID_COLUMNS', $lineNo, $table); break; }
                        $actual = array_map(static fn($c)=>['name'=>$c['name'],'type'=>$c['type']], $columns);
                        $expected = array_map(static fn($c)=>['name'=>$c['name'],'type'=>$c['type']], $this->profile['tables'][$table]);
                        $tableMatches = $actual === $expected;
                        if (!$tableMatches) { $profileMatches = false; $error('SCHEMA_PROFILE_MISMATCH', $lineNo, $table); }
                        $report['tables'][$table] = ['rows'=>0,'columns'=>$actual]; $state = 'rows'; continue;
                    }
                    if (count($columns) >= 256 || ($columns !== [] && !end($columns)['comma']) || !preg_match('/^  `([a-z_][a-z0-9_]{0,63})` (JSON|CHAR\(36\)|TINYINT\(1\)|BIGINT|DECIMAL\(65,20\)|DOUBLE|DATE|DATETIME\(6\)|LONGTEXT) NULL(,?)$/D', $line, $m)) { $error('INVALID_COLUMN_DECLARATION', $lineNo, $table); break; }
                    if (in_array($m[1], ['secret','password_hash'], true) || in_array($m[1], array_column($columns,'name'), true)) { $error('FORBIDDEN_OR_DUPLICATE_COLUMN', $lineNo, $table); break; }
                    $columns[] = ['name'=>$m[1],'type'=>$m[2],'comma'=>$m[3]===',']; continue;
                }
                if ($line === '-- '.$table.': '.$counts[$table].' rows') { $state = 'table'; continue; }
                $prefix = 'INSERT INTO `'.$table.'` ('.implode(',',array_map(static fn($c)=>'`'.$c['name'].'`', $columns)).') VALUES (';
                if (!str_starts_with($line, $prefix) || !str_ends_with($line, ');')) { $error('INVALID_INSERT_OR_TABLE_COUNT', $lineNo, $table); break; }
                $values = $this->values(substr($line, strlen($prefix), -2));
                if ($values === null || count($values) !== count($columns)) { $error('INVALID_VALUES', $lineNo, $table); break; }
                $profile = $this->profile['tables'][$table];
                $validRow = true;
                foreach ($columns as $i=>$column) {
                    $source = $profile[$i] ?? null;
                    if ($source !== null && ($source['name'] !== $column['name'] || $source['type'] !== $column['type'])) $source = null;
                    if (!$this->validValue($values[$i], $column['type'], $source)) { $validRow = false; $error('INVALID_VALUE', $lineNo, $table, $column['name']); }
                }
                if ($observeRow !== null && $validRow && $tableMatches) $observeRow($table, $columns, $values, $lineNo, $counts[$table]+1);
                $counts[$table]++; $report['tables'][$table]['rows']++;
            }
            if (!feof($file) && $state !== 'done' && $report['errorCount'] === 0) $error('READ_FAILED', $lineNo);
            if (!$complete) $error('MISSING_COMPLETE_FOOTER', $lineNo);
            $after = fstat($file);
            if ($before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime'] || $before['ctime'] !== $after['ctime'] || $report['bytes'] !== $before['size']) $error('FILE_CHANGED_OR_NOT_FULLY_READ', $lineNo);
            $digest = hash_final($hash);
            $report['sha256'] = $report['bytes'] === $before['size'] ? $digest : null;
            $report['missingTables'] = array_values(array_diff(array_keys($this->profile['tables']), array_keys($counts)));
            $report['profileMatches'] = $profileMatches && $report['missingTables'] === [] && $report['errorCount'] === 0;
            $report['formatValid'] = $complete && $report['errorCount'] === 0;
            return $report;
        } finally { fclose($file); }
    }

    private function values(string $input): ?array
    {
        $values = []; $offset = 0; $length = strlen($input);
        while ($offset < $length) {
            if (!preg_match("/\G(NULL|-?\d+(?:\.\d+)?(?:e[+-]?\d+)?|CONVERT\(X'[0-9a-f]*' USING utf8mb4\))(,|$)/i", $input, $m, 0, $offset)) return null;
            $values[] = $m[1]; $offset += strlen($m[0]);
            if (count($values) > 256 || ($m[2] === ',' && $offset === $length)) return null;
        }
        return $values;
    }

    private function validValue(string $raw, string $type, ?array $source): bool
    {
        if ($raw === 'NULL') return $source === null || $source['nullable'];
        if (in_array($type, ['BIGINT','TINYINT(1)','DOUBLE','DECIMAL(65,20)'], true)) {
            if (!preg_match('/^-?\d+(?:\.\d+)?(?:e[+-]?\d+)?$/iD', $raw)) return false;
            if ($type === 'TINYINT(1)') return $raw === '0' || $raw === '1';
            if ($type === 'DOUBLE') return is_finite((float)$raw);
            if ($type === 'DECIMAL(65,20)') return $this->decimalFits($raw);
            if (!preg_match('/^-?\d+$/D', $raw)) return false;
            $negative = str_starts_with($raw, '-'); $digits = ltrim($raw, '-0'); if ($digits === '') return true;
            $limits = ['smallint'=>['32767','32768'],'integer'=>['2147483647','2147483648']];
            $limit = ($limits[$source['sourceType'] ?? ''] ?? ['9223372036854775807','9223372036854775808'])[$negative ? 1 : 0];
            return strlen($digits) < strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0);
        }
        if (!preg_match("/^CONVERT\(X'([0-9a-f]*)' USING utf8mb4\)$/D", $raw, $m) || strlen($m[1]) % 2 !== 0) return false;
        $value = hex2bin($m[1]);
        if ($value === false || preg_match('//u', $value) !== 1) return false;
        if ($type === 'JSON') { try { json_decode($value, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); return true; } catch (JsonException) { return false; } }
        if ($type === 'CHAR(36)') return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) === 1;
        if ($type === 'DATE' || $type === 'DATETIME(6)') {
            $format = $type === 'DATE' ? 'Y-m-d' : 'Y-m-d H:i:s.u';
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('UTC'));
            return $date !== false && $date->format($format) === $value && substr($value,0,4) >= '1000';
        }
        return true;
    }

    private function decimalFits(string $value): bool
    {
        preg_match('/^-?(\d+)(?:\.(\d+))?(?:e([+-]?\d+))?$/iD', $value, $m);
        $exponent = $m[3] ?? '0';
        if (strlen(ltrim($exponent, '+-0')) > 4) return false;
        $digits = $m[1].($m[2] ?? ''); $point = strlen($m[1]) + (int)$exponent;
        $leading = strlen($digits) - strlen(ltrim($digits, '0'));
        $trailing = strlen($digits) - strlen(rtrim($digits, '0'));
        if ($leading === strlen($digits)) return true;
        return max(0,$point-$leading) <= 45 && max(0,strlen($digits)-$trailing-$point) <= 20;
    }
}
