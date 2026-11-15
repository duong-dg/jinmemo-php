'use strict';
(()=>{
 let cached=null;
 const el=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;return n;};
 window.ticketPaymentSummary=(parent,choice,en=false)=>{if(!choice)return;parent.append(el('p',(en?'Selected payment method: ':'Khách chọn thanh toán: ')+(en?choice.englishName||choice.name:choice.name)),el('p',en?choice.englishInstructions||choice.instructions:choice.instructions));};
 window.ticketPaymentChoice=({parent,en=false})=>{
  const label=el('label',en?'Payment method':'Phương thức thanh toán'),select=el('select'),status=el('p'),retry=el('button',en?'Reload payment methods':'Tải lại phương thức');select.name='paymentPreference';select.required=true;select.disabled=true;retry.type='button';retry.hidden=true;label.append(select);parent.append(label,status,retry);let methods=[],wanted='';
  function applyWanted(){if(!wanted)return;if(!methods.some(m=>m.id===wanted)){const o=el('option',(en?'Previously selected: ':'Đã chọn: ')+wanted);o.value=wanted;select.append(o);}select.value=wanted;}
  function show(){const m=methods.find(m=>m.id===select.value);status.textContent=m?(en?m.englishInstructions||m.instructions:m.instructions):(en?'No manual payment method is available. Contact us before booking.':'Chưa có phương thức thanh toán thủ công. Liên hệ trước khi đặt.');}
  async function load(){retry.disabled=true;status.textContent=en?'Loading payment methods…':'Đang tải phương thức…';try{if(!cached)cached=fetch('/api/v1/ticket-payment-methods',{credentials:'omit',cache:'no-store'}).then(async r=>{const data=await r.json();if(!r.ok||!Array.isArray(data.data))throw Error(en?'Unable to load payment methods.':'Không tải được phương thức.');return data.data;}).catch(e=>{cached=null;throw e;});methods=await cached;select.replaceChildren();for(const m of methods){const o=el('option',en?m.englishName||m.name:m.name);o.value=m.id;select.append(o);}applyWanted();select.disabled=!methods.length;retry.hidden=false;show();}catch(e){select.disabled=true;retry.hidden=false;status.textContent=e.message;}finally{retry.disabled=false;}}
  select.onchange=()=>{wanted=select.value;show();};retry.onclick=()=>{cached=null;return load();};load();
  return {value(){if(select.disabled||!methods.some(m=>m.id===select.value))throw Error(en?'Choose an available payment method.':'Chọn phương thức thanh toán đang nhận.');return select.value;},restore(id){wanted=id||'';applyWanted();show();}};
 };
})();
