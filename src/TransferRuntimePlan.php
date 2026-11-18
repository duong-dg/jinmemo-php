<?php
declare(strict_types=1);

/** Read-only schema comparison. Column matches never certify behavior or authorize publication. */
final class TransferRuntimePlan
{
    public function __construct(private PDO $db){}
    public function run():array
    {
        $profile=json_decode((string)file_get_contents(dirname(__DIR__).'/docs/TRANSFER-EXPORT-SCHEMA.json'),true,512,JSON_THROW_ON_ERROR);
        $schema=(string)$this->db->query('SELECT DATABASE()')->fetchColumn();$tables=[];$matches=0;
        $q=$this->db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        foreach($profile['tables'] as $source=>$columns){
            $target='jingo_'.substr($source,12);$q->execute([$schema,$target]);$actual=$q->fetchAll(PDO::FETCH_COLUMN);$expected=array_column($columns,'name');$missing=array_values(array_diff($expected,$actual));$extra=array_values(array_diff($actual,$expected));$same=$actual!==[]&&$missing===[]&&$extra===[];
            if($same)$matches++;$tables[$source]=['target'=>$target,'status'=>$actual===[]?'MISSING_RUNTIME_TABLE':($same?'COLUMN_SET_MATCH_ONLY':'ADAPTER_REQUIRED'),'missingSourceColumns'=>$missing,'runtimeOnlyColumns'=>$extra];
        }
        return ['scope'=>'runtime-schema-plan-only','publicationReady'=>false,'sourceCommit'=>$profile['sourceCommit'],'tablesCompared'=>count($tables),'columnSetsMatching'=>$matches,'tables'=>$tables,
            'blockers'=>[
                'ATOMIC_RUNTIME_PUBLICATION_AND_ALL_ADAPTERS_NOT_IMPLEMENTED',
                'SOURCE_EXPORT_OMITS_CART_AND_STAY_PROOF_SECRETS',
                'MAIL_LOOKUP_KEYS_NOT_EXPORTED_PRIVATE_BACKUP_OR_EXPLICIT_RESET_PLAN_REQUIRED',
                'ORDER_ACTION_VERSIONS_NOT_EXPORTED_USE_LEGACY_HISTORY_ADAPTER',
                'GATEWAY_ATTEMPTS_NOT_EXPORTED_UNRESOLVED_REFERENCES_CANNOT_BE_INVENTED',
                'PRODUCTION_PROVIDER_SMTP_CPANEL_AND_REAL_DATA_ACCEPTANCE_REQUIRED'
            ]];
    }
}
