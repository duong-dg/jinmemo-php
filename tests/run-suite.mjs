// Runs standalone regression tests against a disposable local MySQL server. Never uses app .env credentials.
import fs from 'node:fs';import path from 'node:path';import {fileURLToPath} from 'node:url';import {spawn,execFileSync} from 'node:child_process';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');let php='php',scope='all',report=null,rerun=null;
for(const arg of process.argv.slice(2)){if(arg.startsWith('--php='))php=arg.slice(6);else if(arg.startsWith('--scope='))scope=arg.slice(8);else if(arg.startsWith('--report='))report=path.resolve(arg.slice(9));else if(arg.startsWith('--rerun-failed='))rerun=path.resolve(arg.slice(15));else throw Error('Use --php=PHP_BINARY --scope=all|transfer --report=OUTPUT.json [--rerun-failed=PREVIOUS.json]');}
if(!['all','transfer'].includes(scope))throw Error('Unknown scope.');
if(!/^mysql:host=(?:127\.0\.0\.1|localhost);port=\d+(?:;charset=utf8mb4)?$/.test(process.env.JINGO_TEST_DSN??''))throw Error('Set JINGO_TEST_DSN to a disposable local MySQL server without dbname.');
const gdLoaded=execFileSync(php,['-r','echo extension_loaded("gd") ? "1" : "0";'],{encoding:'utf8',windowsHide:true,cwd:root}).trim()==='1';
const previous=rerun?JSON.parse(fs.readFileSync(rerun,'utf8')):null;
const failed=new Set(previous?.tests.filter(t=>['FAILED','TIMEOUT'].includes(t.status)).map(t=>t.file));
const files=fs.readdirSync(path.join(root,'tests')).filter(f=>(f.endsWith('.php')&&!/(?:-worker|-scenarios)\.php$/.test(f)&&!['smtp-fixture.php','paypal-remote.php'].includes(f))||f.endsWith('.cjs')).filter(f=>(scope==='all'||f.includes('transfer'))&&(!previous||failed.has(f))).sort();
const results=[];let next=0;
async function run(file){
 const start=Date.now();let output='',timedOut=false;
 const child=spawn(file.endsWith('.php')?php:process.execPath,[...(file==='http.php'&&!gdLoaded?['-d','extension=gd']:[]),'tests/'+file],{cwd:root,env:process.env,windowsHide:true,stdio:['ignore','pipe','pipe']});
 const append=b=>{output=(output+b.toString()).slice(-24000);};child.stdout.on('data',append);child.stderr.on('data',append);
 const timer=setTimeout(()=>{timedOut=true;child.kill();},240000);
 const code=await new Promise(resolve=>{child.once('error',e=>{append(String(e));resolve(-1);});child.once('close',code=>resolve(code));});clearTimeout(timer);
 const status=timedOut?'TIMEOUT':code!==0?'FAILED':/\bSKIP(?:PED)?\b/.test(output)?'SKIPPED':'PASSED';const result={file,status,exitCode:code,seconds:Math.round((Date.now()-start)/10)/100,output};results.push(result);console.log(`${status} ${file} (${result.seconds}s)`);
}
await Promise.all(Array.from({length:2},async()=>{while(next<files.length){const file=files[next++];await run(file);}}));
const merged=new Map(previous?.tests.map(t=>[t.file,t])??[]);for(const r of results){const old=merged.get(r.file);merged.set(r.file,old?{...r,previousAttempts:[...(old.previousAttempts??[]),{status:old.status,exitCode:old.exitCode,seconds:old.seconds}]}:r);}
const tests=[...merged.values()].sort((a,b)=>a.file.localeCompare(b.file)),counts={};for(const r of tests)counts[r.status]=(counts[r.status]??0)+1;const payload={scope,generatedAt:new Date().toISOString(),rerunCount:previous?results.length:0,counts,tests};if(report)fs.writeFileSync(report,JSON.stringify(payload,null,2)+'\n');console.log(JSON.stringify(counts));process.exitCode=tests.some(r=>['FAILED','TIMEOUT'].includes(r.status))?1:0;
