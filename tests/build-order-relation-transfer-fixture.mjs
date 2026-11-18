// Uses pinned Node exporter helpers and the original order parent fixture. No app/database startup.
import fs from 'node:fs';import path from 'node:path';import vm from 'node:vm';import crypto from 'node:crypto';import {createRequire} from 'node:module';
const root=process.argv[2];if(!root)throw Error('Provide local Node repository.');
const profile=JSON.parse(fs.readFileSync(new URL('../docs/TRANSFER-EXPORT-SCHEMA.json',import.meta.url))),source=fs.readFileSync(path.join(root,'apps/api/src/admin/mysql-export.ts'),'utf8');
if(crypto.createHash('sha256').update(source).digest('hex')!==profile.exporterSha256)throw Error('Pinned exporter differs.');
const ts=createRequire(path.resolve(root,'package.json'))('typescript'),exports={};vm.runInNewContext(ts.transpileModule(source.slice(0,source.indexOf('@Injectable()')),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText,{exports,Buffer});
const id=n=>'00000000-0000-4000-8000-'+String(n).padStart(12,'0');
const sqlParent=fs.readFileSync(new URL('./fixtures/voucher-use-parents-node.sql',import.meta.url),'utf8'),tokens=sqlParent.match(/^INSERT INTO `jm_transfer_orders` .+ VALUES \((.+)\);\r?$/m)[1].split(',');
const values=tokens.map(t=>t.startsWith('CONVERT(')?Buffer.from(t.slice(10,-16),'hex').toString('utf8'):t),base=Object.fromEntries(profile.tables.jm_transfer_orders.map((c,i)=>[c.name,values[i]]));
const snapshot=JSON.parse(base.snapshot);delete snapshot.discount;snapshot.total=snapshot.lines.reduce((sum,l)=>sum+l.subtotal,0);
const extraOrders=[903,904].map((n,i)=>({...base,id:id(n),code:'JM'+String(n).padStart(10,'0'),request_id:id(n+1000),snapshot:JSON.stringify(snapshot),booking_status:'CONFIRMED',payment_status:i?'PAID':'CREDIT',version:'2'}));
const actions=[
 {id:id(8001),order_id:id(901),action:'ATTACH_FILE',request_hash:'c'.repeat(64),admin_id:'private-admin',note:'Đính kèm file gốc 🐴'},
 {id:id(8002),order_id:id(901),action:'REVOKE_FILE',request_hash:id(987),admin_id:'private-admin',note:'Thu hồi file cũ'},
 {id:id(8003),order_id:id(901),action:'INSTRUCTIONS',request_hash:'d'.repeat(64),admin_id:'private-admin',note:'  Ghi chú lịch sử không trim  '},
 {id:id(8004),order_id:id(902),action:'CANCEL',request_hash:'e'.repeat(64),admin_id:'private-admin',note:'Hủy đơn lịch sử'},
 {id:id(8005),order_id:id(903),action:'CREDIT',request_hash:'f'.repeat(64),admin_id:'partner:'+id(50),note:'Ghi nợ lịch sử'},
 {id:id(8006),order_id:id(904),action:'PAY',request_hash:'1'.repeat(64),admin_id:'gateway:VNPAY',note:'Thu tiền tại quầy'}
].map((r,i)=>({...r,created_at:'2026-11-18 00:00:01.'+String(123456+i).padStart(6,'0')}));
const rows={orders:extraOrders,partner_orders:[{order_id:id(903),partner_id:id(50)}],pos_orders:[{order_id:id(904),counter_id:'main',admin_id:'private-pos-actor'}],order_actions:actions};
let sql='-- Synthetic source channel/action rows - original REVOKE_FILE UUID hash retained.\n';
for(const table of Object.keys(rows)){
 const columns=profile.tables['jm_transfer_'+table].map(c=>({column_name:c.name,data_type:{text:'text',jsonb:'jsonb',integer:'integer',timestamptz:'timestamp with time zone'}[c.sourceType]}));
 if(table==='order_actions')sql+=exports.mysqlCreate('jinmemo_'+table,columns);
 sql+=exports.mysqlInsert('jinmemo_'+table,columns,rows[table]);
}
fs.writeFileSync(new URL('./fixtures/order-relation-transfer-node.sql',import.meta.url),sql);console.log('Generated original Node channel/action fixture, including UUID REVOKE_FILE request hash.');
