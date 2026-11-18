// Pure helpers from the pinned Node exporter and creditSummary, no database/app startup.
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import crypto from 'node:crypto';
import {createRequire} from 'node:module';
const root=process.argv[2];
if(!root)throw Error('Provide local Node repository.');
const profile=JSON.parse(fs.readFileSync(new URL('../docs/TRANSFER-EXPORT-SCHEMA.json',import.meta.url)));
const source=fs.readFileSync(path.join(root,'apps/api/src/admin/mysql-export.ts'),'utf8');
for(const [content,hash] of [[source,profile.exporterSha256],[fs.readFileSync(path.join(root,'apps/api/src/database/schema.sql')),profile.schemaSha256]])
  if(crypto.createHash('sha256').update(content).digest('hex')!==hash)throw Error('Pinned source differs.');
const ts=createRequire(path.resolve(root,'package.json'))('typescript');
const compile=s=>ts.transpileModule(s,{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText;
const exports={};vm.runInNewContext(compile(source.slice(0,source.indexOf('@Injectable()'))),{exports,Buffer});
const creditSource=fs.readFileSync(path.join(root,'apps/api/src/partners/credit.ts'),'utf8');
if(crypto.createHash('sha256').update(creditSource).digest('hex')!=='55d0a3735232dcbe97a19cee4c57200738cff2ba3a0702a0fac53a63fc8a6158')throw Error('Pinned credit source differs.');
const credit={};
const asOf='2026-11-18T00:00:00.000000Z';
class FixedDate extends Date { static now(){return Date.parse(asOf);} }
vm.runInNewContext(compile(creditSource.replace(/^import.*;\r?\n/gm,'')),{exports:credit,Date:FixedDate});
const id=n=>'00000000-0000-4000-8000-'+String(n).padStart(12,'0');
const time='2026-01-01 00:00:00.000001',old='2026-01-02 00:00:00.000000',future='2099-01-01 00:00:00.000000';
const partners=[1,2,3,4].map(n=>({id:id(n),email:`private${n}@example.test`,data:JSON.stringify({id:id(n),creditLimit:10000}),version:'1'}));
const specs=[[1,11,1000,old],[1,12,2000,future],[2,13,500,future],[2,14,200,old],[3,15,0,old]];
const orders=specs.map(([p,n,total])=>({id:id(n),code:'PRIVATE-ORDER-'+n,snapshot:JSON.stringify({total}),booking_status:'CONFIRMED',payment_status:'CREDIT'}));
const owners=specs.map(([p,n])=>({order_id:id(n),partner_id:id(p)}));
const ledger=specs.map(([p,n,amount,due])=>({id:id(n+100),partner_id:id(p),order_id:id(n),kind:'CHARGE',amount:String(amount),reference:'PRIVATE-ORDER-'+n,due_at:due,created_at:time}));
for(const [p,n,amount] of [[1,201,1500],[2,202,100]])ledger.push({id:id(n),partner_id:id(p),order_id:null,kind:'REPAYMENT',amount:String(-amount),reference:'PRIVATE-REPAYMENT-'+n,due_at:null,created_at:'2026-02-01 00:00:00.000000'});
const refs=ledger.filter(e=>e.kind==='REPAYMENT').map(e=>({reference:e.reference,kind:'PARTNER_REPAYMENT',entity_id:e.partner_id,amount:String(-Number(e.amount)),created_at:e.created_at}));
const rows={jinmemo_partners:partners,jinmemo_orders:orders,jinmemo_partner_orders:owners,jinmemo_partner_ledger:ledger,jinmemo_payment_references:refs};
const pgTypes={text:'text',jsonb:'jsonb',json:'json',integer:'integer',bigint:'bigint',bigserial:'bigint',smallint:'smallint',boolean:'boolean',uuid:'uuid',timestamptz:'timestamp with time zone',timestamp:'timestamp without time zone',date:'date',numeric:'numeric',decimal:'decimal',real:'real'};
let sql="-- JinMemo MySQL transfer v1; generated 2026-11-18T00:00:00.000Z\n-- DATA TRANSFER ONLY: jm_transfer_* staging tables, not a runnable PHP application schema.\n-- Import into a separate database. No DROP, overwrite, auth sessions or gateway secrets.\n-- Upload files are not included; copy media and original ticket files separately.\nSET NAMES utf8mb4;\nSET time_zone='+00:00';\n";
const counts={};
for(const name of exports.transferTables){
  const table=exports.transferName(name),fields=profile.tables[table];
  const columns=fields.map(c=>({column_name:c.name,data_type:pgTypes[c.sourceType] ?? (c.sourceType.endsWith('[]')?'ARRAY':null)}));
  const records=(rows[name] ?? []).map(r=>Object.fromEntries(fields.map(c=>[c.name,Object.hasOwn(r,c.name)?r[c.name]:c.nullable?null:({LONGTEXT:'private-value',JSON:'{}',BIGINT:'1','TINYINT(1)':'true','CHAR(36)':id(999),DATE:'2026-01-01','DATETIME(6)':time,'DECIMAL(65,20)':'1',DOUBLE:'1'})[c.type]])));
  sql+=exports.mysqlCreate(name,columns)+exports.mysqlInsert(name,columns,records)+`-- ${table}: ${records.length} rows\n`;counts[name]=records.length;
}
sql+='-- TRANSFER COMPLETE '+JSON.stringify(counts)+'\n';
const hash=v=>crypto.createHash('sha256').update(v).digest('hex');
const summaries=partners.map(p=>{
  const entries=ledger.filter(e=>e.partner_id===p.id).sort((a,b)=>a.created_at.localeCompare(b.created_at)||a.id.localeCompare(b.id));
  const summary=credit.creditSummary(entries.map(e=>({...e,due_at:e.due_at===null?null:e.due_at.replace(' ','T')+'Z'})),10000);
  return [hash(p.id),summary.balance,summary.overdue,summary.charges.map(c=>[hash(c.orderId),c.amount,c.outstanding,c.dueAt.replace('T',' ').replace(/Z$/,'')])];
}).sort((a,b)=>a[0].localeCompare(b[0]));
fs.writeFileSync(new URL('./fixtures/partner-credit-transfer-node.sql',import.meta.url),sql);
fs.writeFileSync(new URL('./fixtures/partner-credit-transfer-summary.json',import.meta.url),JSON.stringify({asOf,sourceCommit:profile.sourceCommit,creditSourceSha256:hash(creditSource),sourceSummarySha256:hash(JSON.stringify(summaries)),partnersChecked:4,chargesChecked:5,repaymentsChecked:2,partnersWithDebt:2,partnersOverdue:1},null,2)+'\n');
console.log('Generated 61-table source fixture and FIFO digest using original Node creditSummary.');
