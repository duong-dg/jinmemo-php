// Usage: node tests/build-transfer-audit-fixture.mjs PATH_TO_NODE_REPOSITORY
// Runs pure mysqlCreate/mysqlInsert helpers from the pinned, local Node exporter.
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import crypto from 'node:crypto';
import {createRequire} from 'node:module';
const root = process.argv[2];
if (!root) throw Error('Provide local Node repository path.');
const profile = JSON.parse(fs.readFileSync(new URL('../docs/TRANSFER-EXPORT-SCHEMA.json',import.meta.url)));
const source = fs.readFileSync(path.join(root,'apps/api/src/admin/mysql-export.ts'),'utf8');
for (const [content, hash] of [[source,profile.exporterSha256],[fs.readFileSync(path.join(root,'apps/api/src/database/schema.sql')),profile.schemaSha256]]) {
  if (crypto.createHash('sha256').update(content).digest('hex') !== hash) throw Error('Pinned source hash differs.');
}
const ts = createRequire(path.resolve(root,'package.json'))('typescript');
const js = ts.transpileModule(source.slice(0,source.indexOf('@Injectable()')), {compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText;
const exports = {};
vm.runInNewContext(js, {exports, Buffer});
if (exports.transferTables.length !== Object.keys(profile.tables).length) throw Error('Whitelist differs.');
let sql = '-- JinMemo MySQL transfer v1; generated 2026-11-17T00:00:00.000Z\n-- DATA TRANSFER ONLY: jm_transfer_* staging tables, not a runnable PHP application schema.\n-- Import into a separate database. No DROP, overwrite, auth sessions or gateway secrets.\n-- Upload files are not included; copy media and original ticket files separately.\nSET NAMES utf8mb4;\nSET time_zone=\'+00:00\';\n';
const counts = {};
const pgTypes = {text:'text',jsonb:'jsonb',json:'json',integer:'integer',bigint:'bigint',bigserial:'bigint',smallint:'smallint',boolean:'boolean',uuid:'uuid',timestamptz:'timestamp with time zone',timestamp:'timestamp without time zone',date:'date',numeric:'numeric',decimal:'decimal',real:'real'};
for (const sourceName of exports.transferTables) {
  const table = exports.transferName(sourceName), fields=profile.tables[table];
  const columns=fields.map(c=>({column_name:c.name,data_type:pgTypes[c.sourceType] ?? (c.sourceType.endsWith('[]')?'ARRAY':null)}));
  const row={};
  fields.forEach(c=>{row[c.name]=({'LONGTEXT':'Khách riêng tư — private@example.test 🐴','JSON':'{"label":"dữ liệu riêng","amount":9007199254740993}','BIGINT':c.sourceType==='integer'?'12':'9007199254740993','TINYINT(1)':'true','CHAR(36)':'00000000-0000-4000-8000-000000000001','DATE':'2026-11-17','DATETIME(6)':'2026-11-17 01:02:03.123456','DECIMAL(65,20)':'12.12345678901234567890','DOUBLE':'1.25'})[c.type];});
  sql += exports.mysqlCreate(sourceName,columns)+exports.mysqlInsert(sourceName,columns,[row])+`-- ${table}: 1 rows\n`;
  counts[sourceName]=1;
}
sql += '-- TRANSFER COMPLETE '+JSON.stringify(counts)+'\n';
fs.writeFileSync(new URL('./fixtures/transfer-audit-node.sql',import.meta.url),sql);
console.log('Generated synthetic fixture using pinned Node exporter: '+Object.keys(counts).length+' tables.');
