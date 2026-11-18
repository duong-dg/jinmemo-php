// Complete, internally linked 61-table synthetic export via the pinned original Node exporter.
import fs from 'node:fs';import path from 'node:path';import vm from 'node:vm';import crypto from 'node:crypto';import {createRequire} from 'node:module';
const root=process.argv[2];if(!root)throw Error('Provide local Node repository.');
const profile=JSON.parse(fs.readFileSync(new URL('../docs/TRANSFER-EXPORT-SCHEMA.json',import.meta.url))),source=fs.readFileSync(path.join(root,'apps/api/src/admin/mysql-export.ts'),'utf8');
if(crypto.createHash('sha256').update(source).digest('hex')!==profile.exporterSha256)throw Error('Pinned exporter differs.');
const ts=createRequire(path.resolve(root,'package.json'))('typescript'),exports={};vm.runInNewContext(ts.transpileModule(source.slice(0,source.indexOf('@Injectable()')),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText,{exports,Buffer});
const rows={};
for(const file of ['catalog-transfer-node.sql','posts-transfer-node.sql','product-transfer-node.sql','price-group-transfer-node.sql','partner-transfer-node.sql','voucher-transfer-node.sql','voucher-use-parents-node.sql','order-relation-transfer-node.sql']){
 const sql=fs.readFileSync(new URL('./fixtures/'+file,import.meta.url),'utf8');
 for(const m of sql.matchAll(/^INSERT INTO `(jm_transfer_[a-z_]+)` \(([^\n]+)\) VALUES \(([^\n]+)\);\r?$/gm)){
  const names=m[2].split(',').map(n=>n.slice(1,-1)),values=m[3].split(',').map(t=>t==='NULL'?null:t.startsWith('CONVERT(')?Buffer.from(t.slice(10,-16),'hex').toString('utf8'):t);(rows[m[1]]??=[]).push(Object.fromEntries(names.map((n,i)=>[n,values[i]])));
 }
}
const id=n=>'00000000-0000-4000-8000-'+String(n).padStart(12,'0'),time='2026-11-18 00:00:02.123456',sha=v=>crypto.createHash('sha256').update(v).digest('hex'),add=(t,r)=>(rows['jm_transfer_'+t]??=[]).push(r);
add('order_payments',{id:id(9101),order_id:id(904),amount:'550000',reference:'FIXTURE-POS-PAY',admin_id:'fixture',created_at:time});
add('payment_methods',{payment_id:id(9101),method:'CASH'});
add('payment_references',{reference:'FIXTURE-POS-PAY',kind:'ORDER_PAYMENT',entity_id:id(904),amount:'550000',created_at:time});
add('payment_references',{reference:'FIXTURE-REPAY',kind:'PARTNER_REPAYMENT',entity_id:id(50),amount:'100000',created_at:time});
add('partner_ledger',{id:id(9102),partner_id:id(50),order_id:id(903),kind:'CHARGE',amount:'550000',reference:'JM0000000903',note:'Historical debt',actor_id:'fixture',request_hash:'a'.repeat(64),due_at:'2026-12-01 00:00:00.123456',created_at:time});
add('partner_ledger',{id:id(9103),partner_id:id(50),order_id:null,kind:'REPAYMENT',amount:'-100000',reference:'FIXTURE-REPAY',note:'Historical repayment',actor_id:'fixture',request_hash:'b'.repeat(64),due_at:null,created_at:time});
add('inventory_configs',{product_id:'p',enabled:'true',version:'1'});
add('inventory_buckets',{product_id:'p',variant_id:'eco',visit_date:'2099-06-02',slot_id:'',capacity:'10',low_stock_threshold:'2'});
for(const n of [901,902,903,904])add('inventory_allocations',{order_id:id(n),product_id:'p',variant_id:'eco',visit_date:'2099-06-02',slot_id:'',quantity:'1',released:n===902?'true':'false',version:'1'});
add('supply_lots',{id:id(300),request_hash:'a'.repeat(64),supplier_id:id(3),product_id:'p',variant_id:'eco',customer_type:'ADULT',unit_cost:'100000',total_quantity:'10',valid_from:'2099-01-01',valid_to:'2099-12-31',priority:'100',enabled:'true',data:'{}',version:'1',created_at:time,archived:'false',unlimited:'false'});
for(const n of [901,902,903,904])add('supply_allocations',{order_id:id(n),lot_id:id(300),quantity:'1',unit_cost:'100000',released:n===902?'true':'false'});
add('supplier_payments',{id:id(9300),supplier_id:id(3),amount:'100000',method:'BANK',reference:'FIXTURE-SUPPLIER-PAY',note:'Historical payment',request_hash:'a'.repeat(64),created_at:time});
add('order_tickets',{id:id(9201),order_id:id(904),code:'FIXTURE-STOCK-CODE',code_hash:sha('FIXTURE-STOCK-CODE'),kind:'SUPPLIER_CODE',covers:'1',note:'',created_at:time});
add('ticket_stock',{id:id(9202),lot_id:id(300),code:'FIXTURE-STOCK-CODE',code_hash:sha('FIXTURE-STOCK-CODE'),status:'ISSUED',version:'1',reason:'',order_id:id(904),ticket_id:id(9201),created_at:time});
add('stock_imports',{id:id(9301),lot_id:id(300),request_hash:'c'.repeat(64),quantity:'1',created_at:time});
const pdf=Buffer.from('%PDF-1.4\nopaque original fixture\n%%EOF\n');fs.writeFileSync(new URL('./fixtures/transfer-original.pdf',import.meta.url),pdf);
add('ticket_files',{id:id(9401),order_id:id(904),ticket_id:id(9201),name:'original.pdf',mime:'application/pdf',bytes:String(pdf.length),hash:sha(pdf),created_at:time,revoked_at:null,revoke_reason:'',file_kind:'ORIGINAL',deleted_at:null,partner_shared:'false',sharing_version:'1'});
const webp=fs.readFileSync(new URL('./fixtures/transfer-media.webp',import.meta.url));add('media',{id:id(9901),filename:'fixture.webp',alt:'Fixture',width:'8',height:'8',bytes:String(webp.length),created_by:'fixture',created_at:time,deleted_at:null});
let sql="-- JinMemo MySQL transfer v1; generated 2026-11-18T00:00:00.000Z\n-- DATA TRANSFER ONLY: jm_transfer_* staging tables, not a runnable PHP application schema.\n-- Import into a separate database. No DROP, overwrite, auth sessions or gateway secrets.\n-- Upload files are not included; copy media and original ticket files separately.\nSET NAMES utf8mb4;\nSET time_zone='+00:00';\n";const counts={};
const types={text:'text',jsonb:'jsonb',json:'json',integer:'integer',bigint:'bigint',bigserial:'bigint',smallint:'smallint',boolean:'boolean',uuid:'uuid',timestamptz:'timestamp with time zone',timestamp:'timestamp without time zone',date:'date',numeric:'numeric',decimal:'decimal',real:'real'};
for(const sourceName of exports.transferTables){const table=exports.transferName(sourceName),columns=profile.tables[table].map(c=>({column_name:c.name,data_type:types[c.sourceType]??(c.sourceType.endsWith('[]')?'ARRAY':null)}));const entries=(rows[table]??[]).map(r=>Object.fromEntries(Object.entries(r).map(([key,v])=>{const c=profile.tables[table].find(c=>c.name===key);return [key,c.sourceType==='boolean'&&v!==null?(v==='1'||v==='true'?'true':'false'):v];})));for(const r of entries)for(const c of columns)if(!Object.hasOwn(r,c.column_name))throw Error('Fixture column absent '+table+'.'+c.column_name);sql+=exports.mysqlCreate(sourceName,columns)+exports.mysqlInsert(sourceName,columns,entries)+`-- ${table}: ${entries.length} rows\n`;counts[sourceName]=entries.length;}
sql+='-- TRANSFER COMPLETE '+JSON.stringify(counts)+'\n';fs.writeFileSync(new URL('./fixtures/full-transfer-node.sql',import.meta.url),sql);console.log('Generated complete linked export: '+Object.keys(counts).length+' tables, '+Object.values(counts).reduce((a,b)=>a+b,0)+' rows.');
