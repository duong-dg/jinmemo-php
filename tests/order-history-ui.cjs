'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(__dirname+'/../public/assets/orders-admin.js','utf8'),start=source.indexOf('function renderSourceOrderHistory('),end=source.indexOf('async function openOrder(',start);
assert.ok(start>=0&&end>start);
const node=(tag,text,style)=>({tag,text,style,children:[],append(...children){this.children.push(...children);},set innerHTML(value){throw Error('Audit notes must be plain text');}}),context=vm.createContext({el:node,orderLabels:{PAY:'Ghi nhận thu tiền'},Date});
vm.runInContext(source.slice(start,end),context);const detail=node('div');context.renderSourceOrderHistory(detail,undefined);context.renderSourceOrderHistory(detail,[]);assert.equal(detail.children.length,0);
const note='  <img src=x onerror=alert(1)>\nGhi chú gốc 👋  ',history=[{id:'original',action:'PAY',adminId:'old-admin',note,createdAt:'2025-01-01T00:00:00.123456Z'},{id:'second',action:'REVOKE_FILE',adminId:'archived-actor',note:'Không chạy lại',createdAt:'2025-01-01T00:00:00.123456Z'}],before=JSON.stringify(history);
context.renderSourceOrderHistory(detail,history);assert.equal(detail.children[0].text,'Lịch sử từ hệ thống cũ');assert.equal(detail.children[1].children[0].text,'Ghi nhận thu tiền');assert.equal(detail.children[1].children[2].text,note);assert.equal(detail.children[2].children[0].text,'REVOKE_FILE');assert.match(detail.children[2].children[1].text,/archived-actor/);assert.equal(JSON.stringify(history),before);assert.ok(!detail.children.some(n=>n.tag==='button'));
console.log('PASS: original order audit is readable, preserves raw notes and tied timestamps, renders as text and offers no action replay.');
