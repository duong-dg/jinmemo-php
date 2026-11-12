'use strict';
for(const form of document.querySelectorAll('.quote-form')){
 const result=form.querySelector('.quote-result'),fieldset=form.querySelector('fieldset'),date=form.querySelector('[name=date]');const en=form.dataset.locale==='en';date.min=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Ho_Chi_Minh',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());let sequence=0;
 form.addEventListener('input',()=>{sequence++;result.replaceChildren();result.hidden=true;});
 form.addEventListener('submit',async event=>{
  event.preventDefault();const serial=++sequence,values=new FormData(form),quantities={};for(const input of form.querySelectorAll('[data-group]'))quantities[input.dataset.group]=Number(input.value||0);const payload={productId:form.dataset.product,variantId:form.dataset.variant,date:values.get('date'),quantities};if(values.get('slotId'))payload.slotId=values.get('slotId');fieldset.disabled=true;result.replaceChildren();result.hidden=true;
  try{const response=await fetch('/api/v1/quotes?lang='+form.dataset.locale,{method:'POST',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});const quote=await response.json().catch(()=>({}));if(serial!==sequence)return;if(!response.ok)throw new Error(quote.message||(en?'Could not get a quote.':'Chưa lấy được giá.'));
   const total=document.createElement('strong');total.textContent=(en?'Total: ':'Tổng: ')+new Intl.NumberFormat(en?'en-US':'vi-VN').format(quote.total)+' VND';result.append(total);
   const stock=document.createElement('p');stock.textContent=quote.inventory.limited?(en?'Places remaining: ':'Số suất còn lại: ')+quote.inventory.remaining:(en?'Availability is not limited for this selection.':'Lựa chọn này chưa giới hạn số suất.');result.append(stock);
   for(const line of quote.lines){const row=document.createElement('p');const label=form.querySelector('[data-group="'+line.customerType+'"]')?.closest('label')?.querySelector('span')?.textContent||line.customerType;row.textContent=label+' × '+line.quantity+': '+new Intl.NumberFormat(en?'en-US':'vi-VN').format(line.subtotal)+' VND';result.append(row);}
   const note=document.createElement('small');note.textContent=en?'Price and availability checked. No reservation has been made.':'Đã kiểm tra giá và số suất. Kết quả này chưa giữ chỗ hoặc tạo đơn.';result.append(note);result.classList.remove('error');
  }catch(error){if(serial!==sequence)return;result.textContent=error.message;result.classList.add('error');}finally{fieldset.disabled=false;if(serial===sequence)result.hidden=false;}
 });
}
