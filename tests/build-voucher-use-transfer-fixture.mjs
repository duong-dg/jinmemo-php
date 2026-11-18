// Real Node exporter helpers for order parents and channel tables. Does not run the application.
import fs from 'node:fs';import path from 'node:path';import vm from 'node:vm';import crypto from 'node:crypto';import {createRequire} from 'node:module';
const root=process.argv[2];if(!root)throw Error('Provide local Node repository.');
const profile=JSON.parse(fs.readFileSync(new URL('../docs/TRANSFER-EXPORT-SCHEMA.json',import.meta.url)));
const source=fs.readFileSync(path.join(root,'apps/api/src/admin/mysql-export.ts'),'utf8');
if(crypto.createHash('sha256').update(source).digest('hex')!==profile.exporterSha256)throw Error('Pinned exporter differs.');
const ts=createRequire(path.resolve(root,'package.json'))('typescript'),exports={};vm.runInNewContext(ts.transpileModule(source.slice(0,source.indexOf('@Injectable()')),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText,{exports,Buffer});
const id=n=>'00000000-0000-4000-8000-'+String(n).padStart(12,'0');
const orders=[901,902].map((n,i)=>({id:id(n),code:'JM'+String(n).padStart(10,'0'),request_id:id(n+1000),request_hash:'a'.repeat(64),access_hash:'b'.repeat(64),
 snapshot:JSON.stringify({productId:'p',variantId:'eco',productName:'Private historical product',variantName:'Historical variant',date:'2099-06-02',total:i?180000:500000,demo:false,lines:[{customerType:'ADULT',quantity:1,unitPrice:i?200000:550000,subtotal:i?200000:550000}],discount:{code:i?'MIGRATE_VARIANT':'MIGRATE10',amount:i?20000:50000,subtotal:i?200000:550000},conditions:{},policy:'Historical policy'}),
 contact:JSON.stringify({name:'Private historical guest',phone:'0901234567',email:'history-private@example.test'}),booking_status:i?'CANCELLED':'CONFIRMED',payment_status:'UNPAID',instructions:'Historical instructions',version:String(i+4),created_at:'2026-11-18 00:00:00.123456'}));
let sql='-- Synthetic historical order parents from original Node exporter - not an order importer.\n';
for(const table of ['orders','partner_orders','pos_orders']){
 const columns=profile.tables['jm_transfer_'+table].map(c=>({column_name:c.name,data_type:{text:'text',jsonb:'jsonb',integer:'integer',timestamptz:'timestamp with time zone'}[c.sourceType]}));
 sql+=exports.mysqlCreate('jinmemo_'+table,columns)+exports.mysqlInsert('jinmemo_'+table,columns,table==='orders'?orders:[]);
}
fs.writeFileSync(new URL('./fixtures/voucher-use-parents-node.sql',import.meta.url),sql);console.log('Generated original Node historical order and channel fixture.');
