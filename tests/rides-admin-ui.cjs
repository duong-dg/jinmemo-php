'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{randomUUID}=require('node:crypto');
class Element{
 constructor(tag,text){this.tagName=tag;this.textContent=text??'';this.children=[];this._value='';this.disabled=false;}
 set type(v){if(this.tagName==='textarea')throw Error('Textarea type is read-only');this._type=v;}get type(){return this._type;}
 set value(v){this._value=String(v);}get value(){return this._value;}
 append(...nodes){this.children.push(...nodes);if(this.tagName==='select'&&!this.value)this.value=this.children[0]?.value||'';}
 replaceChildren(...nodes){this.children=nodes;}
 setAttribute(){}
 checkValidity(){return all(this).every(n=>!n.required||n.value!=='');}
}
const all=n=>[n,...n.children.flatMap(c=>all(c))],find=(node,tag,text)=>{const n=all(node).find(c=>c.tagName===tag&&c.textContent===text);assert.ok(n,tag+': '+text);return n;},control=(n,label)=>find(n,'label',label).children[0];
async function click(node,text){const b=find(node,'button',text);assert.equal(b.disabled,false);await b.onclick();}
const poolId='12345678-1234-4234-8234-123456789012',calls=[],pool={id:poolId,status:'OPEN',used:1,target:3,total:101,departure:'10:00',version:1};
const row={id:'12345678-1234-4234-8234-123456789013',code:'JM-X123456ABCDEF',visit_date:'2099-07-01',status:'WAITING_MATCH',version:1,pool,data:{mode:'SHARED',autoPool:true,contact:{name:'Web guest',phone:'123456789',email:'guest@example.test'},fromName:'A',toName:'B',vehicleName:'Car',passengers:1,time:'10:00',pickup:'Pickup',dropoff:'Dropoff',total:34,paymentStatus:'UNPAID',note:''}};
let rows=[row],failManual=true;const screen=new Element('section');
const context=vm.createContext({loading:1,editing:false,renderRideCash:()=>{},crypto:{randomUUID},URLSearchParams,el:(tag,text)=>new Element(tag,text),button:(text,onclick)=>Object.assign(new Element('button',text),{onclick}),toolbar:text=>new Element('div',text),orderMoney:n=>String(n),api:async(path,body)=>{if(path==='counters')return {data:[]};calls.push({path,body:structuredClone(body)});if(!body)return {data:structuredClone(rows),total:rows.length,page:1};if(path.includes('/guests')){if(failManual){failManual=false;throw Error('Response lost');}pool.status='LOCKED';pool.used=3;row.status='MATCHED';row.version++;rows.push({...structuredClone(row),id:'12345678-1234-4234-8234-123456789014',code:'JM-X123456ABCDE0',data:{...row.data,manual:true,passengers:2,total:67,contact:{name:body.name,phone:body.phone,email:''}}});return {code:'JM-X123456ABCDE0'};}for(const r of rows){r.status=body.status;r.version++;Object.assign(r.data,{driver:body.driver,driverPhone:body.driverPhone,plate:body.plate,operatorNote:body.operatorNote});r.pool.status='PICKUP';}return {ok:true};}});
vm.runInContext(fs.readFileSync(__dirname+'/../public/assets/rides-admin.js','utf8'),context);
(async()=>{
 await vm.runInContext('renderRides(screen,1)',Object.assign(context,{screen}));
 const manual=all(screen).find(n=>n.tagName==='form'&&all(n).some(c=>c.textContent==='Kiểm tra thêm khách'));assert.ok(manual);control(manual,'Tên khách').value='Walk in';control(manual,'Số khách').value=2;manual.onsubmit({preventDefault(){}});assert.equal(calls.length,1,'Review has no mutation');await click(manual,'Xác nhận thêm khách');const first=calls.at(-1);assert.equal(first.path,'rides/pools/'+poolId+'/guests');assert.equal(first.body.passengers,2);assert.match(first.body.requestKey,/^[a-f0-9-]{36}$/);await click(manual,'Xác nhận thêm khách');assert.deepEqual(calls.filter(c=>c.path.includes('/guests'))[1].body,first.body,'Retry preserves guest key/payload');
 const cards=all(screen).filter(n=>n.tagName==='details'&&n.children.some(c=>c.tagName==='summary'&&c.textContent.startsWith('JM-X')));assert.equal(cards.length,2);
 const card=cards[0];for(const [label,value]of [['Tài xế','Driver'],['Điện thoại tài xế','123456789'],['Biển số','TEST-123'],['Ghi chú nội bộ','Group note'],['Trạng thái mới','WAITING_PICKUP']])control(card,label).value=value;await click(card,'Kiểm tra cập nhật');assert.ok(all(card).some(n=>n.textContent.includes('TẤT CẢ khách')),'Review explains group-wide changes');await click(card,'Xác nhận cập nhật');const posted=calls.find(c=>c.path==='rides/'+row.id&&c.body);assert.equal(posted.body.version,2);assert.equal(posted.body.tripId,null);assert.equal(posted.body.status,'WAITING_PICKUP');assert.equal(rows[1].status,'WAITING_PICKUP');
 const current=all(screen).find(n=>n.tagName==='details'&&n.children.some(c=>c.tagName==='summary'&&c.textContent.startsWith(row.code)));control(current,'Trạng thái mới').value='CANCELLED';await click(current,'Kiểm tra cập nhật');assert.ok(all(current).some(n=>n.textContent.includes('Chỉ hủy khách này')),'Cancellation review is per guest');
 console.log('PASS: admin shared guest review/typed payload/key retry, group dispatch/version and distinct per-guest cancellation confirmation.');
})().catch(e=>{console.error(e);process.exitCode=1;});
