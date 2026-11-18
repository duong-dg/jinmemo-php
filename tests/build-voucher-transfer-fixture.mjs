// Uses the pinned Node exporter's pure helpers. No application/database startup.
import fs from 'node:fs';import path from 'node:path';import vm from 'node:vm';import crypto from 'node:crypto';import {createRequire} from 'node:module';
const root=process.argv[2];if(!root)throw Error('Provide local Node repository.');
const profile=JSON.parse(fs.readFileSync(new URL('../docs/TRANSFER-EXPORT-SCHEMA.json',import.meta.url)));
const source=fs.readFileSync(path.join(root,'apps/api/src/admin/mysql-export.ts'),'utf8');
if(crypto.createHash('sha256').update(source).digest('hex')!==profile.exporterSha256)throw Error('Pinned exporter differs.');
const ts=createRequire(path.resolve(root,'package.json'))('typescript'),exports={};vm.runInNewContext(ts.transpileModule(source.slice(0,source.indexOf('@Injectable()')),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText,{exports,Buffer});
const id=n=>'00000000-0000-4000-8000-'+String(n).padStart(12,'0');
const base={name:'Private voucher fixture',discountType:'PERCENT',value:10,minSubtotal:0,maxDiscount:50000,startDate:'2000-01-01',endDate:'2100-12-31',maxUses:2,enabled:true,version:0,notes:'private@example.test'};
const vouchers=[{...base,id:id(70),code:'MIGRATE10'}, {...base,id:id(71),code:'MIGRATE_VARIANT',scope:'VARIANTS',targets:[{productId:'p',variantId:'eco'}],visitFrom:'2099-06-01',visitTo:'2099-06-30',visitWeekdays:[1,2,3,4,5,6,0]}, {...base,id:id(72),code:'MIGRATE_FIXED',discountType:'FIXED',value:20000,maxDiscount:null,enabled:false,scope:'PRODUCTS',targets:[{productId:'p'}]}];
const columns=[{column_name:'id',data_type:'text'},{column_name:'code',data_type:'text'},{column_name:'data',data_type:'jsonb'},{column_name:'version',data_type:'integer'}];
let sql='-- Synthetic vouchers from original Node exporter - usage remains in staging.\n'+exports.mysqlCreate('jinmemo_vouchers',columns)+exports.mysqlInsert('jinmemo_vouchers',columns,vouchers.map((v,i)=>({id:v.id,code:v.code,data:JSON.stringify(v),version:String(12+i)})));
const uses=[{column_name:'order_id',data_type:'text'},{column_name:'voucher_id',data_type:'text'},{column_name:'released',data_type:'boolean'}];
sql+=exports.mysqlCreate('jinmemo_voucher_uses',uses)+exports.mysqlInsert('jinmemo_voucher_uses',uses,[{order_id:id(901),voucher_id:id(70),released:'false'},{order_id:id(902),voucher_id:id(71),released:'true'}]);
fs.writeFileSync(new URL('./fixtures/voucher-transfer-node.sql',import.meta.url),sql);console.log('Generated original Node voucher and usage fixture.');
