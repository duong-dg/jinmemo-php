'use strict';
// DOM interaction contract; does not replace a browser layout/accessibility check.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const {randomUUID,createHash}=require('node:crypto');
class Element{
 constructor(tag,text){this.tagName=tag;this.text=text??'';this.children=[];this._value='';this.disabled=false;}
 set value(v){this._value=String(v);}
 get value(){return this._value;}
 append(...nodes){this.children.push(...nodes);}
 prepend(...nodes){this.children.unshift(...nodes);}
 replaceChildren(...nodes){this.children=nodes;}
 checkValidity(){return this.disabled||(!this.required||this.value!=='');}
 showModal(){this.open=true;}
 close(){this.open=false;}
}
function all(node){return [node,...node.children.flatMap(n=>n instanceof Element?all(n):[])];}
function find(node,tag,text){const found=all(node).find(n=>n.tagName===tag&&n.text===text);assert.ok(found,tag+': '+text);return found;}
function control(text){const label=find(fields,'label',text);return label.children[0];}
async function click(node,text){const b=find(node,'button',text);assert.equal(b.disabled,false,text+' disabled');await b.onclick();}
const fixture=JSON.parse(fs.readFileSync(__dirname+'/fixtures/transport-plan-source.json','utf8'))[0].plan;
let state={version:1,plan:{...fixture,trips:[],requests:[]}},fields,dialog,screen,saveError=null,saves=[];
const notices=[],sectionCalls=[];
const versions=p=>Object.fromEntries(['vehicles','areas','fares','settings'].map(k=>[k,createHash('sha256').update(JSON.stringify(k==='settings'?[!!p.enabled,!!p.qrEnabled,p.note??null,p.noteEn??null]:[p[k]])).digest('hex')]));
const context={window:{},structuredClone,crypto:{randomUUID},loading:1,dirty:false,editing:false,
 el:(tag,text)=>new Element(tag,text),
 button:(text,action)=>Object.assign(new Element('button',text),{onclick:action}),
 toolbar:text=>new Element('div',text),orderMoney:n=>String(n),
 $:selector=>selector==='#fields'?fields:{hidden:false},
 shiftDialog:()=>{dialog=new Element('dialog');fields=new Element('div');dialog.append(fields);return dialog;},
 notice:(text,error)=>notices.push({text,error}),
 api:async(path,body)=>{
  if(path==='media')return {data:[]};
  if(path==='transport-plan/preview')return {quotes:[],trips:body.plan.trips.map(t=>({id:t.id,remaining:4-body.plan.requests.filter(r=>r.tripId===t.id&&['HELD','CONFIRMED'].includes(r.status)).reduce((n,r)=>n+r.passengers,0),open:t.status==='OPEN'})),suggestions:Object.fromEntries(body.plan.requests.filter(r=>r.status==='WAITING').map(r=>[r.id,body.plan.trips.filter(t=>t.status==='OPEN').map(t=>t.id)]))};
  if(path.startsWith('transport-plan/sections/')){const section=path.split('/').at(-1);assert.equal(body.version,versions(state.plan)[section]);sectionCalls.push({path,body:structuredClone(body)});saves.push(structuredClone(body));if(section==='settings')Object.assign(state.plan,structuredClone(body.settings));else state.plan[section]=structuredClone(body.rows);state.version++;return structuredClone(state);}
  assert.equal(path,'transport-plan');
  if(!body)return {...structuredClone(state),sectionVersions:versions(state.plan)};
  if(saveError)throw Error(saveError);
  assert.equal(body.version,state.version);
  saves.push(structuredClone(body));state={version:state.version+1,plan:structuredClone(body.plan)};return structuredClone(state);
 },
 render:async()=>{screen=new Element('section');await vm.runInContext('renderTransportPlan(screen,1)',Object.assign(sandbox,{screen}));}
};
const sandbox=vm.createContext(context);
vm.runInContext(fs.readFileSync(__dirname+'/../public/assets/transport-plan-admin.js','utf8'),sandbox);
async function submit(){await click(fields,'Kiểm tra lưu');assert.equal(saves.length,expectedSaves);await click(fields,'Xác nhận lưu');expectedSaves++;assert.equal(saves.length,expectedSaves);assert.equal(dialog.open,false);}
let expectedSaves=0;
(async()=>{
 await context.render();
 await click(screen,'Thêm Chuyến kế hoạch');
 for(const [label,value]of [['Ngày đi','2099-07-01'],['Bắt đầu đón','09:00'],['Kết thúc đón','09:30'],['Khởi hành','10:00'],['Trạng thái','OPEN']])control(label).value=value;
 await submit();
 const trip=state.plan.trips[0];assert.equal(trip.cutoffMinutes,30);assert.equal(trip.status,'OPEN');assert.equal(trip.vehicleId,'v1');assert.equal(typeof trip.seatPrice,'number');
 await click(screen,'Thêm Yêu cầu ghép');
 assert.equal(control('Chuyến được gán').disabled,true);
 for(const [label,value]of [['Tên khách / nhãn yêu cầu','<Khách A>'],['Ngày đi','2099-07-01'],['Giờ khách muốn đi','10:00'],['Chấp nhận lệch giờ','60'],['Số khách','2']])control(label).value=value;
 await submit();
 assert.equal(state.plan.requests[0].label,'<Khách A>');assert.equal(state.plan.requests[0].flexMinutes,60);assert.equal(typeof state.plan.requests[0].passengers,'number');assert.ok(!('tripId'in state.plan.requests[0]));
 const suggestion=all(screen).find(n=>n.tagName==='button'&&n.text.startsWith('Giữ chỗ: '));assert.ok(suggestion);await suggestion.onclick();
 assert.equal(control('Trạng thái').value,'HELD');assert.equal(control('Chuyến được gán').value,trip.id);assert.equal(control('Chuyến được gán').required,true);
 await submit();assert.equal(state.plan.requests[0].status,'HELD');assert.equal(state.plan.requests[0].tripId,trip.id);
 const requestCard=()=>all(screen).find(n=>n.tagName==='article'&&all(n).some(c=>c.tagName==='strong'&&c.text==='<Khách A>'));
 await click(requestCard(),'Chỉnh sửa');control('Trạng thái').value='CONFIRMED';control('Trạng thái').onchange();await submit();assert.equal(state.plan.requests[0].status,'CONFIRMED');
 await click(requestCard(),'Chỉnh sửa');control('Trạng thái').value='WAITING';control('Trạng thái').onchange();assert.equal(control('Chuyến được gán').value,'');assert.equal(control('Chuyến được gán').disabled,true);await submit();assert.ok(!('tripId'in state.plan.requests[0]));
 await click(requestCard(),'Chỉnh sửa');control('Trạng thái').value='CANCELLED';control('Trạng thái').onchange();await submit();assert.equal(state.plan.requests[0].status,'CANCELLED');
 const requestsGroup=all(screen).find(n=>n.tagName==='details'&&n.children.some(c=>c.tagName==='summary'&&c.text==='Yêu cầu ghép'));
 const dateFilter=find(requestsGroup,'label','Lọc ngày đi').children[0],statusFilter=find(requestsGroup,'label','Lọc trạng thái').children[0];
 statusFilter.value='WAITING';statusFilter.onchange();assert.equal(requestCard().hidden,true);
 statusFilter.value='CANCELLED';statusFilter.onchange();assert.equal(requestCard().hidden,false);
 dateFilter.value='2099-07-02';dateFilter.oninput();assert.equal(requestCard().hidden,true);
 await click(requestsGroup,'Bỏ lọc');assert.equal(requestCard().hidden,false);
 // A stale save must leave the dialog open and preserve form values for recovery.
 await click(requestCard(),'Chỉnh sửa');control('Tên khách / nhãn yêu cầu').value='Keep unsaved';saveError='Kế hoạch xe vừa thay đổi';await click(fields,'Kiểm tra lưu');await click(fields,'Xác nhận lưu');assert.equal(saves.length,expectedSaves);assert.equal(dialog.open,true);assert.equal(control('Tên khách / nhãn yêu cầu').value,'Keep unsaved');assert.ok(all(fields).some(n=>n.textContent?.includes(saveError)));saveError=null;
 assert.deepEqual(state.plan.vehicles,fixture.vehicles);assert.deepEqual(state.plan.areas,fixture.areas);assert.deepEqual(state.plan.fares,fixture.fares);
 // Catalog editing uses the section token and does not submit unrelated arrays.
 await context.render();const vehiclesGroup=all(screen).find(n=>n.tagName==='details'&&n.children.some(c=>c.tagName==='summary'&&c.text==='Loại xe'));await click(vehiclesGroup,'Chỉnh sửa');control('Tên loại xe').value='Renamed car';await submit();assert.equal(sectionCalls.at(-1).path,'transport-plan/sections/vehicles');assert.deepEqual(Object.keys(sectionCalls.at(-1).body).sort(),['rows','version']);assert.deepEqual(state.plan.areas,fixture.areas);
 await click(screen,'Kiểm tra thiết lập');await click(screen,'Xác nhận lưu thiết lập');assert.equal(sectionCalls.at(-1).path,'transport-plan/sections/settings');assert.deepEqual(Object.keys(sectionCalls.at(-1).body).sort(),['settings','version']);
 // Empty catalog still permits loading the screen; dependent add buttons stay disabled.
 state={version:0,plan:{enabled:false,qrEnabled:false,vehicles:[],areas:[],fares:[],trips:[],requests:[]}};await context.render();for(const name of ['Chuyến kế hoạch','Yêu cầu ghép'])assert.equal(find(screen,'button','Thêm '+name).disabled,true);
 console.log('PASS: plan UI trip creation, typed request payloads, suggested hold, confirmation, release, cancellation, date/status filters, preserved catalog, stale-save recovery and empty catalog.');
})().catch(e=>{console.error(e);process.exitCode=1;});
