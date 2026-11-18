<?php
declare(strict_types=1);

/** Reads the Node export dialect only. Never opens a database or executes SQL. */
final class TransferV2Audit
{
    private const MAX_BYTES = 536870912;
    private const MAX_LINE = 33554432;
    private array $profile;

    public function __construct(?string $profilePath = null)
    {
        $this->profile = json_decode((string)file_get_contents($profilePath ?? dirname(__DIR__).'/docs/TRANSFER-V2-SCHEMA.json'), true, 512, JSON_THROW_ON_ERROR);
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
            $report = ['scope'=>'TRANSFER_FILE_AUDIT_ONLY', 'format'=>'full-v2', 'manifestValid'=>false, 'importReady'=>false, 'formatValid'=>false, 'profileMatches'=>false, 'profileSourceCommit'=>$this->profile['sourceCommit'], 'sha256'=>null, 'bytes'=>0, 'tables'=>[], 'missingTables'=>[], 'errors'=>[], 'errorCount'=>0,
                'unchecked'=>['foreign_keys_and_unique_constraints','references_inside_json','runtime_adapters_and_schema','money_and_inventory_reconciliation','runtime_collation','physical_media_and_original_ticket_files']];
            $error = static function(string $code, int $line, ?string $table = null, ?string $column = null) use (&$report): void {
                $report['errorCount']++;
                if (count($report['errors']) < 100) $report['errors'][] = array_filter(['code'=>$code,'line'=>$line,'table'=>$table,'column'=>$column], static fn($v)=>$v!==null);
            };
            $hash = hash_init('sha256'); $lineNo = 0; $state = 'header'; $table = null; $columns = []; $counts = []; $profileMatches = true; $complete = false; $tableMatches = false;
            $preamble = [
                '-- STAGING ONLY: import into a separate database; mapping required for PHP.',
                '-- Business settings only. No passwords, login sessions, SMTP or gateway credentials.',
                '-- Original uploads NOT included. Historical values preserved; do not recalculate orders.',
                'SET NAMES utf8mb4;', "SET time_zone='+00:00';",
            ];
            $pre = 0; $tableHashes=[]; $manifest=[]; $insertHash=null;
            while (($raw = fgets($file, self::MAX_LINE + 2)) !== false) {
                $lineNo++; $report['bytes'] += strlen($raw); hash_update($hash, $raw);
                if (strlen($raw) > self::MAX_LINE || $report['bytes'] > self::MAX_BYTES) { $error('SIZE_LIMIT', $lineNo); break; }
                if (!str_ends_with($raw, "\n")) { $error('MISSING_LINE_TERMINATOR', $lineNo); break; }
                $line = rtrim($raw, "\r\n");
                if ($state === 'header') {
                    if (!preg_match('/^-- JinMemo MySQL full transfer v2; generated \d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/D', $line)) { $error('INVALID_HEADER', $lineNo); break; }
                    $state = 'preamble'; continue;
                }
                if ($state === 'preamble') {
                    if ($line !== $preamble[$pre++]) { $error('INVALID_PREAMBLE', $lineNo); break; }
                    if ($pre === count($preamble)) $state = 'table';
                    continue;
                }
                if ($state === 'done') { $error('CONTENT_AFTER_COMPLETE', $lineNo); break; }
                if($state==='rows' && (str_starts_with($line,'CREATE TABLE ') || str_starts_with($line,'-- TRANSFER COMPLETE '))){$tableHashes[$table]=hash_final($insertHash);$state='table';}
                if ($state === 'table') {
                    if (str_starts_with($line, '-- TRANSFER COMPLETE ')) {
                        $expected = []; foreach ($counts as $t=>$n) if($t!=='jm_transfer_manifest')$expected['jinmemo_'.substr($t, 12)] = $n;
                        // Exact exporter JSON also rejects duplicate keys and injected trailing content.
                        if (substr($line, 21) !== json_encode((object)$expected, JSON_THROW_ON_ERROR)) $error('FOOTER_COUNTS_MISMATCH', $lineNo);
                        $complete = true; $state = 'done'; continue;
                    }
                    if (!preg_match('/^CREATE TABLE `(jm_transfer_[a-z_]+)` \($/D', $line, $m) || !isset($this->profile['tables'][$m[1]]) || isset($counts[$m[1]])) { $error('UNEXPECTED_TABLE_OR_SQL', $lineNo); break; }
                    $table = $m[1]; $insertHash=hash_init('sha256'); $columns = []; $counts[$table] = 0; $tableMatches = false; $state = 'columns'; continue;
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
                    if (self::secret($m[1]) || in_array($m[1], array_column($columns,'name'), true)) { $error('FORBIDDEN_OR_DUPLICATE_COLUMN', $lineNo, $table); break; }
                    $columns[] = ['name'=>$m[1],'type'=>$m[2],'comma'=>$m[3]===',']; continue;
                }
                $prefix = 'INSERT INTO `'.$table.'` ('.implode(',',array_map(static fn($c)=>'`'.$c['name'].'`', $columns)).') VALUES (';
                if (!str_starts_with($line, $prefix) || !str_ends_with($line, ');')) { $error('INVALID_INSERT_OR_TABLE_COUNT', $lineNo, $table); break; }
                hash_update($insertHash,$raw);
                $values = $this->values(substr($line, strlen($prefix), -2));
                if ($values === null || count($values) !== count($columns)) { $error('INVALID_VALUES', $lineNo, $table); break; }
                $profile = $this->profile['tables'][$table];
                $validRow = true;
                foreach ($columns as $i=>$column) {
                    $source = $profile[$i] ?? null;
                    if ($source !== null && ($source['name'] !== $column['name'] || $source['type'] !== $column['type'])) $source = null;
                    if (!$this->validValue($values[$i], $column['type'], $source)) { $validRow = false; $error('INVALID_VALUE', $lineNo, $table, $column['name']); }
                }
                if($validRow && $tableMatches && $table==='jm_transfer_manifest'){if(count($manifest)>=256){$error('MANIFEST_LIMIT',$lineNo);break;}$manifest[]=array_combine(array_column($columns,'name'),array_map([self::class,'decode'],$values));}
                if($validRow && $table==='jm_transfer_settings'){if(!in_array(self::decode($values[0]),$this->profile['businessSettingIds'],true)){$validRow=false;$error('SETTING_NOT_ALLOWED',$lineNo,$table);}}
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
            if($complete)$this->manifest($manifest,$counts,$tableHashes,$error);
            $report['manifestValid']=$complete && $report['errorCount']===0;
            $report['profileMatches'] = $profileMatches && $report['manifestValid'];
            $report['formatValid'] = $complete && $report['errorCount'] === 0;
            return $report;
        } finally { fclose($file); }
    }

    public static function secret(string $key):bool
    {
        $key=str_replace(['_','-'],'',strtolower($key));
        if(in_array($key,explode(' ','password passwordhash secret smtppassword smtppasswordencrypted token tokenhash accesstoken refreshtoken accesskey accesshash apikey clientsecret encryptedpassword authorization cookie payloadencrypted'),true))return true;
        foreach(['secret','secretkey','password','passwordhash','apikey','privatekey'] as $suffix)if(str_ends_with($key,$suffix))return true;return false;
    }
    private static function noSecrets(mixed $value):bool
    {
        if($value instanceof stdClass){foreach(get_object_vars($value) as $key=>$v)if(self::secret((string)$key)||!self::noSecrets($v))return false;}
        elseif(is_array($value))foreach($value as $v)if(!self::noSecrets($v))return false;return true;
    }
    private static function decode(string $raw):?string{return $raw==='NULL'?null:(str_starts_with($raw,'CONVERT(')?hex2bin(substr($raw,10,-16)):$raw);}
    private function manifest(array $entries,array $counts,array $hashes,callable $error):void
    {
        if(!isset($counts['jm_transfer_manifest'])){$error('MANIFEST_MISSING',0);return;}$seen=[];
        foreach($entries as $entry){
            $source=$entry['source_table'];$target='jm_transfer_'.substr((string)$source,8);
            if(isset($seen[$source])){$error('DUPLICATE_MANIFEST_ENTRY',0,'jm_transfer_manifest');continue;}$seen[$source]=true;
            $columns=json_decode($entry['columns_json'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($columns)||!array_is_list($columns)){$error('INVALID_MANIFEST_COLUMNS',0,'jm_transfer_manifest');continue;}
            if(in_array($source,$this->profile['excludedSourceTables'],true)){
                if($entry['status']!=='EXCLUDED_SECURITY_OR_RUNTIME'||$entry['target_table']!==null||$entry['row_count']!==null||$entry['insert_sha256']!==null||$columns!==[])$error('INVALID_EXCLUSION_ENTRY',0,'jm_transfer_manifest');continue;
            }
            if(!is_string($source)||!str_starts_with($source,'jinmemo_')||$target==='jm_transfer_manifest'||!isset($this->profile['tables'][$target])||$entry['target_table']!==$target){$error('UNEXPECTED_MANIFEST_ENTRY',0,'jm_transfer_manifest');continue;}
            if($entry['status']==='MISSING_SOURCE'){
                if(isset($counts[$target])||$entry['row_count']!=='0'||$entry['insert_sha256']!==null||$columns!==[])$error('INVALID_MISSING_SOURCE_ENTRY',0,$target);continue;
            }
            $status=$target==='jm_transfer_settings'?'FILTERED_BUSINESS_SETTINGS':'EXPORTED';
            if($entry['status']!==$status||!isset($counts[$target])||$entry['row_count']!==(string)$counts[$target]){$error('MANIFEST_COUNTS_OR_STATUS_MISMATCH',0,$target);continue;}
            if(!is_string($entry['insert_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$entry['insert_sha256'])||!hash_equals($hashes[$target]??'',$entry['insert_sha256']))$error('MANIFEST_INSERT_SHA256_MISMATCH',0,$target);
            $expected=$this->profile['tables'][$target];if(count($columns)!==count($expected)){$error('MANIFEST_COLUMNS_MISMATCH',0,$target);continue;}
            foreach($expected as $i=>$c){$actual=$columns[$i];if(!is_array($actual)||count($actual)!==2||($actual['column_name']??null)!==$c['name']||($actual['data_type']??null)!==$c['manifestDataType']){$error('MANIFEST_COLUMNS_MISMATCH',0,$target);break;}}
        }
        foreach($this->profile['tables'] as $target=>$columns)if($target!=='jm_transfer_manifest'&&!isset($seen['jinmemo_'.substr($target,12)]))$error('MISSING_MANIFEST_ENTRY',0,$target);
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
        if ($type === 'JSON' || ($source['json']??false)) { try { $decoded=json_decode($value, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); return self::noSecrets($decoded); } catch (JsonException) { return false; } }
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
