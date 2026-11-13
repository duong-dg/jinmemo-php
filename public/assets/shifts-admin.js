'use strict';
let shiftCounter='';
const shiftTime=value=>value?new Date(value).toLocaleString('vi-VN',{timeZone:'Asia/Ho_Chi_Minh'}):'—';
async function renderShifts(screen,serial){
 try{
  const counters=(await api('counters')).data;if(serial!==loading)return;
  if(!counters.length){screen.append(empty('Tạo quầy bán vé trước khi mở ca'));return;}
  if(!counters.some(c=>c.id===shiftCounter))shiftCounter=counters[0].id;
  const bar=toolbar('50 ca gần nhất tại quầy'),select=el('select');select.setAttribute('aria-label','Quầy thu ngân');
  for(const c of counters){const option=el('option',c.name+(c.status==='ARCHIVED'?' · lưu trữ':''));option.value=c.id;select.append(option);}select.value=shiftCounter;select.onchange=()=>{shiftCounter=select.value;render();};bar.append(select);screen.append(bar);
  const result=await api('shifts?'+new URLSearchParams({counterId:shiftCounter}));if(serial!==loading)return;
  const counter=counters.find(c=>c.id===shiftCounter),open=button('Mở ca',()=>shiftForm(counter,null,'open'),'primary');open.disabled=counter.status!=='ACTIVE'||result.data.some(s=>!s.closedAt);bar.append(open);
  screen.append(el('p','Thu/chi tiền mặt và kiểm đếm khi chốt ca. Khoản thu chỉ xuất hiện khi được gắn với ca; các đơn website không tự cộng vào ca.','muted'));
  if(!result.data.length)screen.append(empty('Quầy chưa có ca'));
  for(const s of result.data){const card=el('article',undefined,'card');card.append(el('h3',s.closedAt?'Đã chốt ca':'Ca đang mở'),el('p',s.counterName+' · '+s.openedName),el('p',shiftTime(s.openedAt)+' · Đầu ca '+orderMoney(s.openingCash)),button('Xem ca',()=>showShift(s.id)));screen.append(card);}
 }catch(error){if(serial===loading)notice(error.message,true);}
}
function shiftDialog(title){draft=null;dirty=false;$('#editor-title').textContent=title;$('#fields').replaceChildren();$('#edit-error').hidden=true;$('#edit-form button[type=submit]').hidden=true;return $('#editor');}
async function showShift(id){
 try{const s=await api('shifts/'+id),dialog=shiftDialog('Chi tiết ca thu ngân'),box=el('div',undefined,'full'),totals=s.summary;
  box.append(el('h3',s.counterName),el('p',s.id),el('p','Mở: '+shiftTime(s.openedAt)+' · '+s.openedName),el('p',s.openingNote||''));
  for(const [key,label]of [['openingCash','Đầu ca'],['cash','Thu tiền mặt'],['bank','Thu chuyển khoản'],['cashIn','Bổ sung'],['cashOut','Rút/chi'],['expectedCash','Tiền mặt theo sổ'],['countedCash','Thực đếm'],['difference','Chênh lệch']]){const value=key==='openingCash'?s.openingCash:totals[key];if(value!==undefined)box.append(el('p',label+': '+orderMoney(value)));}
  if(s.closedAt)box.append(el('p','Chốt: '+shiftTime(s.closedAt)),el('p',s.closeNote));
  const actions=el('div',undefined,'toolbar');if(s.canClose)for(const [action,label]of [['IN','Bổ sung tiền'],['OUT','Rút/chi tiền'],['close','Chốt ca']])actions.append(button(label,()=>{dialog.close();shiftForm(null,s,action);}));
  for(const [suffix,label]of [['export','CSV khoản thu'],['cash/export','CSV thu/chi']])actions.append(button(label,()=>shiftDownload(id,suffix)));box.append(actions);
  box.append(el('h3','Khoản thu · '+s.receipts.length));for(const r of s.receipts)box.append(el('p',r.code+' · '+r.method+' · '+orderMoney(r.amount)+' · '+r.reference));
  box.append(el('h3','Thu/chi tiền mặt · '+s.movements.length));for(const m of s.movements)box.append(el('p',(m.kind==='IN'?'Bổ sung':'Rút/chi')+' · '+orderMoney(m.amount)+' · '+m.reference+' · '+m.note+' · '+m.actor_name));
  $('#fields').append(box);dialog.showModal();
 }catch(error){notice(error.message,true);}
}
function shiftForm(counter,shift,action){
 const opening=action==='open',closing=action==='close',dialog=shiftDialog(opening?'Mở ca':closing?'Chốt ca':action==='IN'?'Bổ sung tiền':'Rút/chi tiền'),box=el('div',undefined,'full'),inputs=[];
 box.append(el('p',opening?counter.name:shift.counterName));if(closing)box.append(el('p','Tiền mặt theo sổ: '+orderMoney(shift.summary.expectedCash)));
 const input=(label,type='text',max=1000)=>{const wrap=el('label',label),n=el(type==='textarea'?'textarea':'input');if(type!=='textarea')n.type=type;n.maxLength=max;n.oninput=()=>{dirty=true;};wrap.append(n);box.append(wrap);inputs.push(n);return n;};
 const amount=input(opening?'Tiền đầu ca (VND)':closing?'Tiền thực đếm (VND)':'Số tiền (VND)','number');amount.min=opening||closing?'0':'1';amount.max='15000000000';amount.step='1';amount.value=opening?'0':'';
 const reference=opening||closing?null:input('Mã chứng từ','text',150),note=input(opening?'Ghi chú mở ca (không bắt buộc)':'Lý do / ghi chú','textarea');
 const confirmation=el('div',undefined,'toolbar'),requestId=crypto.randomUUID(),prepare=button('Kiểm tra',()=>{
  const value=Number(amount.value);if(!amount.value||!Number.isSafeInteger(value)||value<Number(amount.min)||value>15000000000||(!opening&&!note.value.trim())||(reference&&!reference.value.trim())){$('#edit-error').textContent='Nhập số tiền hợp lệ, mã chứng từ và lý do.';$('#edit-error').hidden=false;return;}
  const payload=opening?{requestId,counterId:counter.id,openingCash:value,note:note.value.trim()}:{requestId,version:shift.version,...(closing?{countedCash:value}:{kind:action,amount:value,reference:reference.value.trim()}),note:note.value.trim()};
  inputs.forEach(n=>n.disabled=true);prepare.disabled=true;confirmation.replaceChildren(el('p',closing?'Chênh lệch: '+orderMoney(value-shift.summary.expectedCash)+'. Xác nhận chốt ca?':'Xác nhận ghi nhận thao tác?'),button('Xác nhận',async()=>{
   if(editing)return;editing=true;dialog.querySelectorAll('button').forEach(b=>b.disabled=true);
   try{const result=await api(opening?'shifts':'shifts/'+shift.id+'/'+(closing?'close':'cash'),payload);dirty=false;dialog.close();await render();await showShift(result.id);}catch(error){$('#edit-error').textContent=error.message+' Có thể xác nhận lại khi lỗi mạng.';$('#edit-error').hidden=false;}finally{editing=false;dialog.querySelectorAll('button').forEach(b=>b.disabled=false);prepare.disabled=true;}
  },'primary'),button('Quay lại',()=>{inputs.forEach(n=>n.disabled=false);prepare.disabled=false;confirmation.replaceChildren();}));
 });box.append(prepare,confirmation);$('#fields').append(box);dialog.showModal();amount.focus();
}
async function shiftDownload(id,suffix){try{const headers={'X-Jingo-Admin':'1'};if(document.body.dataset.entry)headers['X-Jingo-Admin-Entry']=document.body.dataset.entry;const response=await fetch('/api/v1/admin/shifts/'+id+'/'+suffix,{headers,credentials:'same-origin',cache:'no-store'});if(!response.ok){if(response.status===401)showLogin();throw new Error((await response.json()).message||'Không tải được CSV.');}const url=URL.createObjectURL(await response.blob()),a=el('a');a.href=url;a.download='shift-'+(suffix==='export'?'receipts-':'cash-')+id+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}catch(error){notice(error.message,true);}}
