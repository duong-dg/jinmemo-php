'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Element{constructor(tag,text){this.tagName=tag;this.textContent=text??'';this.children=[];this._value='';this.disabled=false;}set value(v){this._value=String(v);}get value(){return this._value;}append(...n){this.children.push(...n);if(this.tagName==='select'&&!this.value)this.value=this.children[0]?.value||'';}replaceChildren(...n){this.children=n;}checkValidity(){return !this.required||this.value!=='';}}
const all=n=>[n,...n.children.flatMap(all)],find=(n,tag,text)=>{const r=all(n).find(c=>c.tagName===tag&&c.textContent===text);assert.ok(r,tag+': '+text);return r;};
const root=new Element('section'),calls=[];let lost=true,status='MATCHED';
const receipt={id:'test',code:'JM-X123456ABCDEF',amount:34,counterName:'Main',actorName:'Cashier',from:'A',to:'B'};
const context=vm.createContext({document:{createElement:tag=>new Element(tag)},URLSearchParams});vm.runInContext(fs.readFileSync(__dirname+'/../public/assets/ride-cash.js','utf8'),context);
const options={counters:[{id:'main',name:'Main'}],printBase:'/api/v1/pos/rides/cash/receipt',money:n=>String(n),send:async(path,body)=>{calls.push({path,body:structuredClone(body)});if(path.endsWith('/status'))return {status,paymentStatus:'UNPAID',total:34,from:'A',to:'B'};if(lost){lost=false;throw Error('Lost response');}return receipt;}};
(async()=>{
 vm.runInContext('renderRideCash(root,options)',Object.assign(context,{root,options}));const form=root.children[0],code=all(form).find(n=>n.tagName==='input');code.value=receipt.code;await form.onsubmit({preventDefault(){}});find(root,'button','Kiểm tra thu tiền mặt').onclick();assert.equal(calls.length,1,'Review does not collect');await find(root,'button','Xác nhận đã nhận tiền mặt').onclick();const first=calls.at(-1);assert.equal(first.path,'rides/cash');assert.deepEqual(first.body,{code:receipt.code,counterId:'main',expectedTotal:34});await find(root,'button','Xác nhận đã nhận tiền mặt').onclick();assert.deepEqual(calls.at(-1).body,first.body,'Retry uses same code/counter/amount');assert.ok(find(root,'a','In biên nhận ↗').href.endsWith('?code='+receipt.code));
 code.oninput();status='WAITING_MATCH';await form.onsubmit({preventDefault(){}});assert.equal(all(root).some(n=>n.textContent==='Kiểm tra thu tiền mặt'),false,'Unfilled groups cannot collect');
 console.log('PASS: cash UI lookup/review, immutable typed amount/counter/code, uncertain-response retry, receipt link and unfilled-group guard.');
})().catch(e=>{console.error(e);process.exitCode=1;});
