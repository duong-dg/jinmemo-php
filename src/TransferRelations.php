<?php
declare(strict_types=1);
require_once __DIR__.'/TransferFormat.php';

/** Two read-only streaming passes; indexes contain hashes, never customer identifiers. */
final class TransferRelations
{
    private array $profile;
    private array $constraints;
    private int $maxKeys;

    public function __construct(int $maxKeys = 100000)
    {
        if ($maxKeys < 1 || $maxKeys > 100000) throw new InvalidArgumentException('Invalid audit index limit.');
        $this->maxKeys = $maxKeys;
        $this->profile = json_decode((string)file_get_contents(dirname(__DIR__).'/docs/TRANSFER-EXPORT-SCHEMA.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->constraints = json_decode((string)file_get_contents(dirname(__DIR__).'/docs/TRANSFER-RELATIONS.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($this->profile['sourceCommit'] !== $this->constraints['sourceCommit'] || $this->profile['schemaSha256'] !== $this->constraints['schemaSha256'] || array_keys($this->profile['tables']) !== array_keys($this->constraints['tables'])) throw new RuntimeException('Constraint profile differs from schema profile.');
    }

    public function run(string $path): array
    {
        $format=TransferFormat::detect($path);
        $this->profile=json_decode((string)file_get_contents(TransferFormat::profile($format)),true,512,JSON_THROW_ON_ERROR);
        $this->constraints=json_decode((string)file_get_contents(TransferFormat::relations($format)),true,512,JSON_THROW_ON_ERROR);
        if($this->profile['sourceCommit']!==$this->constraints['sourceCommit']||$this->profile['schemaSha256']!==$this->constraints['schemaSha256']||array_keys($this->profile['tables'])!==array_keys($this->constraints['tables']))throw new RuntimeException('Constraint profile differs.');
        $result = ['status'=>'SKIPPED','declaredConstraintsValid'=>null,'indexedKeys'=>0,'maxIndexedKeys'=>$this->maxKeys,'rowsChecked'=>0,'foreignReferencesChecked'=>0,'unresolvedReferences'=>[],'errors'=>[],'errorCount'=>0];
        $indexes = []; $limit = false;
        $error = static function(string $code, ?string $table = null, ?array $columns = null, ?int $line = null, ?int $row = null) use (&$result): void {
            $result['errorCount']++;
            if (count($result['errors']) < 100) $result['errors'][] = array_filter(['code'=>$code,'table'=>$table,'columns'=>$columns,'line'=>$line,'row'=>$row],static fn($v)=>$v!==null);
        };
        $audit = TransferFormat::audit($path);
        $report = $audit->run($path, function(string $table, array $columns, array $values, int $line, int $row) use (&$indexes,&$result,&$limit,$error): void {
            if ($limit) return;
            $spec = $this->constraints['tables'][$table];
            $keys = [['columns'=>$spec['primary'],'kind'=>'PRIMARY']];
            foreach ($spec['unique'] as $key) $keys[] = ['columns'=>$key,'kind'=>'UNIQUE'];
            $raw = array_combine(array_column($columns,'name'),$values);
            foreach ($keys as $key) {
                $tuple = $this->tuple($table,$key['columns'],$raw);
                if ($tuple === null) continue; // PostgreSQL UNIQUE permits multiple SQL NULL values.
                $id = implode(',',$key['columns']);
                if (isset($indexes[$table][$id][$tuple])) { $error('DUPLICATE_'.$key['kind'],$table,$key['columns'],$line,$row); continue; }
                if ($result['indexedKeys'] >= $this->maxKeys) { $limit=true; $error('INDEX_LIMIT'); return; }
                $indexes[$table][$id][$tuple] = true; $result['indexedKeys']++;
            }
            $result['rowsChecked']++;
        });
        if (!$report['formatValid']) { $report['relations']=$result; return $report; }
        if ($limit) { $result['status']='INCOMPLETE'; $report['relations']=$result; return $report; }
        $unresolved = [];
        $second = $audit->run($path, function(string $table, array $columns, array $values, int $line, int $row) use (&$indexes,&$result,&$unresolved,$report,$error): void {
            $raw = array_combine(array_column($columns,'name'),$values);
            foreach ($this->constraints['tables'][$table]['foreign'] as $fk) {
                $tuple = $this->tuple($table,$fk['columns'],$raw);
                if ($tuple === null) continue;
                $target = $fk['targetTable'];
                if (!isset($report['tables'][$target])) {
                    $id = $table.':'.implode(',',$fk['columns']);
                    if (!isset($unresolved[$id])) $unresolved[$id] = ['table'=>$table,'columns'=>$fk['columns'],'targetTable'=>$target,'targetColumns'=>$fk['targetColumns'],'reason'=>isset($this->profile['tables'][$target])?'TARGET_TABLE_MISSING':'TARGET_NOT_EXPORTED','references'=>0];
                    $unresolved[$id]['references']++; continue;
                }
                $result['foreignReferencesChecked']++;
                if (!isset($indexes[$target][implode(',',$fk['targetColumns'])][$tuple])) $error('MISSING_FOREIGN_TARGET',$table,$fk['columns'],$line,$row);
            }
        });
        $result['unresolvedReferences'] = array_values($unresolved);
        if (!$second['formatValid'] || $second['sha256'] !== $report['sha256']) $error('INPUT_CHANGED_BETWEEN_PASSES');
        $result['status'] = $result['errorCount'] > 0 ? 'FAILED' : ($unresolved !== [] ? 'INCOMPLETE' : 'PASSED');
        $result['declaredConstraintsValid'] = $result['status'] === 'FAILED' ? false : ($result['status'] === 'PASSED' ? true : null);
        $report['unchecked'] = array_values(array_diff($report['unchecked'],['foreign_keys_and_unique_constraints']));
        if ($result['status'] === 'INCOMPLETE') $report['unchecked'][] = 'declared_constraints_not_fully_checked';
        $report['relations'] = $result;
        return $report;
    }

    private function tuple(string $table, array $columns, array $raw): ?string
    {
        $types = array_column($this->profile['tables'][$table],'type','name');
        $hash = hash_init('sha256');
        foreach ($columns as $column) {
            if (!isset($raw[$column]) || $raw[$column] === 'NULL') return null;
            $value = $raw[$column];
            if (str_starts_with($value,'CONVERT(')) {
                $value = hex2bin(substr($value,10,-16));
                if ($value === false) throw new RuntimeException('Invalid internal transfer value.');
                if ($types[$column] === 'CHAR(36)') $value = strtolower($value);
            } elseif ($types[$column] === 'BIGINT') {
                $negative = str_starts_with($value,'-');
                $value = ltrim($negative ? substr($value,1) : $value,'0');
                $value = $value === '' ? '0' : ($negative ? '-'.$value : $value);
            } else throw new RuntimeException('Unsupported declared key type.');
            hash_update($hash,strlen($value).':'.$value);
        }
        return hash_final($hash,true);
    }
}
