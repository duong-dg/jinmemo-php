'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Element {
 constructor(tag){this.tagName=tag;this.children=[];this.value='';this.disabled=false;this.hidden=false;this.textContent='';this.maxLength=-1;}
 append(...nodes){for(const n of nodes){n.parentElement=this;this.children.push(n);}}
 replaceChildren(...nodes){this.children=[];this.append(...nodes);if(this.tagName==='select')this.value='';}
 after(n){const p=this.parentElement,i=p.children.indexOf(this);n.parentElement=p;p.children.splice(i+1,0,n);}
 get selectedOptions(){return this.children.filter(c=>c.value===this.value);}
 dispatchEvent(e){this.event=e;}
 matches(){return this.disabled;}
}
const all=n=>[n,...n.children.flatMap(all)],find=(n,tag,text)=>{const e=all(n).find(e=>e.tagName===tag&&e.textContent===text);assert.ok(e,tag+' '+text);return e;},select=(n,title)=>find(n,'label',title).children[0],tick=()=>new Promise(r=>setImmediate(r));
const pending=[],calls=[],apiCalls=[];
const context=vm.createContext({window:{},document:{createElement:t=>new Element(t)},fetch:(url,options)=>{calls.push({url,options});return new Promise((resolve,reject)=>pending.push({resolve,reject}));},Event:class{constructor(type){this.type=type;}},structuredClone,URLSearchParams,Set});
vm.runInContext(fs.readFileSync(__dirname+'/../public/assets/area-source.js','utf8'),context);
const respond=data=>pending.shift().resolve({ok:true,json:async()=>({data})});
(async()=>{
 const form=new Element('fieldset'),label=new Element('label'),address=new Element('input');address.value='12 Đường thử';address.maxLength=500;label.append(address);form.append(label);
 const box=context.window.jinmemoGiftAddressPicker({address}),p=select(box,'Tỉnh/thành'),w=select(box,'Phường/xã');
 assert.equal(calls.length,0,'No upstream lookup before opening picker');box.open=true;box.ontoggle();respond([{code:1,name:'Tỉnh A'},{code:2,name:'Tỉnh B'}]);await tick();
 assert.equal(label.children.length,1,'Picker must not nest labels');assert.equal(form.children[1],box);
 p.value='1';const first=p.onchange();p.value='2';const second=p.onchange();pending[1].resolve({ok:true,json:async()=>({data:[{code:20,name:'Xã B'}]})});pending.splice(1,1);await second;
 pending.shift().resolve({ok:true,json:async()=>({data:[{code:10,name:'Xã A'}]})});await first;assert.equal(w.children[1].textContent,'Xã B','Stale province response overwrote wards');
 w.value='20';w.onchange();const apply=find(box,'button','Thêm khu vực vào địa chỉ');apply.onclick();assert.equal(address.value,'12 Đường thử, Xã B, Tỉnh B');apply.onclick();assert.equal(address.value,'12 Đường thử, Xã B, Tỉnh B','Repeated apply duplicated suffix');assert.equal(address.event.type,'input');
 address.disabled=true;address.value='Keep';apply.onclick();assert.equal(address.value,'Keep','Disabled address edited');address.disabled=false;address.value='x'.repeat(500);apply.onclick();assert.equal(address.value.length,500,'Address limit ignored');
 const enLabel=new Element('label'),enAddress=new Element('input');enLabel.append(enAddress);form.append(enLabel);const english=context.window.jinmemoGiftAddressPicker({address:enAddress,en:true});english.open=true;english.ontoggle();pending.shift().reject(Error('offline'));await tick();assert.ok(all(english).some(n=>n.textContent==='offline'));assert.ok(all(english).some(n=>n.textContent==='Choose a province and ward'));
 // Admin merge: existing edited area and surcharge are retained, no POST before confirmation.
 const parent=new Element('div'),existing={id:'vn-v2-p-1',name:'Tên chỉnh tay',surcharges:{car:120}},plan={enabled:false,areas:[existing],vehicles:[],fares:[],trips:[],requests:[]};let saved=0,alive=true;
 const api=async(path,body)=>{apiCalls.push({path,body});if(body)return {};if(path==='ride-areas?version=v2')return {data:[{code:1,name:'Tỉnh A'}]};return {data:[{id:'vn-v2-p-1',name:'Tên nguồn',surcharges:{}},{id:'vn-v2-w-10',name:'Xã A',parentId:'vn-v2-p-1',surcharges:{}}]};};
 const admin=context.window.jinmemoRideAreaImport({parent,plan,version:7,api,isAlive:()=>alive,onSaved:async()=>{saved++;}});await tick();
 const province=select(admin,'Tỉnh/thành');province.value='1';await province.onchange();await find(admin,'button','Xem trước khu vực sẽ thêm').onclick();assert.equal(apiCalls.filter(c=>c.body).length,0,'Preview saved config');
 await find(admin,'button','Xác nhận thêm khu vực').onclick();assert.equal(saved,1);const payload=apiCalls.find(c=>c.body).body;assert.equal(payload.version,7);assert.equal(payload.rows[0].name,'Tên chỉnh tay');assert.equal(payload.rows[0].surcharges.car,120);assert.equal(payload.rows.length,2);assert.equal(plan.areas.length,1,'Live draft mutated before save');
 alive=false;await find(admin,'button','Xác nhận thêm khu vực').onclick();assert.equal(apiCalls.filter(c=>c.body).length,1,'Old admin screen saved data');
 console.log('PASS: area picker lazy load, stale responses, VI/EN, manual address/limits, admin preview/explicit save/version, preservation and screen guards.');
})().catch(e=>{console.error(e);process.exit(1);});
