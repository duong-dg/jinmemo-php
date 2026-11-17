// Reproduce the declared constraints profile from the pinned, local PostgreSQL schema.
// Usage: node tests/build-transfer-relations.mjs PATH_TO_NODE_REPOSITORY [--check]
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
const root=process.argv[2];
if (!root) throw Error('Provide local Node repository path.');
const profile=JSON.parse(fs.readFileSync(new URL('../docs/TRANSFER-EXPORT-SCHEMA.json',import.meta.url)));
const schema=fs.readFileSync(path.join(root,'apps/api/src/database/schema.sql'),'utf8');
if (crypto.createHash('sha256').update(schema).digest('hex')!==profile.schemaSha256) throw Error('Pinned schema hash differs.');
function definitions(table) {
  const m=new RegExp('CREATE TABLE IF NOT EXISTS '+table+'\\s*\\(','i').exec(schema);
  if (!m) throw Error('Missing source table '+table);
  let start=m.index+m[0].length, depth=0, quote=false, parts=[];
  for(let i=start;i<schema.length;i++) {
    const c=schema[i];
    if(c==="'") {if(quote&&schema[i+1]==="'"){i++;continue;} quote=!quote;}
    if(quote)continue;
    if(c==='(')depth++;
    if(c===')'){if(depth===0){parts.push(schema.slice(start,i).trim());return parts;}depth--;}
    if(c===','&&depth===0){parts.push(schema.slice(start,i).trim());start=i+1;}
  }
  throw Error('Unclosed source definition');
}
const constraints={};
for(const table of Object.keys(profile.tables)) {
  const source='jinmemo_'+table.slice(12), allowed=new Set(profile.tables[table].map(c=>c.name));
  const c={primary:[],unique:[],foreign:[]};
  for(const d of definitions(source)) {
    const compound=/^(PRIMARY KEY|UNIQUE)\s*\(([^)]+)\)$/i.exec(d);
    if(compound){const cols=compound[2].split(',').map(v=>v.trim());if(compound[1]==='PRIMARY KEY')c.primary=cols;else c.unique.push(cols);continue;}
    if(/^(CHECK|CONSTRAINT|FOREIGN)\b/i.test(d)) throw Error('Unparsed constraint: '+d);
    const name=/^(\w+)\s/.exec(d)?.[1];if(!name)throw Error('Unparsed column');
    if(!allowed.has(name))continue; // Export deliberately omits secret/password_hash.
    if(/\bPRIMARY KEY\b/i.test(d))c.primary=[name];
    if(/\bUNIQUE\b/i.test(d))c.unique.push([name]);
    const fk=/\bREFERENCES\s+(jinmemo_\w+)\s*\((\w+)\)/i.exec(d);
    if(fk)c.foreign.push({columns:[name],targetTable:'jm_transfer_'+fk[1].slice(8),targetColumns:[fk[2]]});
  }
  for(const cols of [c.primary,...c.unique,...c.foreign.map(f=>f.columns)])for(const col of cols)if(!allowed.has(col))throw Error('Constraint uses omitted column');
  constraints[table]=c;
}
for(const [table,c] of Object.entries(constraints))for(const fk of c.foreign) {
  const target=constraints[fk.targetTable];
  if(target && ![target.primary,...target.unique].some(cols=>JSON.stringify(cols)===JSON.stringify(fk.targetColumns)))throw Error('Foreign target has no indexed declared key: '+table);
}
// Fail closed if this pinned source introduces constraints outside the supported CREATE dialect.
if(/CREATE\s+UNIQUE\s+INDEX|ADD\s+CONSTRAINT|ADD\s+PRIMARY\s+KEY|ADD\s+UNIQUE/i.test(schema))throw Error('Review constraints outside CREATE TABLE.');
const output=JSON.stringify({sourceCommit:profile.sourceCommit,schemaSha256:profile.schemaSha256,tables:constraints},null,2)+'\n';
const target=new URL('../docs/TRANSFER-RELATIONS.json',import.meta.url);
if(process.argv.includes('--check')){if(fs.readFileSync(target,'utf8').replaceAll('\r\n','\n')!==output)throw Error('Constraint profile differs.');}
else fs.writeFileSync(target,output);
console.log(JSON.stringify({tables:Object.keys(constraints).length,primary:Object.values(constraints).filter(c=>c.primary.length).length,unique:Object.values(constraints).reduce((n,c)=>n+c.unique.length,0),foreign:Object.values(constraints).reduce((n,c)=>n+c.foreign.length,0)}));
