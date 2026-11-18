'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Element{
 constructor(tag){this.tagName=tag;this.children=[];this.textContent='';this.hidden=false;this.dataset={};this.value='';this.classList={toggle(){}};}
 append(...nodes){this.children.push(...nodes);}prepend(...nodes){this.children.unshift(...nodes);}replaceChildren(...nodes){this.children=nodes;}
 addEventListener(name,fn){this[name]=fn;}setAttribute(){}removeAttribute(){}querySelectorAll(){return [];}showModal(){this.open=true;}focus(){}
}
const nodes=new Map(),get=selector=>{if(!nodes.has(selector))nodes.set(selector,new Element('div'));return nodes.get(selector);};
const all=n=>[n,...n.children.flatMap(all)];
const pending={id:'fixture',code:'OLD10',name:'Fixture voucher',value:10,minSubtotal:0,discountType:'PERCENT',enabled:true,version:12,visitWeekdays:[],scope:'ALL',targets:[],transferPending:true};
const native={...pending,id:'native',code:'PHP10',name:'Native fixture',transferPending:undefined};
const context=vm.createContext({document:{querySelector:get,querySelectorAll:()=>[],createElement:t=>new Element(t),addEventListener(){},readyState:'loading',body:{dataset:{}}},window:{addEventListener(){},matchMedia:()=>({matches:false,addEventListener(){}})},location:{hash:'#vouchers'},structuredClone,crypto:{randomUUID:()=> 'new-id'},refreshNotifications(){},console});
vm.runInContext(fs.readFileSync(__dirname+'/../public/assets/admin.js','utf8'),context);
vm.runInContext(fs.readFileSync(__dirname+'/../public/assets/vouchers-admin.js','utf8'),context);
context.pending=pending;context.native=native;
vm.runInContext("api=async path=>path==='products'?{data:[]}:{data:[pending,native],usage:{native:7}}",context);
(async()=>{
 await vm.runInContext('render()',context);
 let text=all(get('#screen')).map(n=>n.textContent).join('\n');
 assert.ok(text.includes('Chờ chuyển dữ liệu')&&text.includes('Chờ đối soát lượt dùng'));
 assert.ok(text.includes('7 lượt đã dùng'),'Native usage lost');
 assert.equal(text.includes('0 lượt đã dùng'),false,'Pending usage shown as zero');
 await vm.runInContext('openEditor(pending)',context);
 text=all(get('#fields')).map(n=>n.textContent).join('\n');
 assert.ok(text.includes('Lưu hoặc bật chương trình không tự mở khóa'));
 assert.equal(vm.runInContext("Object.hasOwn(draft,'transferPending')",context),false,'Runtime flag would be posted');
 assert.equal(get('#editor').open,true,'Pending editor failed to open');
 await vm.runInContext('openEditor(native)',context);
 assert.equal(all(get('#fields')).some(n=>n.textContent.includes('Lưu hoặc bật chương trình không tự mở khóa')),false,'Native account got transfer warning');
 console.log('PASS: full admin voucher pending badge, unknown usage, native usage and editable form without runtime flag.');
})().catch(e=>{console.error(e);process.exit(1);});
