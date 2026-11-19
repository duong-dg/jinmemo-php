<?php
declare(strict_types=1);
require_once __DIR__.'/TransferRelations.php';
/** Source-wide inventory of migration capability. It never connects to a database or certifies publication. */
final class TransferCoverage
{
    public static function adapters():array
    {
        $map=[];$add=static function(array $tables,array $commands,string $input,string $scope)use(&$map):void{foreach($tables as $table)$map[$table]=['coverage'=>'PARTIAL_NATIVE','commands'=>$commands,'input'=>$input,'scope'=>$scope];};
        $add(['product_types','destinations','suppliers','counters'],['import-catalog.php'],'STAGING_DATABASE','Danh mục theo validator và fingerprint');
        $add(['media'],['import-media.php'],'STAGING_DATABASE_AND_FILES','Metadata ảnh, cần file gốc');
        $add(['products'],['import-products.php'],'STAGING_DATABASE','Sản phẩm/gói/lịch giá trong phạm vi adapter');
        $add(['post_categories','posts','post_revisions','post_metrics'],['import-posts.php'],'STAGING_DATABASE','Blog trong phạm vi adapter');
        $add(['slug_aliases'],['import-catalog.php','import-products.php','import-posts.php'],'STAGING_DATABASE','Alias theo loại nội dung được hỗ trợ');
        $add(['price_groups'],['import-price-groups.php'],'STAGING_DATABASE','Nhóm giá');$add(['partners'],['import-partners.php'],'STAGING_DATABASE','Hồ sơ chờ reset, không mật khẩu/session');$add(['vouchers'],['import-vouchers.php'],'STAGING_DATABASE','Metadata chờ đối soát');$add(['voucher_uses'],['import-voucher-uses.php'],'STAGING_DATABASE','Cần cha runtime và quan hệ nguyên bản');
        $add(['settings'],['import-business-settings.php','import-rides.php'],'FULL_V2_FILE','Chỉ manual-payments/gifts/transport-plan và phạm vi liên quan');
        $add(['gift_orders'],['import-business-settings.php'],'FULL_V2_FILE','Đơn độc lập và mã tổng chỉ quà, còn giới hạn');$add(['checkout_bundles','checkout_members'],['import-business-settings.php'],'FULL_V2_FILE','Chỉ mã tổng quà, pending cấp lại khóa');
        $add(['ride_bookings','ride_pools','ride_payment_events'],['import-rides.php'],'FULL_V2_FILE','Xe WEB private/shared, SePay và refunds theo flag; chưa POS/cash/bundle/legacy');$add(['payment_references','gateway_events'],['import-rides.php','import-business-settings.php'],'FULL_V2_FILE','Chỉ receipts/events của graph đã hỗ trợ, không toàn bộ ledger');$add(['refunds'],['import-refunds.php','import-rides.php'],'FULL_V2_FILE','Cần cha/receipt đúng hoặc graph xe nguyên tử');$add(['inventory_configs','inventory_buckets','inventory_allocations'],['import-inventory.php'],'FULL_V2_FILE','Tồn và phân bổ gốc; cần sản phẩm/đơn native khớp, chưa tự xuất bản đơn vé');$add(['contact_requests'],['import-contact-requests.php'],'FULL_V2_FILE','NEW/DONE/TRASH, VI/EN và thời gian gốc');return $map;
    }
    private static function capability(string $name,array $map):array
    {
        $cap=$map[$name]??['coverage'=>'HOLDING_ONLY','commands'=>[],'input'=>null,'scope'=>'Chưa có bộ nhập runtime đầy đủ'];if(in_array($name,['admins','pos_users'],true))$cap=['coverage'=>'RESET_REQUIRED','commands'=>[],'input'=>null,'scope'=>'Không có mật khẩu trong export, cần luồng reset và phân quyền'];if(in_array($name,['mail_outbox','stay_mail','admin_notifications'],true))$cap=['coverage'=>'ARCHIVED_DO_NOT_REPLAY','commands'=>[],'input'=>null,'scope'=>'Không tự phát lại mail/thông báo cũ'];if($name==='manifest')$cap=['coverage'=>'METADATA','commands'=>[],'input'=>null,'scope'=>'Checksum và khai báo nguồn'];return $cap;
    }
    public static function archiveSummary(array $counts,string $profilePath):array
    {
        $profile=json_decode(file_get_contents($profilePath),true,512,JSON_THROW_ON_ERROR);$map=self::adapters();$tables=[];foreach($profile['tables'] as $table=>$columns){$name=substr($table,12);$tables[]=['table'=>$name,'archiveRows'=>(int)($counts[$table]??0)]+self::capability($name,$map);}
        return ['scope'=>'ARCHIVE_COUNTS_AND_ADAPTER_MAP','publicationReady'=>false,'perRowValidated'=>false,'tables'=>$tables];
    }
    public function run(string $file):array
    {
        $r=['scope'=>'source-wide-migration-coverage','sourceVerified'=>false,'sourceComplete'=>false,'allConverted'=>false,'publicationReady'=>false,'liveDataImported'=>false,'sourceSha256'=>null,'tables'=>[],'pendingSourceTables'=>[],'requiresScopeAudit'=>[],'errors'=>[],
            'remainingGroups'=>['NATIVE_TICKET_ORDERS_CARTS_PAYMENTS_AND_PRIVATE_LOOKUP','TICKET_STOCK_INVENTORY_SUPPLY_AND_SUPPLIER_LEDGER','PARTNER_ORDERS_DEBT_AND_SETTLEMENT','POS_USERS_SHIFTS_CASH_RECEIPTS_AND_HISTORY','STAYS_BOOKINGS_VOUCHERS_EVENTS_AND_LOOKUP','MIXED_CHECKOUT_BUNDLES_AND_ORIGINAL_FILES','REMAINING_CONTENT_SETTINGS_HISTORY_AND_CREDENTIAL_RESETS'],
            'unchecked'=>['native_target_state_and_per_row_adapters','original_media_and_pdf_bytes','credential_reset_and_missing_lookup_proofs','provider_smtp_cpanel_and_cutover_acceptance']];
        if(TransferFormat::detect($file)!=='full-v2'){$r['errors'][]=['code'=>'FULL_V2_REQUIRED'];return $r;}$audit=(new TransferRelations())->run($file);$r['sourceSha256']=$audit['sha256'];if(!$audit['profileMatches']||$audit['relations']['errorCount']){$r['errors'][]=['code'=>'SOURCE_FILE_OR_RELATIONS_INVALID'];return $r;}$manifest=[];
        $again=TransferFormat::audit($file)->run($file,static function($table,$cols,$values)use(&$manifest):void{if($table!=='jm_transfer_manifest')return;$row=[];foreach($cols as $i=>$c){$v=$values[$i];$row[$c['name']]=$v==='NULL'?null:(str_starts_with($v,'CONVERT(')?hex2bin(substr($v,10,-16)):$v);}$manifest[substr($row['source_table'],8)]=$row['status'];});if(!$again['profileMatches']||$again['sha256']!==$audit['sha256']){$r['errors'][]=['code'=>'SOURCE_CHANGED_DURING_READ'];return $r;}$r['sourceVerified']=true;$r['sourceComplete']=true;$map=self::adapters();$profile=json_decode(file_get_contents(dirname(__DIR__).'/docs/TRANSFER-V2-SCHEMA.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($profile['tables'] as $table=>$columns){$name=substr($table,12);$count=(int)($audit['tables'][$table]['rows']??0);$status=$name==='manifest'?'METADATA':($manifest[$name]??'NOT_DECLARED');$cap=self::capability($name,$map);
            $r['tables'][]=['table'=>$name,'sourceRows'=>$count,'sourceStatus'=>$status]+$cap;if(in_array($status,['MISSING_SOURCE','NOT_DECLARED'],true))$r['sourceComplete']=false;if($count&&in_array($cap['coverage'],['HOLDING_ONLY','RESET_REQUIRED'],true))$r['pendingSourceTables'][]=$name;if($count&&$cap['coverage']==='PARTIAL_NATIVE')$r['requiresScopeAudit'][]=$name;
        }if(!is_file($file)||is_link($file)||hash_file('sha256',$file)!==$audit['sha256']){$r['sourceVerified']=false;$r['sourceComplete']=false;$r['errors'][]=['code'=>'SOURCE_CHANGED_BEFORE_REPORT'];}return $r;
    }
}
