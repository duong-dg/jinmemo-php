'use strict';
let posCounter='';
async function renderPos(screen,serial){
 try{
  const [counterResult,productResult]=await Promise.all([api('counters'),api('products')]);if(serial!==loading||$('#workspace').hidden)return;
  const counters=counterResult.data,products=productResult.data.filter(p=>p.status==='PUBLISHED'&&!p.demo);
  if(!counters.length){screen.append(empty('Tạo quầy bán vé trước khi bán tại quầy'));return;}
  if(!counters.some(c=>c.id===posCounter))posCounter=counters.find(c=>c.status==='ACTIVE')?.id||counters[0].id;
  const bar=toolbar('Đơn tại quầy'),select=el('select');select.setAttribute('aria-label','Quầy bán POS');for(const c of counters){const o=el('option',c.name+(c.status==='ARCHIVED'?' · lưu trữ':''));o.value=c.id;select.append(o);}select.value=posCounter;select.onchange=()=>{posCounter=select.value;render();};bar.append(select);
  const counter=counters.find(c=>c.id===posCounter),create=button('Tạo đơn tại quầy',()=>openPosCreate(counter,products),'primary');create.disabled=counter.status!=='ACTIVE'||!products.length;bar.append(create);screen.append(bar,el('p','Giá tại quầy không áp dụng ưu đãi online hoặc voucher Website. Tạo đơn giữ tồn vé; ghi nhận tiền và giao file tại chi tiết đơn.','muted'));
  const shifts=await api('shifts?'+new URLSearchParams({counterId:counter.id}));if(serial!==loading)return;const active=shifts.data.find(s=>!s.closedAt);screen.append(el('p',active?'Ca đang mở: '+active.id:'Quầy chưa mở ca. Khoản thu vẫn ghi sổ nhưng không được gắn ca.','muted'));const link=el('a','Quản lý ca thu ngân ↗');link.href='#shifts';link.onclick=()=>{shiftCounter=counter.id;};screen.append(link);
  const result=await api('orders?'+new URLSearchParams({channel:'POS',counterId:counter.id,pageSize:25}));if(serial!==loading)return;
  screen.append(el('p',result.summary.total+' đơn đang quản lý · Hiển thị 25 đơn mới nhất. Xem toàn bộ và lọc thêm tại Đơn hàng.'));
  for(const o of result.data){const card=el('article',undefined,'card');card.append(el('h3',o.code+' · '+o.contact.name),el('p',o.snapshot.productName+' · '+o.snapshot.variantName+' · '+o.snapshot.date),el('p',orderMoney(o.snapshot.total)+' · '+(orderLabels[o.paymentStatus]||o.paymentStatus)),button('Xem đơn ↗',()=>openOrder(o.id,serial)));screen.append(card);}
 }catch(error){if(serial===loading)notice(error.message,true);}
}
function openPosCreate(counter,products){
 const dialog=shiftDialog('Tạo đơn tại quầy'),box=el('div',undefined,'full'),formInputs=[],status=el('p'),quoteArea=el('div'),confirmation=el('div',undefined,'toolbar');let quote=null,locked=false;
 const requestId=crypto.randomUUID(),bytes=crypto.getRandomValues(new Uint8Array(32)),accessKey=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
 const clearQuote=()=>{dirty=true;quote=null;quoteArea.replaceChildren();confirmation.replaceChildren();};
 const field=(title,type='text')=>{const label=el('label',title),input=el(type==='select'?'select':type==='textarea'?'textarea':'input');if(!['select','textarea'].includes(type))input.type=type;input.oninput=clearQuote;label.append(input);box.append(label);formInputs.push(input);return input;};
 box.append(el('p',counter.name),el('p','Tạo đơn chưa đồng nghĩa đã thu tiền hoặc giao vé. File gốc gửi cho khách qua quy trình hiện tại; bản PHP chưa tự gửi email.','muted'));
 const product=field('Sản phẩm tại quầy','select'),variant=field('Gói vé tại quầy','select'),date=field('Ngày sử dụng tại quầy','date'),slot=field('Suất giờ tại quầy','select'),quantities=el('div');box.append(quantities);let quantityInputs={};
 for(const p of products){const option=el('option',p.name);option.value=p.id;product.append(option);}
 date.value=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Ho_Chi_Minh',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());date.min=date.value;
 const chosen=()=>products.find(p=>p.id===product.value),chosenVariant=()=>chosen().variants.find(v=>v.id===variant.value);
 function slots(){slot.replaceChildren();const options=ticketSchedule.slots(chosenVariant(),date.value);if(!options.length){const o=el('option','Không dùng suất giờ');o.value='';slot.append(o);}else for(const s of options){const o=el('option',s.start+(s.end?' – '+s.end:''));o.value=s.id;slot.append(o);}clearQuote();}
 function guests(){quantities.replaceChildren();quantityInputs={};const labels={ADULT:'Người lớn',CHILD:'Trẻ em',SENIOR:'Người cao tuổi',LOCAL_ADULT:'Người lớn địa phương',LOCAL_CHILD:'Trẻ em địa phương',LOCAL_SENIOR:'Người cao tuổi địa phương'};for(const key of Object.keys(chosenVariant().prices)){const label=el('label',labels[key]||key),n=el('input');n.type='number';n.min='0';n.max='50';n.step='1';n.value=key==='ADULT'?'1':'0';n.oninput=clearQuote;label.append(n);quantities.append(label);quantityInputs[key]=n;}slots();}
 function variants(){variant.replaceChildren();for(const v of [...chosen().variants].sort((a,b)=>(a.displayOrder??10001)-(b.displayOrder??10001))){const o=el('option',v.name);o.value=v.id;variant.append(o);}guests();}
 product.onchange=variants;variant.onchange=guests;date.onchange=slots;variants();dirty=false;
 const name=field('Tên khách tại quầy'),email=field('Email khách tại quầy','email'),phone=field('Điện thoại khách tại quầy','tel'),note=field('Ghi chú đơn tại quầy','textarea');name.maxLength=100;email.maxLength=200;phone.maxLength=25;note.maxLength=1000;
 const selection=()=>({productId:product.value,variantId:variant.value,date:date.value,...(slot.value?{slotId:slot.value}:{}),quantities:Object.fromEntries(Object.entries(quantityInputs).map(([k,n])=>[k,Number(n.value)]))});
 const allInputs=()=>[...formInputs,...Object.values(quantityInputs)],setBusy=value=>{for(const n of allInputs())n.disabled=value;preview.disabled=value;};
 const preview=button('Lấy giá tại quầy',async()=>{if(locked)return;locked=true;editing=true;setBusy(true);status.textContent='Đang lấy giá…';try{const quoteSelection=selection();quote=await api('pos/quotes',quoteSelection);quoteArea.replaceChildren(el('h3','Giá tại quầy: '+orderMoney(quote.total)),el('p',quote.lines.map(l=>l.customerType+' × '+l.quantity+' · '+orderMoney(l.subtotal)).join(' | ')),el('p',quote.inventory.limited?'Còn '+quote.inventory.remaining+' vé trước đơn này.':'Không giới hạn tồn vé.'));status.textContent='';const prepare=button('Kiểm tra và tạo đơn',()=>{
   if(!quote||!name.value.trim()||!email.checkValidity()||!email.value||!phone.value.trim()){status.textContent='Nhập tên, email và điện thoại hợp lệ.';return;}
   const payload={...quoteSelection,requestId,accessKey,counterId:counter.id,expectedTotal:quote.total,contact:{name:name.value.trim(),email:email.value.trim(),phone:phone.value.trim(),note:note.value.trim()},locale:'vi',accepted:true};setBusy(true);prepare.disabled=true;
   confirmation.replaceChildren(el('p','Xác nhận khách đồng ý thông tin đặt vé và tạo đơn '+orderMoney(payload.expectedTotal)+'?'),button('Xác nhận tạo đơn',async()=>{if(editing)return;editing=true;dialog.querySelectorAll('button').forEach(b=>b.disabled=true);try{const result=await api('pos/orders',payload);dirty=false;dialog.close();await render();await openOrder(result.id,loading);}catch(error){status.textContent=error.message+' Nếu lỗi mạng, xác nhận lại cùng yêu cầu.';}finally{editing=false;dialog.querySelectorAll('button').forEach(b=>b.disabled=false);preview.disabled=true;prepare.disabled=true;}},'primary'),button('Quay lại',()=>{setBusy(false);prepare.disabled=false;confirmation.replaceChildren();}));
  },'primary');quoteArea.append(prepare);
  }catch(error){status.textContent=error.message;}finally{locked=false;editing=false;setBusy(false);}},'secondary');
 box.append(preview,quoteArea,confirmation,status);$('#fields').append(box);dialog.showModal();
}
