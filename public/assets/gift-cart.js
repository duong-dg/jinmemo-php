'use strict';
(()=>{
 const key='jinmemo-gift-cart',pendingKey='jinmemo-gift-pending',eventName='jinmemo-gift-cart-change',copy=value=>JSON.parse(JSON.stringify(value)),fail=()=>{throw Error(document.documentElement.lang==='en'?'Unable to read or save the souvenir basket. Please review it before placing another order.':'Không đọc hoặc lưu được giỏ quà. Kiểm tra giỏ trước khi đặt thêm đơn.');};
 function read(){try{const raw=JSON.parse(localStorage.getItem(key)||'{}');if(!raw||typeof raw!=='object'||Array.isArray(raw)||Object.keys(raw).length>20)fail();for(const [id,line]of Object.entries(raw))if(!/^[0-9a-f-]{36}$/i.test(id)||!line||typeof line.name!=='string'||!Number.isInteger(line.quantity)||line.quantity<1||line.quantity>50)fail();return raw;}catch{fail();}}
 function pending(){try{const raw=JSON.parse(sessionStorage.getItem(pendingKey)||'null');if(raw!==null&&(!raw||typeof raw!=='object'||!raw.payload||typeof raw.payload.requestId!=='string'||!Array.isArray(raw.payload.lines)||!raw.snapshot))fail();return raw;}catch{fail();}}
 function emit(){window.dispatchEvent(new Event(eventName));}
 function write(next){localStorage.setItem(key,JSON.stringify(next));emit();}
 function setQuantity(id,name,quantity){if(pending())throw Error(document.documentElement.lang==='en'?'Resolve the pending souvenir order first.':'Xử lý yêu cầu đặt quà đang chờ trước.');if(!/^[0-9a-f-]{36}$/i.test(id)||typeof name!=='string'||!Number.isInteger(quantity)||quantity<0||quantity>50)fail();const next=read();if(quantity===0)delete next[id];else next[id]={name,quantity,revision:crypto.randomUUID()};if(Object.keys(next).length>20)throw Error(document.documentElement.lang==='en'?'The basket supports up to 20 souvenirs.':'Giỏ tối đa 20 món quà khác nhau.');write(next);}
 function savePending(entry){if(pending())throw Error(document.documentElement.lang==='en'?'A souvenir request is already pending.':'Đã có yêu cầu đặt quà đang chờ.');sessionStorage.setItem(pendingKey,JSON.stringify(entry));emit();}
 function clearPending(){sessionStorage.removeItem(pendingKey);emit();}
 function complete(snapshot){const next=read();for(const [id,old]of Object.entries(snapshot)){const current=next[id];if(current&&current.quantity===old.quantity&&current.revision===old.revision)delete next[id];}localStorage.setItem(key,JSON.stringify(next));sessionStorage.removeItem(pendingKey);emit();}
 window.jinmemoGiftCart={read,pending,setQuantity,savePending,clearPending,complete,copy,key,eventName};
})();
