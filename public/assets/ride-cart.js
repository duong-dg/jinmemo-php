'use strict';
(()=>{
 const key='jinmemo-ride-cart',eventName='jinmemo-ride-cart',pendingKey='jinmemo-private-ride-pending';
 const error=()=>Error(document.documentElement.lang==='en'?'Unable to update the ride basket. Resolve any pending request and check browser storage.':'Không cập nhật được giỏ xe. Kiểm tra lưu trữ trình duyệt và xử lý yêu cầu đang chờ trước.');
 function valid(d){const s=d?.selection;return !!s&&['fromName','toName','vehicleName'].every(k=>typeof d[k]==='string'&&d[k].length<=300)&&['from','to','vehicleId'].every(k=>typeof s[k]==='string'&&s[k].length>0&&s[k].length<=100)&&s.from!==s.to&&/^\d{4}-\d{2}-\d{2}$/.test(s.date)&&/^\d{2}:\d{2}$/.test(s.time)&&Number.isInteger(s.passengers)&&s.passengers>=1&&s.passengers<=60&&['PRIVATE','SHARED'].includes(s.mode)&&typeof s.roundTrip==='boolean'&&[0,30,60].includes(s.flexMinutes)&&Number.isSafeInteger(s.expectedTotal)&&s.expectedTotal>=0&&s.expectedTotal<=15000000000;}
 function read(){const d=JSON.parse(sessionStorage.getItem(key)||'null');if(d!==null&&!valid(d))throw error();return d;}
 function locked(){return !!window.jinmemoCheckout?.active()||!!sessionStorage.getItem(pendingKey);}
 function emit(){window.dispatchEvent(new Event(eventName));}
 function set(d){if(locked()||(d!==null&&!valid(d)))throw error();if(d===null)sessionStorage.removeItem(key);else{const s=d.selection;const selection={};for(const k of ['from','to','date','time','passengers','mode','roundTrip','flexMinutes','locale','returnTime','vehicleId','shareSize','poolId','expectedTotal'])if(s[k]!==undefined)selection[k]=s[k];sessionStorage.setItem(key,JSON.stringify({fromName:d.fromName,toName:d.toName,vehicleName:d.vehicleName,revision:crypto.randomUUID(),selection}));}emit();}
 function complete(payload,source){const d=read();if(d&&(!source||d.revision===source.revision)&&['from','to','date','time','passengers','mode','roundTrip','flexMinutes','vehicleId','shareSize','poolId','expectedTotal'].every(k=>d.selection[k]===payload[k])){sessionStorage.removeItem(key);emit();}}
 window.jinmemoRideCart={key,eventName,read,set,locked,complete};
})();
