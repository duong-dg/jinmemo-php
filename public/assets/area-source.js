'use strict';
(()=>{
 const el=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;return n;};
 const select=(parent,title)=>{const label=el('label',title),n=el('select');label.append(n);parent.append(label);return n;};
 const options=(n,rows,title)=>{n.replaceChildren();const empty=el('option',title);empty.value='';n.append(empty);for(const row of rows){const o=el('option',row.name);o.value=String(row.code);n.append(o);}n.value='';};
 const button=(parent,title,action)=>{const n=el('button',title);n.type='button';n.onclick=action;parent.append(n);return n;};
 window.jinmemoGiftAddressPicker=({address,en=false})=>{
  const tr=(vi,eng)=>en?eng:vi,box=el('details'),summary=el('summary',tr('Chọn tỉnh/thành và phường/xã','Choose a province and ward')),status=el('p');
  box.className='address-picker';status.role='status';box.append(summary);
  const province=select(box,tr('Tỉnh/thành','Province / city')),ward=select(box,tr('Phường/xã','Ward / commune'));let sequence=0,loaded=false;
  options(province,[],tr('Chọn tỉnh/thành','Choose a province'));options(ward,[],tr('Chọn phường/xã','Choose a ward'));ward.disabled=true;
  async function request(code='') {const r=await fetch('/api/v1/gifts/addresses'+(code?'?province='+encodeURIComponent(code):''),{credentials:'omit',cache:'no-store'});if(!r.ok)throw Error(tr('Chưa tải được địa chỉ. Bạn vẫn có thể nhập tay.','Addresses could not be loaded. You can still type your address.'));const result=await r.json();if(!Array.isArray(result.data))throw Error(tr('Danh mục địa chỉ không hợp lệ.','Invalid address list.'));return result.data;}
  async function loadProvinces(){if(loaded||province.disabled)return;province.disabled=true;status.textContent=tr('Đang tải…','Loading…');try{options(province,await request(),tr('Chọn tỉnh/thành','Choose a province'));loaded=true;status.textContent='';}catch(e){status.textContent=e.message;}finally{province.disabled=false;}}
  box.ontoggle=()=>{if(box.open)loadProvinces();};
  province.onchange=async()=>{const seq=++sequence,code=province.value;options(ward,[],tr('Chọn phường/xã','Choose a ward'));ward.disabled=true;apply.disabled=true;status.textContent='';if(!code)return;try{const rows=await request(code);if(seq!==sequence||province.value!==code)return;options(ward,rows,tr('Chọn phường/xã','Choose a ward'));ward.disabled=false;}catch(e){if(seq===sequence)status.textContent=e.message;}};
  ward.onchange=()=>{apply.disabled=!province.value||!ward.value;};
  const apply=button(box,tr('Thêm khu vực vào địa chỉ','Add area to address'),()=>{if(address.disabled||address.matches?.(':disabled')||!province.value||!ward.value)return;const p=province.selectedOptions[0]?.textContent,w=ward.selectedOptions[0]?.textContent;if(!p||!w)return;const suffix=w+', '+p,old=address.value.trim(),next=old.endsWith(suffix)?old:(old?old+', ':'')+suffix;if(next.length>(address.maxLength>0?address.maxLength:500)){status.textContent=tr('Địa chỉ quá dài; chỉnh lại trước khi thêm.','The address is too long; shorten it before adding the area.');return;}address.value=next;address.dispatchEvent(new Event('input',{bubbles:true}));status.textContent=tr('Đã thêm khu vực. Kiểm tra số nhà và tên đường trước khi đặt.','Area added. Check the street and house number before ordering.');});
  apply.disabled=true;button(box,tr('Tải lại danh mục','Retry address list'),()=>{loaded=false;loadProvinces();});box.append(status);address.parentElement.after(box);address.jinmemoAreaPicker=box;return box;
 };
 window.jinmemoRideAreaImport=({parent,plan,version,api,isAlive,onSaved})=>{
  const box=el('section'),title=el('h4','Thêm khu vực từ danh mục hành chính'),fields=el('fieldset'),status=el('p'),review=el('div');status.role='status';box.append(title,fields,review,status);parent.append(box);
  const mode=select(fields,'Danh mục'),province=select(fields,'Tỉnh/thành'),district=select(fields,'Quận/huyện (danh mục cũ)');
  for(const [value,text]of [['v2','Tỉnh và phường/xã hiện tại'],['v1','Tỉnh, huyện và xã cũ']]){const o=el('option',text);o.value=value;mode.append(o);}mode.value='v2';let seq=0,busy=false;
  options(province,[],'Chọn tỉnh/thành');options(district,[],'Chọn quận/huyện');district.parentElement.hidden=true;
  const url=()=> 'ride-areas?'+new URLSearchParams({version:mode.value,...(province.value?{province:province.value}:{}),...(mode.value==='v1'&&district.value?{district:district.value}:{})});
  async function load(){const id=++seq;review.replaceChildren();options(province,[],'Chọn tỉnh/thành');options(district,[],'Chọn quận/huyện');district.parentElement.hidden=mode.value!=='v1';province.disabled=true;preview.disabled=true;status.textContent='Đang tải danh mục…';try{const result=await api(url());if(!isAlive()||id!==seq)return;options(province,result.data,'Chọn tỉnh/thành');status.textContent='';}catch(e){if(isAlive()&&id===seq)status.textContent=e.message;}finally{if(id===seq)province.disabled=false;}}
  mode.onchange=load;
  province.onchange=async()=>{const id=++seq;review.replaceChildren();options(district,[],'Chọn quận/huyện');preview.disabled=!province.value||mode.value==='v1';if(!province.value||mode.value!=='v1')return;district.disabled=true;try{const result=await api(url());if(!isAlive()||id!==seq)return;options(district,result.data,'Chọn quận/huyện');status.textContent='';}catch(e){if(isAlive()&&id===seq)status.textContent=e.message;}finally{if(id===seq)district.disabled=false;}};
  district.onchange=()=>{++seq;review.replaceChildren();preview.disabled=!district.value;};
  const preview=button(fields,'Xem trước khu vực sẽ thêm',async()=>{
   if(busy||!province.value||mode.value==='v1'&&!district.value)return;const id=++seq;busy=true;fields.disabled=true;review.replaceChildren();status.textContent='Đang tải khu vực…';
   try{const result=await api(url());if(!isAlive()||id!==seq)return;const existing=new Set(plan.areas.map(a=>a.id)),added=result.data.filter(a=>!existing.has(a.id)),next=structuredClone(plan);next.areas.push(...added);if(next.areas.length>1000)throw Error('Vượt giới hạn 1.000 khu vực. Chọn phạm vi nhỏ hơn.');status.textContent='';review.append(el('p','Sẽ thêm '+added.length+' khu vực; giữ nguyên '+(result.data.length-added.length)+' mục đã có, tên chỉnh tay, phụ thu và giá tuyến.'));
    const list=el('ul');for(const a of added)list.append(el('li',a.name));review.append(list);
    const save=button(review,'Xác nhận thêm khu vực',async()=>{if(busy||!isAlive())return;busy=true;fields.disabled=true;save.disabled=back.disabled=true;try{await api('transport-plan/sections/areas',{version,rows:next.areas});if(isAlive())await onSaved();}catch(e){if(isAlive())status.textContent=e.message+' Cấu hình chưa được tự ghi đè; tải lại nếu phiên bản đã thay đổi.';}finally{busy=false;fields.disabled=false;save.disabled=back.disabled=false;}}),back=button(review,'Quay lại',()=>{review.replaceChildren();});save.disabled=added.length===0;
   }catch(e){if(isAlive()&&id===seq)status.textContent=e.message;}finally{busy=false;fields.disabled=false;}
  });preview.disabled=true;button(fields,'Tải lại danh mục',load);load();return box;
 };
})();
