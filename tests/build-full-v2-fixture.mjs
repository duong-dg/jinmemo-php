// Synthetic business graph, serialized by the pinned Node full-v2 helpers.
import fs from 'node:fs';import path from 'node:path';import vm from 'node:vm';import crypto from 'node:crypto';import {createRequire} from 'node:module';
const root=process.argv[2];if(!root)throw Error('Provide local Node repository.');
const profile=JSON.parse(fs.readFileSync(new URL('../docs/TRANSFER-V2-SCHEMA.json',import.meta.url))),hash=s=>crypto.createHash('sha256').update(s).digest('hex');
const ts=createRequire(path.resolve(root,'package.json'))('typescript'),compile=s=>ts.transpileModule(s.slice(0,s.indexOf('@Injectable()')),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText;
const v1={},v2={},source=fs.readFileSync(path.join(root,'apps/api/src/admin/mysql-transfer-full.ts'),'utf8');if(hash(source)!==profile.exporterSha256)throw Error('Exporter differs.');
vm.runInNewContext(compile(fs.readFileSync(path.join(root,'apps/api/src/admin/mysql-export.ts'),'utf8')),{exports:v1,Buffer});
vm.runInNewContext(compile(source),{exports:v2,Buffer,require:n=>{if(n==='./mysql-export')return v1;if(n==='node:crypto')return crypto;throw Error(n);}});
const rows={};for(const m of fs.readFileSync(new URL('./fixtures/full-transfer-node.sql',import.meta.url),'utf8').matchAll(/^INSERT INTO `(jm_transfer_[a-z_]+)` \(([^\n]+)\) VALUES \(([^\n]+)\);\r?$/gm)){
 const names=m[2].split(',').map(n=>n.slice(1,-1)),values=m[3].split(',').map(t=>t==='NULL'?null:t.startsWith('CONVERT(')?Buffer.from(t.slice(10,-16),'hex').toString('utf8'):t);(rows[m[1]]??=[]).push(Object.fromEntries(names.map((n,i)=>[n,values[i]])));
}
rows.jm_transfer_settings=v2.businessSettingIds.map(id=>({id,data:'{"fixture":true,"amount":9007199254740993,"nested":{"apiKey":"omit-me","label":"preserved"}}',version:'1'}));
const time='2026-11-18 00:00:03.123456';
rows.jm_transfer_admins=[{id:'fixture',email:'fixture-admin@example.test'}];
rows.jm_transfer_pos_users=[{id:'fixture-pos',email:'fixture-pos@example.test',data:'{"name":"Fixture","passwordHash":"omit-me"}',version:'1'}];
rows.jm_transfer_audit=[{id:'fixture-audit',admin_id:'fixture',action:'fixture.archive',entity_id:'fixture',created_at:time}];
rows.jm_transfer_mail_outbox=[{id:'fixture-mail',order_id:'00000000-0000-4000-8000-000000000904',event:'ORDER_CREATED',status:'PENDING',attempts:'0',next_at:time,last_error:'',created_at:time,sent_at:null}];
rows.jm_transfer_admin_notifications=[{id:'9007199254740993',kind:'ORDER',code:'FIXTURE',href:'/admin',created_at:time}];
const columns=t=>profile.tables[t].map(c=>({column_name:c.name,data_type:c.manifestDataType}));
function build(missing=new Set()){
 let sql="-- JinMemo MySQL full transfer v2; generated 2026-11-18T00:00:00.000Z\n-- STAGING ONLY: import into a separate database; mapping required for PHP.\n-- Business settings only. No passwords, login sessions, SMTP or gateway credentials.\n-- Original uploads NOT included. Historical values preserved; do not recalculate orders.\nSET NAMES utf8mb4;\nSET time_zone='+00:00';\n",manifest=[],counts={};
 for(const source of v2.fullTransferTables){const target=v2.fullName(source),cols=columns(target);if(missing.has(target)){manifest.push({source_table:source,target_table:target,status:'MISSING_SOURCE',row_count:'0',columns_json:'[]',insert_sha256:null});continue;}
 const data=(rows[target]??[]).map(row=>Object.fromEntries(cols.map(c=>[c.column_name,c.data_type==='boolean'?(row[c.column_name]==='1'?'true':'false'):(row[c.column_name]??null)]))),inserts=v2.fullInsert(source,cols,data);sql+=v2.fullCreate(source,cols)+inserts;counts[source]=data.length;
 manifest.push({source_table:source,target_table:target,status:source==='jinmemo_settings'?'FILTERED_BUSINESS_SETTINGS':'EXPORTED',row_count:String(data.length),columns_json:JSON.stringify(cols),insert_sha256:hash(inserts)});
 }
 for(const source of v2.excludedTransferTables)manifest.push({source_table:source,target_table:null,status:'EXCLUDED_SECURITY_OR_RUNTIME',row_count:null,columns_json:'[]',insert_sha256:null});
 return sql+v2.fullCreate('manifest',columns('jm_transfer_manifest'))+v2.fullInsert('manifest',columns('jm_transfer_manifest'),manifest)+'-- TRANSFER COMPLETE '+JSON.stringify(counts)+'\n';
}
for(const [file,missing]of [['full-v2-node.sql',new Set()],['full-v2-missing-node.sql',new Set(['jm_transfer_admin_notifications'])]]){
 const text=build(missing),target=new URL('./fixtures/'+file,import.meta.url);if(process.argv.includes('--check')){if(fs.readFileSync(target,'utf8')!==text)throw Error('Fixture differs '+file);}else fs.writeFileSync(target,text);
}
console.log('Node full-v2 fixtures generated; nested credentials removed, numeric tokens preserved.');

rows.jm_transfer_settings=rows.jm_transfer_settings.map(r=>({...r,version:'7',data:r.id==='manual-payments'?JSON.stringify({methods:[{id:'PAY_LATER',name:'Thanh toán sau',englishName:'Pay later',instructions:'Liên hệ xác nhận',englishInstructions:'Contact to confirm',enabled:true}]}):r.id==='gifts'?JSON.stringify({enabled:true,shippingFee:50000,items:[{id:'00000000-0000-4000-8000-000000009999',name:'Quà mẫu',description:'Mô tả',nameEn:'Gift',descriptionEn:'Description',price:100000,stock:7,active:true}]}):r.data}));
{const text=build(),target=new URL('./fixtures/business-settings-v2-node.sql',import.meta.url);if(process.argv.includes('--check')){if(fs.readFileSync(target,'utf8')!==text)throw Error('Business fixture differs');}else fs.writeFileSync(target,text);}
