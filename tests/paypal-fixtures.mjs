import {readFileSync,writeFileSync} from 'node:fs';
import {stripTypeScriptTypes} from 'node:module';
if(!process.argv[2])throw Error('Pass the original payments/paypal.ts path.');
const source=readFileSync(process.argv[2],'utf8'),start=source.indexOf('export function paypalCents('),end=source.indexOf('const remoteId=');
if(start<0||end<start)throw Error('Original currency functions not found.');
const js=stripTypeScriptTypes(source.slice(start,end));
const {paypalCents,usdValue}=await import('data:text/javascript;base64,'+Buffer.from('class BadRequestException extends Error {}\n'+js).toString('base64'));
const inputs=[[600000,25000],[600000,26000],[125,25000],[124,25000],[126,25000],[375,25000],[374,25000],[376,25000],[1,100],[1,25000],[0,25000],[-1,25000],[15000000000,100],[15000000000,1000000],[99999999999,100],[100000000000,100],[Number.MAX_SAFE_INTEGER,1000000],[Number.MAX_SAFE_INTEGER,100],[600000,99],[600000,1000001],[600000,25000.5],[600000,0],[600000.5,25000]];
for(let rate of [100,101,25000,26000,1000000])for(let n=1;n<=24;n++){inputs.push([Math.floor((n-.5)*rate/100),rate],[Math.ceil((n-.5)*rate/100),rate]);}
const cases=inputs.map(([vnd,rate])=>{try{const cents=paypalCents(vnd,rate);return {vnd,rate,cents,usd:usdValue(cents)};}catch{return {vnd,rate,cents:null};}});
writeFileSync(new URL('./fixtures/paypal-money-node.json',import.meta.url),JSON.stringify({cases},null,2)+'\n');
