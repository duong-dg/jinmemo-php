// Extract declarations only; never load/run the Node application or access its database.
// node tests/build-source-api-inventory.mjs PATH_TO_NODE_REPOSITORY [--check]
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
const root=path.resolve(process.argv[2]||'');
if(!process.argv[2])throw Error('Provide local Node repository path.');
const ts=createRequire(path.join(root,'package.json'))('typescript'),base=path.join(root,'apps/api/src');
const routes=[],controllers=[],files={};
function decorators(node,source){return (ts.getDecorators(node)||[]).filter(d=>ts.isCallExpression(d.expression)).map(d=>{
 const call=d.expression,name=call.expression.getText(source),arg=call.arguments[0];
 if(['Controller','Get','Post','Put','Patch','Delete','Options','Head','All'].includes(name)&&arg&&!ts.isStringLiteral(arg))throw Error('Review dynamic route declaration in '+source.fileName);
 return {name,value:arg?.text||''};
});}
function walk(dir){for(const name of fs.readdirSync(dir).sort()){
 const file=path.join(dir,name);if(fs.statSync(file).isDirectory()){walk(file);continue;}
 if(!name.endsWith('.ts')||name.endsWith('.test.ts')||name.endsWith('.spec.ts'))continue;
 const text=fs.readFileSync(file,'utf8'),source=ts.createSourceFile(file,text,ts.ScriptTarget.Latest,true),relative=path.relative(base,file).replaceAll('\\','/');
 function visit(node){if(ts.isClassDeclaration(node)){
  const controller=decorators(node,source).find(d=>d.name==='Controller');
  if(controller){controllers.push({name:node.name?.text||'(anonymous)',prefix:controller.value,file:relative});files[relative]=crypto.createHash('sha256').update(text).digest('hex');
   for(const member of node.members)for(const d of decorators(member,source))if(['Get','Post','Put','Patch','Delete','Options','Head','All'].includes(d.name))routes.push({method:d.name.toUpperCase(),path:'/api/v1/'+[controller.value,d.value].filter(Boolean).join('/'),file:relative,line:source.getLineAndCharacterOfPosition(member.getStart(source)).line+1});
  }
 }ts.forEachChild(node,visit);}visit(source);
}}
walk(base);
const sourceCommit=execFileSync('git',['-c','safe.directory='+root.replaceAll('\\','/'),'-C',root,'rev-parse','HEAD'],{encoding:'utf8'}).trim();
const result={scope:'SOURCE_DECLARATIONS_ONLY',sourceCommit,phpParityVerified:false,controllerCount:controllers.length,routeCount:routes.length,files,controllers,routes};
const text=JSON.stringify(result,null,2)+'\n',output=new URL('../docs/SOURCE-API-INVENTORY.json',import.meta.url);
if(process.argv.includes('--check')){if(fs.readFileSync(output,'utf8').replaceAll('\r\n','\n')!==text)throw Error('Source declaration inventory differs.');}else fs.writeFileSync(output,text);
console.log(JSON.stringify({controllers:controllers.length,routes:routes.length,sourceCommit,phpParityVerified:false}));
