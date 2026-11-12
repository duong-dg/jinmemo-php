'use strict';
const guestLabels={ADULT:'Người lớn',CHILD:'Trẻ em',SENIOR:'Người cao tuổi',LOCAL_ADULT:'Người lớn · Địa phương',LOCAL_CHILD:'Trẻ em · Địa phương',LOCAL_SENIOR:'Người cao tuổi · Địa phương'};
async function buildProductFields(existing){
 if(!existing)Object.assign(draft,{status:'DRAFT',demo:false,destination:'',description:'',detailHtml:'',cover:null,gallery:[],variants:[{id:'standard',name:'Vé tiêu chuẩn',prices:{ADULT:0},timeSlots:[]}],rules:[],closures:[]});
 const [types,nodes,suppliers]=await Promise.all([api('product-types'),api('destinations'),api('suppliers')]);
 field('name','Tên sản phẩm','text',[],true,200);field('slug','Đường dẫn','text',[],true,160);field('status','Trạng thái','select',['DRAFT','PUBLISHED','ARCHIVED']);
 const typeOptions=[['','Chọn loại sản phẩm'],...types.data.filter(t=>t.status==='ACTIVE'||t.id===draft.type).map(t=>[t.id,t.name])];field('type','Loại sản phẩm','select',typeOptions,true);
 const destinationName=field('destination','Nhãn điểm đến','text',[],true,150),destinationSelect=field('destinationId','Điểm đến trong danh mục','select',[['','Không liên kết'],...nodes.data.filter(n=>n.status==='ACTIVE'||n.id===draft.destinationId).map(n=>[n.id,n.name])]);destinationSelect.oninput=()=>{dirty=true;draft.destinationId=destinationSelect.value;const selected=nodes.data.find(n=>n.id===destinationSelect.value);if(selected){draft.destination=selected.name;destinationName.value=selected.name;}};
 field('supplierId','Nhà cung cấp','select',[['','Không liên kết'],...suppliers.data.filter(s=>s.status==='ACTIVE'||s.id===draft.supplierId).map(s=>[s.id,s.name])]);field('demo','Sản phẩm minh họa','checkbox');
 field('description','Mô tả','textarea',[],false,10000);field('detailHtml','Nội dung chi tiết HTML','textarea',[],false,200000);field('seoTitle','Tiêu đề SEO','text',[],false,200);field('seoDescription','Mô tả SEO','textarea',[],false,500);
 section('Gói vé & giá');$('#fields').append(el('p','Bật nhóm khách áp dụng rồi nhập giá. 0đ là miễn phí; bỏ chọn là không áp dụng. Mỗi nhóm tối đa 50 khách, tổng tối đa 150 khách/đơn.','full muted'));
 const packages=el('div',undefined,'full product-packages');$('#fields').append(packages);
 function discountFields(path,parent){
  const config=get(draft,path),type=field('_discountType_'+path,'Ưu đãi online','select',[['INHERIT',path==='onlineDiscount'?'Không thiết lập':'Dùng ưu đãi chung'],['NONE','Không giảm'],['PERCENT','Giảm phần trăm'],['AMOUNT','Giảm số tiền']],false,undefined,parent);type.value=config?.type||'INHERIT';
  const value=field('_discountValue_'+path,'Giá trị ưu đãi','number',[],false,undefined,parent);value.min='0';value.max='100000000';value.value=config?.value??0;
  const state=()=>{value.disabled=['INHERIT','NONE'].includes(type.value);value.max=type.value==='PERCENT'?'100':'100000000';value.min=type.value==='PERCENT'||type.value==='AMOUNT'?'1':'0';};state();
  const sync=()=>{dirty=true;state();if(type.value==='INHERIT'){const keys=path.split('.');const last=keys.pop();const obj=keys.length?get(draft,keys.join('.')):draft;delete obj[last];}else set(draft,path,{type:type.value,value:type.value==='NONE'?0:Number(value.value)});};type.oninput=sync;value.oninput=sync;
 }
 function draw(){
  packages.replaceChildren();draft.variants.forEach((variant,index)=>{
   const card=el('section',undefined,'package-editor');card.append(el('h3','Gói '+(index+1)));const grid=el('div',undefined,'fields');card.append(grid);
   field(`variants.${index}.name`,'Tên gói','text',[],true,100,grid);field(`variants.${index}.id`,'Mã gói','text',[],true,80,grid);
   for(const [group,label]of Object.entries(guestLabels)){
    const block=el('div');const enableLabel=el('label',label),enable=el('input');enable.type='checkbox';enable.checked=Object.hasOwn(variant.prices,group);enableLabel.prepend(enable);block.append(enableLabel);
    const price=field(`variants.${index}.prices.${group}`,'Giá (VND)','number',[],false,undefined,block);price.min='0';price.max='100000000';price.step='1';price.disabled=!enable.checked;
    const condition=field(`variants.${index}.conditions.${group}`,'Điều kiện áp dụng','text',[],false,300,block);condition.disabled=!enable.checked;
    enable.onchange=()=>{dirty=true;price.disabled=!enable.checked;condition.disabled=!enable.checked;if(enable.checked){variant.prices[group]=Number(price.value||0);price.value=variant.prices[group];}else{delete variant.prices[group];if(variant.conditions)delete variant.conditions[group];}};grid.append(block);
   }
   discountFields(`variants.${index}.onlineDiscount`,grid);
   const slots=el('div',undefined,'full');grid.append(slots);
   function drawSlots(){slots.replaceChildren();(variant.timeSlots||[]).forEach((slot,i)=>{const row=el('div',undefined,'fields slot-editor');field(`variants.${index}.timeSlots.${i}.id`,'Mã suất','text',[],true,80,row);field(`variants.${index}.timeSlots.${i}.start`,'Giờ bắt đầu','time',[],true,undefined,row);const end=field(`variants.${index}.timeSlots.${i}.end`,'Giờ kết thúc (tùy chọn)','time',[],false,undefined,row);end.oninput=()=>{dirty=true;if(end.value)slot.end=end.value;else delete slot.end;};row.append(button('Bỏ suất',()=>{variant.timeSlots.splice(i,1);dirty=true;drawSlots();},'secondary'));slots.append(row);});slots.append(button('+ Thêm suất giờ',()=>{variant.timeSlots||=[];if(variant.timeSlots.length>=30)return;variant.timeSlots.push({id:crypto.randomUUID(),start:'09:00'});dirty=true;drawSlots();},'secondary'));}drawSlots();
   card.append(button('Bỏ gói này',()=>{if(draft.variants.length===1){notice('Sản phẩm cần ít nhất một gói vé.',true);return;}if(!confirm('Bỏ gói này? Các lịch tham chiếu gói cũng cần được cập nhật.'))return;draft.variants.splice(index,1);dirty=true;draw();},'quiet'));packages.append(card);
  });
  packages.append(button('+ Thêm gói vé',()=>{if(draft.variants.length>=30)return;draft.variants.push({id:crypto.randomUUID(),name:'Gói mới',prices:{ADULT:0},timeSlots:[]});dirty=true;draw();},'secondary'));
 }
 draw();section('Ưu đãi chung');discountFields('onlineDiscount',$('#fields'));
 for(const key of ['rules','closures']){
  section(key==='rules'?'Lịch giá theo ngày':'Khóa bán theo ngày');const container=el('div',undefined,'full');$('#fields').append(container);draft[key]||=[];
  function drawSchedule(){container.replaceChildren();draft[key].forEach((item,i)=>{
   const row=el('div',undefined,'fields package-editor');const variants=[...(key==='closures'?[['','Mọi gói']]:[]),...draft.variants.map(v=>[v.id,v.name])];const select=field(`${key}.${i}.variantId`,'Gói vé','select',variants,key==='rules',undefined,row);select.oninput=()=>{dirty=true;if(select.value)item.variantId=select.value;else delete item.variantId;};
   field(`${key}.${i}.startDate`,'Từ ngày','date',[],true,undefined,row);field(`${key}.${i}.endDate`,'Đến ngày','date',[],true,undefined,row);
   if(key==='rules'){field(`${key}.${i}.customerType`,'Nhóm khách','select',Object.entries(guestLabels),true,undefined,row);const amount=field(`${key}.${i}.amount`,'Giá áp dụng (VND)','number',[],true,undefined,row);amount.min='0';amount.max='100000000';const priority=field(`${key}.${i}.priority`,'Độ ưu tiên (số lớn ưu tiên hơn)','number',[],true,undefined,row);priority.min='0';priority.max='10000';}
   else field(`${key}.${i}.reason`,'Lý do nội bộ','text',[],false,200,row);
   const days=el('div',undefined,'full weekday-options');days.append(el('small','Áp dụng thứ: bỏ trống để áp dụng mọi ngày.'));for(let day=1;day<=7;day++){const label=el('label',day===7?'Chủ nhật':'Thứ '+(day+1)),check=el('input');check.type='checkbox';check.checked=item.weekdays?.includes(day)||false;check.onchange=()=>{dirty=true;const active=Array.from(days.querySelectorAll('input')).flatMap((c,n)=>c.checked?[n+1]:[]);if(active.length)item.weekdays=active;else delete item.weekdays;};label.prepend(check);days.append(label);}row.append(days,button('Bỏ lịch',()=>{draft[key].splice(i,1);dirty=true;drawSchedule();},'secondary'));container.append(row);
  });container.append(button(key==='rules'?'+ Thêm lịch giá':'+ Thêm khoảng khóa bán',()=>{if(!draft.variants.length)return;const common={productId:draft.id,startDate:'',endDate:''};draft[key].push(key==='rules'?{...common,id:crypto.randomUUID(),variantId:draft.variants[0].id,customerType:Object.keys(draft.variants[0].prices)[0]||'ADULT',priority:10,amount:0}:common);dirty=true;drawSchedule();},'secondary'));}drawSchedule();
 }
 if(draft.english){section('English hiện có');$('#fields').append(el('p','Bản dịch được giữ khi lưu. Đổi nội dung gốc sẽ yêu cầu duyệt lại trước khi hiển thị English.','full muted'));}
}
