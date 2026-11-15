'use strict';
// Exercises client state and request payloads with a small DOM; no layout claims.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{randomUUID}=require('node:crypto');
class Element{
 constructor(tag){this.tagName=tag;this.children=[];this.textContent='';this._value='';this.dataset={};}
 set value(v){this._value=String(v);}get value(){return this._value;}
 append(...nodes){this.children.push(...nodes);if(this.tagName==='select'&&!this.value)this.value=this.children[0]?.value||'';}
 prepend(...nodes){this.children.unshift(...nodes);}
 replaceChildren(...nodes){this.children=nodes;}
}
const all=n=>[n,...n.children.flatMap(c=>all(c))];
function find(node,tag,text){const n=all(node).find(c=>c.tagName===tag&&c.textContent===text);assert.ok(n,tag+': '+text);return n;}
function input(node,label){return find(node,'label',label).children[0];}
const settle=()=>new Promise(resolve=>setImmediate(resolve));
function ui(fetch,storage){const ids=Object.fromEntries(['ride-booking','ride-message','ride-pending','ride-search','ride-offers','ride-checkout','ride-status'].map(id=>[id,new Element('div')]));ids['ride-booking'].dataset.lang='vi';const context=vm.createContext({document:{querySelector:s=>ids[s.slice(1)],createElement:tag=>new Element(tag)},fetch,crypto:{randomUUID},Intl,sessionStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)}});vm.runInContext(fs.readFileSync(__dirname+'/../public/assets/rides.js','utf8'),context);return ids;}
const storage=new Map(),calls=[];let createAttempts=0,serverBooking=null,slowQuote=null;
const quote={vehicleId:'v1',name:'Car',seats:4,total:115,roundTrip:false,note:'Carry one bag',image:null,durationMinutes:45};
async function fetch(url,options){const body=options.body?JSON.parse(options.body):null;calls.push({url,body});let data;if(!body)data={data:{enabled:true,qrEnabled:false,areas:[{id:'a',name:'A'},{id:'b',name:'B'}]}};else if(url.endsWith('/quotes')){if(slowQuote)await slowQuote;data={data:[quote]};}else if(url.endsWith('/status'))data={status:'PENDING',total:115,paymentStatus:'UNPAID'};else{createAttempts++;if(!serverBooking){serverBooking=body;throw Error('Connection lost after commit');}assert.deepEqual(body,serverBooking);data={code:'JM-X123456ABCDEF'};}return {ok:true,status:200,json:async()=>data};}
const event={preventDefault(){}};
(async()=>{
 let page=ui(fetch,storage);await settle();const search=page['ride-search'].children[0];for(const [label,value]of [['Ngày đi','2099-07-01'],['Giờ đi (Việt Nam)','10:00'],['Số khách','2']])input(search,label).value=value;await search.onsubmit(event);assert.equal(calls.at(-1).body.mode,'PRIVATE');assert.equal(calls.at(-1).body.passengers,2);
 find(page['ride-offers'],'button','Chọn xe').onclick();const form=page['ride-checkout'].children[0];for(const [label,value]of [['Họ tên','Guest A'],['Điện thoại','+84 123456789'],['Email','a@example.test'],['Địa chỉ đón','Pickup address'],['Địa chỉ trả','Dropoff address']])input(form,label).value=value;
 form.onsubmit(event);assert.equal(createAttempts,0,'Review does not create a booking');await find(form,'button','Xác nhận gửi yêu cầu').onclick();assert.equal(createAttempts,1);const pending=JSON.parse(storage.get('jinmemo-private-ride-pending'));assert.equal(pending.expectedTotal,115);assert.equal(pending.payment,'PAY_LATER');assert.equal(pending.contact.email,'a@example.test');assert.equal(pending.passengers,2);assert.match(pending.requestKey,/^[a-f0-9-]{36}$/);assert.ok(find(page['ride-pending'],'button','Gửi lại cùng yêu cầu'));
 await search.onsubmit(event);assert.equal(createAttempts,1,'Pending request blocks starting another booking');
 // Reload after the response was lost: retry exactly the persisted request.
 page=ui(fetch,storage);await settle();await find(page['ride-pending'],'button','Thử lại yêu cầu đang chờ').onclick();assert.equal(createAttempts,2);assert.equal(storage.has('jinmemo-private-ride-pending'),false);const proof=JSON.parse(storage.get('jinmemo-private-ride-lookup'));assert.deepEqual(proof,{code:'JM-X123456ABCDEF',requestKey:serverBooking.requestKey});assert.ok(!JSON.stringify(proof).includes('a@example.test'),'Only lookup credentials remain after success');
 const lookup=page['ride-status'].children.find(n=>n.tagName==='form');assert.equal(input(lookup,'Mã đơn xe').value,proof.code);assert.equal(input(lookup,'Khóa tra cứu riêng').value,proof.requestKey);await lookup.onsubmit(event);assert.deepEqual(calls.at(-1).body,proof);
 // Changing the search while its response is in flight must discard that response.
 const current=page['ride-search'].children[0];input(current,'Ngày đi').value='2099-07-01';input(current,'Giờ đi (Việt Nam)').value='10:00';let resolve;slowQuote=new Promise(r=>resolve=r);const request=current.onsubmit(event);current.oninput();resolve();await request;assert.equal(page['ride-offers'].children.length,0,'Stale quote discarded');
 console.log('PASS: private ride UI review/payload, uncertain delivery, reload and exact-key retry, pending guard, private lookup persistence and stale quote suppression.');
})().catch(e=>{console.error(e);process.exitCode=1;});
