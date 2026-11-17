'use strict';
(()=>{
 const key='jinmemo-ticket-cart',pendingKey='jinmemo-cart-pending',legacyKeys=['jinmemo-ticket-cart:vi','jinmemo-ticket-cart:en'],pendingKeys=[pendingKey,'jinmemo-cart-pending:vi','jinmemo-cart-pending:en'],eventName='jinmemo-ticket-cart-change';
 const fail=()=>Error(document.documentElement.lang==='en'?'Unable to read the ticket basket. Your saved data has been preserved.':'Không đọc được giỏ vé. Dữ liệu đã lưu vẫn được giữ nguyên.');
 function parse(raw){const rows=JSON.parse(raw||'[]');if(!Array.isArray(rows)||rows.length>40||rows.some(r=>!r||typeof r.id!=='string'||!r.payload||typeof r.payload!=='object'||!Number.isSafeInteger(r.payload.expectedTotal)))throw fail();if(new Set(rows.map(r=>r.id)).size!==rows.length)throw fail();return rows;}
 function read(){try{const raw=localStorage.getItem(key);if(raw!==null)return parse(raw);const rows=[];for(const oldKey of legacyKeys)for(const row of parse(localStorage.getItem(oldKey))){const existing=rows.find(r=>r.id===row.id);if(existing){if(JSON.stringify(existing)!==JSON.stringify(row))throw fail();}else rows.push(row);}if(rows.length>40)throw fail();if(rows.length)localStorage.setItem(key,JSON.stringify(rows));return rows;}catch{throw fail();}}
 function locked(){return pendingKeys.some(k=>sessionStorage.getItem(k)!==null);}
 function pending(){const k=pendingKeys.find(k=>sessionStorage.getItem(k)!==null);if(!k)return null;try{const value=JSON.parse(sessionStorage.getItem(k));if(!value?.body||!Array.isArray(value.lineIds)||typeof value.body.requestId!=='string')throw fail();return {key:k,value};}catch{throw fail();}}
 function write(rows){parse(JSON.stringify(rows));localStorage.setItem(key,JSON.stringify(rows));window.dispatchEvent(new Event(eventName));}
 window.jinmemoTicketCart={key,pendingKey,legacyKeys,pendingKeys,eventName,read,write,locked,pending};
})();
