'use strict';
let salesReportRange=null,salesReportPage=1;
async function renderSalesReport(screen,serial){
 if(!salesReportRange){
  const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Ho_Chi_Minh',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  salesReportRange={from:today.slice(0,8)+'01',to:today,channel:'ALL',counterId:''};
 }
 const filters={...salesReportRange};screen.append(el('p','Đang tải báo cáo…'));
 try{
  const counters=(await api('counters')).data;if(serial!==loading||$('#workspace').hidden)return;
  screen.replaceChildren();const form=el('form',undefined,'toolbar'),inputs={};
  for(const [key,label]of [['from','Từ ngày'],['to','Đến ngày']]){
   const box=el('label',label),input=el('input');input.type='date';input.required=true;input.value=filters[key];box.append(input);form.append(box);inputs[key]=input;
  }
  const channelLabel=el('label','Kênh bán báo cáo'),channel=el('select');
  for(const [value,title]of [['ALL','Website, POS và CTV'],['WEB','Website'],['POS','Tại quầy POS'],['CTV','CTV']]){const o=el('option',title);o.value=value;channel.append(o);}
  channel.value=filters.channel;channelLabel.append(channel);
  const counterLabel=el('label','Quầy báo cáo'),counter=el('select'),all=el('option','Tất cả quầy');all.value='';counter.append(all);
  for(const c of counters){const o=el('option',c.name+' · '+c.id+(c.status==='ARCHIVED'?' · lưu trữ':''));o.value=c.id;counter.append(o);}
  counter.value=filters.counterId;counter.disabled=['WEB','CTV'].includes(channel.value);counterLabel.append(counter);
  channel.onchange=()=>{counter.disabled=['WEB','CTV'].includes(channel.value);if(counter.disabled)counter.value='';};
  counter.onchange=()=>{if(counter.value)channel.value='POS';};
  const submit=el('button','Xem báo cáo','primary');submit.type='submit';form.append(channelLabel,counterLabel,submit);
  form.onsubmit=e=>{e.preventDefault();salesReportRange={from:inputs.from.value,to:inputs.to.value,channel:channel.value,counterId:counter.value};salesReportPage=1;render();};
  screen.append(form,el('p','Giờ Việt Nam · tối đa 367 ngày. Giá trị đơn theo ngày tạo và trạng thái hiện tại; tiền đã thu theo ngày ghi nhận, kể cả đơn tạo trước kỳ. Giữ lịch sử đơn và quầy lưu trữ.','muted'));
  const report=await api('reports?'+new URLSearchParams(filters));if(serial!==loading||$('#workspace').hidden)return;
  const applied=report.channel==='ALL'?'Website, POS và CTV':report.channel==='WEB'?'Website':report.channel==='CTV'?'CTV':'Tại quầy POS';
  screen.append(el('p','Đang xem: '+applied+(report.counterId?' · Quầy '+report.counterId:'')+' · '+report.from+' → '+report.to));
  const cards=el('div',undefined,'cards');
  for(const [key,label,money]of [['orders','Đơn chưa hủy',false],['cancelled','Đơn đã hủy',false],['bookingValue','Giá trị đơn tạo trong kỳ',true],['unpaidBookingValue','Giá trị đơn hiện chưa thu',true],['collected','Tiền đã thu trong kỳ',true],['cash','Thu tiền mặt',true],['bank','Thu chuyển khoản',true]]){
   const card=el('section',undefined,'card');card.append(el('p',label),el('h3',money?orderMoney(report.totals[key]):String(report.totals[key])));cards.append(card);
  }
  screen.append(cards,el('p','Tiền đã thu không phải lợi nhuận. Không cộng tiền bổ sung/rút chi ca vào doanh thu. Chưa gồm hoàn tiền hoặc khoản trả nợ CTV. Khoản trả nợ và dư nợ xem tại Sổ công nợ CTV.','muted'));
  const bar=toolbar('CSV gồm tổng kỳ, từng ngày và từng kênh/quầy theo bộ lọc đang xem.'),csv=button('Tải CSV toàn kỳ',()=>downloadSalesReport(csv,filters));bar.append(csv);screen.append(bar);
  screen.append(el('h2','Theo kênh và quầy'));
  if(!report.channels.length)screen.append(el('p','Không có phát sinh trong bộ lọc này.','muted'));
  else{
   const wrap=el('div',undefined,'table-wrap'),table=el('table'),head=el('tr'),thead=el('thead'),body=el('tbody');
   for(const title of ['Kênh / Quầy','Đơn chưa hủy','Đơn hủy','Giá trị đơn','Hiện chưa thu','Đã thu','Tiền mặt','Chuyển khoản'])head.append(el('th',title));thead.append(head);table.append(thead);
   for(const group of report.channels){const tr=el('tr'),who=el('td');who.append(el('strong',group.name),el('small',group.channel+(group.counterId?' · '+group.counterId:'')));tr.append(who);for(const key of ['orders','cancelled','bookingValue','unpaidBookingValue','collected','cash','bank'])tr.append(el('td',['orders','cancelled'].includes(key)?String(group[key]):orderMoney(group[key])));body.append(tr);}
   table.append(body);wrap.append(table);screen.append(wrap);
  }
  screen.append(el('h2','Theo ngày'));const rows=el('div');screen.append(rows);
  function draw(){
   rows.replaceChildren();const pages=Math.max(1,Math.ceil(report.daily.length/31));salesReportPage=Math.min(salesReportPage,pages);
   const wrap=el('div',undefined,'table-wrap'),table=el('table'),head=el('tr'),thead=el('thead'),body=el('tbody');
   for(const title of ['Ngày','Đơn chưa hủy','Đơn hủy','Giá trị đơn','Hiện chưa thu','Đã thu','Tiền mặt','Chuyển khoản'])head.append(el('th',title));thead.append(head);table.append(thead);
   for(const day of report.daily.slice((salesReportPage-1)*31,salesReportPage*31)){const tr=el('tr');for(const key of ['date','orders','cancelled','bookingValue','unpaidBookingValue','collected','cash','bank'])tr.append(el('td',['date','orders','cancelled'].includes(key)?String(day[key]):orderMoney(day[key])));body.append(tr);}
   table.append(body);wrap.append(table);rows.append(wrap);
   const pager=toolbar('Trang '+salesReportPage+' / '+pages+' · 31 ngày/trang');
   for(const [label,delta]of [['← Trước',-1],['Sau →',1]]){const b=button(label,()=>{salesReportPage+=delta;draw();});b.disabled=delta<0?salesReportPage<=1:salesReportPage>=pages;pager.append(b);}rows.append(pager);
  }
  draw();
 }catch(error){if(serial===loading){screen.append(el('p','Chưa tải được báo cáo.','error'));notice(error.message,true);}}
}
async function downloadSalesReport(button,filters){
 button.disabled=true;
 try{
  const headers={'X-Jingo-Admin':'1'};if(document.body.dataset.entry)headers['X-Jingo-Admin-Entry']=document.body.dataset.entry;
  const response=await fetch('/api/v1/admin/reports/export?'+new URLSearchParams(filters),{credentials:'same-origin',cache:'no-store',headers});
  if(!response.ok){const error=await response.json().catch(()=>({}));if(response.status===401)showLogin();throw new Error(error.message||'Không tải được báo cáo.');}
  const url=URL.createObjectURL(await response.blob()),link=el('a');link.href=url;link.download='jinmemo-ban-hang-'+filters.channel+'-'+filters.from+'-'+filters.to+'.csv';document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
 }catch(error){notice(error.message,true);}finally{button.disabled=false;}
}
