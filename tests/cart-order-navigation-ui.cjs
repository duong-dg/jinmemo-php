'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
class Element{
 constructor(tag,text){this.tagName=tag;this.textContent=text;this.children=[];this.hidden=false;this.open=false;}
 append(...nodes){this.children.push(...nodes);}replaceChildren(...nodes){this.children=nodes;}setAttribute(){}showModal(){this.open=true;}close(){this.open=false;}
}
const nodes=new Map(),$=s=>{if(!nodes.has(s))nodes.set(s,new Element(s));return nodes.get(s);},el=(t,text)=>new Element(t,text),all=n=>[n,...n.children.flatMap(all)],calls=[];let root='';
const order={id:'child',code:'JG1234567890',cartCode:'JC123456ABCD',channel:'WEB',snapshot:{productName:'Ticket',variantName:'Package',date:'2099-06-01',total:100,lines:[]},contact:{name:'Guest',email:'guest@example.test'},bookingStatus:'CANCELLED',paymentStatus:'UNPAID',archived:true,archiveReason:'Fixture',history:[],payments:[],tickets:[],totalGuests:1,deliveredGuests:0};
const context=vm.createContext({$,el,button:(t,onclick)=>Object.assign(el('button',t),{onclick}),loading:1,current:'carts',draft:null,dirty:false,editing:false,api:async path=>{calls.push(path);return {data:path.endsWith('/files')?[]:structuredClone(order)};},renderOrderMail(){},addSupplyAllocation:async()=>{},addStockDelivery:async()=>{},notice:message=>{throw Error(message);},openCartFor:code=>{root=code;},Intl,Date});
vm.runInContext(fs.readFileSync(__dirname+'/../public/assets/orders-admin.js','utf8'),context);
(async()=>{
 await context.openOrder('child',1);assert.equal($('#editor').open,true,'Opening a child from the carts screen must reach showModal');assert.deepEqual(calls,['orders/child','orders/child/files']);const back=all($('#fields')).find(n=>n.tagName==='button'&&n.textContent==='Mở giỏ '+order.cartCode+' / thu chung');assert.ok(back);back.onclick();assert.equal(root,order.cartCode);assert.equal($('#editor').open,false,'Close the child dialog when returning to the cart');assert.equal(calls.length,2,'Navigation never submits a financial action');
 root='';context.loading=2;await context.openOrder('child',1);assert.equal($('#editor').open,false,'A stale request must not reopen the dialog');
 console.log('PASS: actual admin order renderer opens a child from carts, returns to its parent without mutation and ignores stale requests.');
})().catch(e=>{console.error(e);process.exitCode=1;});
