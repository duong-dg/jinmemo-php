'use strict';
const $=s=>document.querySelector(s);
const names={posts:'Bài viết',orders:'Đơn hàng',products:'Sản phẩm',inventory:'Tồn vé',vouchers:'Mã giảm giá','product-types':'Loại sản phẩm',destinations:'Điểm đến',media:'Thư viện ảnh',suppliers:'Nhà cung cấp',pages:'Trang nội dung','contact-requests':'Yêu cầu liên hệ','site-seo':'SEO website',brand:'Thương hiệu'};
const labels={ACTIVE:'Hoạt động',ARCHIVED:'Lưu trữ',DRAFT:'Bản nháp',PUBLISHED:'Đã xuất bản',TRASH:'Thùng rác',NEW:'Mới',DONE:'Đã xử lý',TICKET:'Vé',TOUR:'Tour',PAGE:'Trang thường',CONTACT:'Liên hệ'};
let current='overview',records=[],draft=null,dirty=false,loading=0,contactStatus='NEW',editing=false,editorSection='';
function el(tag,text,cls){const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;}
function button(text,action,cls='quiet'){const n=el('button',text,cls);n.type='button';n.addEventListener('click',action);return n;}
function notice(message,error=false){const n=$('#notice');n.textContent=message;n.className=error?'error':'';n.hidden=!message;}
async function api(path,body,extra={}){
 const headers={'X-Jingo-Admin':'1',...extra};if(document.body.dataset.entry)headers['X-Jingo-Admin-Entry']=document.body.dataset.entry;
 const options={method:body===undefined?'GET':'POST',credentials:'same-origin',cache:'no-store',headers};
 if(body!==undefined){if(body instanceof FormData)options.body=body;else{headers['Content-Type']='application/json';options.body=JSON.stringify(body);}}
 const response=await fetch('/api/v1/admin/'+path,options);const data=await response.json().catch(()=>({message:'Máy chủ chưa trả dữ liệu hợp lệ.'}));
 if(!response.ok){if(response.status===401&&path!=='login'){showLogin();}throw new Error(data.message||'Không thực hiện được thao tác.');}return data;
}
function showLogin(){loading++;if($('#editor').open)$('#editor').close();$('#screen').replaceChildren();records=[];draft=null;dirty=false;$('#workspace').hidden=true;$('#starting').hidden=true;$('#login').hidden=false;$('#login-form input').focus();}
function showWorkspace(email){$('#login').hidden=true;$('#starting').hidden=true;$('#workspace').hidden=false;$('#account-email').textContent=email;render();}
$('#toggle-password').onclick=()=>{const input=$('#login-form [name=password]');input.type=input.type==='password'?'text':'password';$('#toggle-password').textContent=input.type==='password'?'Hiện':'Ẩn';$('#toggle-password').setAttribute('aria-label',input.type==='password'?'Hiện mật khẩu':'Ẩn mật khẩu');};
$('#login-form').onsubmit=async e=>{e.preventDefault();const submit=e.submitter;submit.disabled=true;$('#login-error').hidden=true;try{const data=new FormData(e.target);const result=await api('login',{email:data.get('email'),password:data.get('password')});e.target.reset();showWorkspace(result.email);}catch(error){$('#login-error').textContent=error.message;$('#login-error').hidden=false;}finally{submit.disabled=false;}};
$('#logout').onclick=async()=>{if(dirty&&!confirm('Bỏ thay đổi chưa lưu và đăng xuất?'))return;$('#logout').disabled=true;try{await api('logout',{});draft=null;dirty=false;showLogin();}catch(error){notice(error.message,true);}finally{$('#logout').disabled=false;}};
function closeEditor(){if(editing)return;if(dirty&&!confirm('Bỏ thay đổi chưa lưu?'))return;$('#editor').close();draft=null;dirty=false;}
$('#close-editor').onclick=closeEditor;$('#cancel-editor').onclick=closeEditor;$('#editor').addEventListener('cancel',e=>{e.preventDefault();closeEditor();});
window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
window.addEventListener('hashchange',()=>{if(!$('#workspace').hidden)render();});
function toolbar(text){const bar=el('div',undefined,'toolbar');bar.append(el('p',text));return bar;}
function empty(text){const box=el('div',undefined,'empty');box.append(el('h3',text),el('p','Dữ liệu mới sẽ xuất hiện tại đây.'));return box;}
async function render(){
 const id=++loading;current=location.hash.slice(1)||'overview';if(!names[current])current='overview';notice('');$('#page-title').textContent=names[current]||'Tổng quan';document.querySelectorAll('#nav a').forEach(a=>a.classList.toggle('active',a.hash==='#'+current));const screen=$('#screen');screen.replaceChildren();
 if(current==='overview'){
  const intro=el('div',undefined,'overview-intro');intro.append(el('h2','Một nơi để chăm chút nội dung.'),el('p','Các danh mục, trang nội dung và hình ảnh đã sẵn sàng để quản lý. Đơn WEB đã có thể đặt và quản lý. Có ghi nhận thu tiền thủ công. Thanh toán trực tuyến, giao vé, quầy và cộng tác viên sẽ được bổ sung trong các đợt chuyển tiếp.'));screen.append(intro);
  const cards=el('div',undefined,'cards');const descriptions={'product-types':'Phân nhóm vé và tour của bạn.',destinations:'Sắp xếp địa điểm theo khu vực.',media:'Lưu và chọn hình ảnh cho nội dung.',suppliers:'Quản lý thông tin các đối tác.',pages:'Biên tập giới thiệu, chính sách, liên hệ.','contact-requests':'Theo dõi và xử lý lời nhắn của khách.'};
  descriptions.posts='Cẩm nang, câu chuyện và lịch xuất bản.';descriptions.orders='Theo dõi đơn, liên hệ và xử lý yêu cầu.';descriptions.products='Vé, gói giá và lịch sử dụng.';descriptions.inventory='Sức chứa theo ngày và suất giờ.';descriptions.vouchers='Chương trình giảm giá và phạm vi áp dụng.';descriptions['site-seo']='Tiêu đề, mô tả và ảnh chia sẻ Việt/Anh.';descriptions.brand='Tên, logo và thông tin công ty.';
  for(const [key,title]of Object.entries(names)){const card=el('a',undefined,'card');card.href='#'+key;card.append(el('h3',title),el('p',descriptions[key]),el('span','Mở quản lý ↗'));cards.append(card);}screen.append(cards);return;
 }
 if(current==='orders'){await renderOrders(screen,id);return;}
 if(current==='inventory'){await renderInventory(screen,id);return;}
 screen.append(el('p','Đang tải…','muted'));
 try{
  const route=current==='contact-requests'?current+'?status='+contactStatus:current;const result=await api(route);if(id!==loading||$('#workspace').hidden)return;screen.replaceChildren();
  if(['site-seo','brand'].includes(current)){renderSettings(screen,result);return;}records=result.data;
  if(current==='media'){renderMedia(screen);return;}
  const bar=toolbar(current==='contact-requests'?`${records.length} yêu cầu trong danh sách`:`${records.length} mục`);
  if(current==='contact-requests'){const select=el('select');select.setAttribute('aria-label','Trạng thái yêu cầu');for(const value of ['NEW','DONE','TRASH']){const o=el('option',labels[value]);o.value=value;select.append(o);}select.value=contactStatus;select.onchange=()=>{contactStatus=select.value;render();};bar.append(select);}
  else {bar.append(button('+ Tạo mới',()=>openEditor(null),'primary'));if(current==='pages'&&!records.some(r=>r.kind==='CONTACT'))bar.append(button('Tạo trang Liên hệ',()=>openEditor(null,true),'secondary'));}screen.append(bar);
  if(!records.length){screen.append(empty(current==='contact-requests'?'Chưa có yêu cầu liên hệ':'Chưa có nội dung'));return;}
  const wrap=el('div',undefined,'table-wrap'),table=el('table'),head=el('thead'),tr=el('tr');for(const title of [current==='contact-requests'?'Khách liên hệ':'Tên','Thông tin','Trạng thái',''])tr.append(el('th',title));head.append(tr);table.append(head);const body=el('tbody');
  records.forEach(record=>{const row=el('tr');const title=el('td');title.append(el('strong',record.name||record.title||record.data?.name||''));
   if(current==='contact-requests')title.append(el('small',record.data.email));
   if(current==='vouchers')title.append(el('small',record.code));
   const info=current==='vouchers'?`${record.value}${record.discountType==='PERCENT'?'%':' VND'} · ${result.usage?.[record.id]||0} lượt đã dùng`:current==='suppliers'?`${record.productCount} sản phẩm · ${record.phone||record.email||'Chưa có liên hệ'}`:current==='contact-requests'?record.data.subject:record.slug;
   row.append(title,el('td',info||''));const state=el('td');state.append(el('span',current==='vouchers'?(record.enabled?'Đang bật':'Đang tắt'):labels[record.status]||record.status,'tag'));row.append(state);const action=el('td');action.append(button(current==='contact-requests'?'Xem yêu cầu ↗':'Chỉnh sửa ↗',()=>current==='contact-requests'?openContact(record):openEditor(record)));if(['posts','pages'].includes(current)&&record.status==='PUBLISHED'){const view=el('a',current==='posts'?'Xem bài ↗':'Xem trang ↗','quiet');view.href=current==='posts'?'/cam-nang/'+encodeURIComponent(record.slug):record.kind==='CONTACT'?'/lien-he':'/trang/'+encodeURIComponent(record.slug);view.target='_blank';view.rel='noopener';action.append(document.createTextNode(' · '),view);}row.append(action);body.append(row);
  });table.append(body);wrap.append(table);screen.append(wrap);
 }catch(error){if(id!==loading)return;screen.replaceChildren(empty('Chưa tải được dữ liệu'));notice(error.message,true);}
}
function get(obj,path){return path.split('.').reduce((o,k)=>o?.[k],obj);}
function set(obj,path,value){const keys=path.split('.');let node=obj;for(const key of keys.slice(0,-1)){if(!node[key])node[key]={};node=node[key];}node[keys.at(-1)]=value;}
function field(path,label,type='text',options=[],required=false,max,parent=$('#fields')){
 const wrapper=el('label',label);if(type==='textarea'||path.includes('contentHtml'))wrapper.className='full';let input;
 if(type==='select'){input=el('select');for(const option of options){const [value,title]=Array.isArray(option)?option:[option,labels[option]||option];const o=el('option',title);o.value=value;input.append(o);}}
 else if(type==='textarea'){input=el('textarea');input.rows=path.includes('contentHtml')?8:3;}
 else {input=el('input');input.type=type;}
 input.name=path;input.required=required;if(max)input.maxLength=max;
 if(type==='checkbox')input.checked=!!get(draft,path);else input.value=get(draft,path)??(type==='select'?(Array.isArray(options[0])?options[0][0]:options[0]??''):'');
 input.oninput=()=>{dirty=true;set(draft,path,type==='checkbox'?input.checked:type==='number'?(input.value===''?null:Number(input.value)):input.value);};
 wrapper.append(input);parent.append(wrapper);return input;
}
function section(title){$('#fields').append(el('h3',title));}
function baseFields(){field('name','Tên','text',[],true,150);field('slug','Đường dẫn','text',[],true,160);field('status','Trạng thái','select',['ACTIVE','ARCHIVED']);}
async function openEditor(record,createContact=false){
 editorSection=current;
 draft=record?structuredClone(record):{id:crypto.randomUUID(),version:0,status:'ACTIVE'};delete draft.productCount;delete draft.updatedAt;dirty=false;$('#fields').replaceChildren();$('#edit-error').hidden=true;$('#editor-title').textContent=(record?'Chỉnh sửa · ':'Tạo mới · ')+names[current];$('#edit-form button[type=submit]').hidden=false;
 if(current==='product-types'){baseFields();field('bookingKind','Cách đặt','select',['TICKET','TOUR']);if(!draft.bookingKind)draft.bookingKind='TICKET';field('description','Mô tả','textarea',[],false,1000);if(draft.english){section('English hiện có');$('#fields').append(el('p','Bản dịch được giữ lại khi lưu. Thay đổi nội dung gốc có thể yêu cầu duyệt lại bản dịch.','full muted'));}}
 if(current==='posts'){try{await buildPostFields(!!record);}catch(error){draft=null;notice(error.message,true);return;}}
 if(current==='products'){try{await buildProductFields(!!record);}catch(error){draft=null;notice(error.message,true);return;}}
 if(current==='vouchers'){try{await buildVoucherFields(!!record);}catch(error){draft=null;notice(error.message,true);return;}}
 if(current==='suppliers'){field('name','Tên nhà cung cấp','text',[],true,150);field('status','Trạng thái','select',['ACTIVE','ARCHIVED']);field('contactName','Người liên hệ','text',[],false,100);field('phone','Điện thoại','tel',[],false,30);field('email','Email','email',[],false,200);field('website','Website','url',[],false,500);field('notes','Ghi chú','textarea',[],false,4000);}
 if(current==='site-seo'){for(const lang of ['vi','en']){section(lang==='vi'?'Tiếng Việt':'English');field(lang+'.title','Tiêu đề SEO '+(lang==='vi'?'Việt':'English'),'text',[],true,200);field(lang+'.description','Mô tả '+(lang==='vi'?'Việt':'English'),'textarea',[],true,500);}}
 if(current==='brand'){field('name','Tên thương hiệu','text',[],true,100);field('companyName','Tên công ty','text',[],false,200);field('email','Email công khai','email',[],false,200);field('phone','Điện thoại','tel',[],false,40);field('address','Địa chỉ','textarea',[],false,500);}
 if(current==='destinations'){
  baseFields();field('parentId','Điểm đến cha','select',[['','Không có cấp cha'],...records.filter(r=>r.id!==draft.id).map(r=>[r.id,r.name])]);field('sortOrder','Thứ tự','number');if(draft.sortOrder===undefined)draft.sortOrder=0;
  field('description','Mô tả','textarea',[],false,2000);field('englishName','Tên English','text',[],false,150);field('englishSlug','Đường dẫn English','text',[],false,160);field('englishDescription','Mô tả English','textarea',[],false,2000);
  field('_lat','Vĩ độ','number').step='any';field('_lon','Kinh độ','number').step='any';$('#fields [name=_lat]').value=draft.coordinates?.lat??'';$('#fields [name=_lon]').value=draft.coordinates?.lon??'';
 }
 if(current==='pages'){
  if(!record)Object.assign(draft,{status:'DRAFT',kind:createContact?'CONTACT':'PAGE',...(createContact?{id:'00000000-0000-4000-8000-000000000001',slug:'lien-he'}:{}),showInMenu:false,showInFooter:false,sortOrder:0,cover:null,contact:null,english:null});
  field('title','Tiêu đề','text',[],true,180);field('slug','Đường dẫn','text',[],true,160);field('status','Trạng thái','select',draft.kind==='CONTACT'?['DRAFT','PUBLISHED']:['DRAFT','PUBLISHED','TRASH']);field('sortOrder','Thứ tự','number');field('showInMenu','Hiện trên menu','checkbox');field('showInFooter','Hiện ở chân trang','checkbox');
  field('menuLabel','Tên trên menu','text',[],false,60);field('description','Mô tả','textarea',[],false,1000);field('contentHtml','Nội dung HTML','textarea',[],true,200000);field('seoTitle','Tiêu đề SEO','text',[],false,200);field('seoDescription','Mô tả SEO','textarea',[],false,500);
  if(draft.kind==='CONTACT'){section('Thông tin liên hệ');for(const [key,label,type]of [['phone','Điện thoại','tel'],['email','Email','email'],['address','Địa chỉ','text'],['hours','Giờ làm việc','text'],['mapUrl','Liên kết bản đồ HTTPS','url']])field('contact.'+key,label,type);}
  section('English');field('_english','Có bản English','checkbox').checked=!!draft.english;
  for(const [key,label,type,max]of [['title','Tiêu đề English','text',180],['slug','Đường dẫn English','text',160],['contentHtml','Nội dung HTML English','textarea',200000],['description','Mô tả English','textarea',1000],['menuLabel','Tên menu English','text',60],['seoTitle','Tiêu đề SEO English','text',200],['seoDescription','Mô tả SEO English','textarea',500]])field('_en.'+key,label,type,[],false,max).value=draft.english?.[key]??'';
  field('_en.published','Xuất bản English','checkbox').checked=!!draft.english?.published;
 }
 if(['posts','products','destinations','pages','site-seo','brand'].includes(current)){
  try{const media=(await api('media')).data;const selected=current==='site-seo'?draft.image:current==='brand'?draft.logo:draft.cover;const options=[['','Không có ảnh'],...media.map(m=>[m.id,m.alt||m.filename])];if(selected&&!media.some(m=>m.id===selected.id))options.push([selected.id,selected.alt||'Ảnh hiện tại']);field('_cover',current==='brand'?'Logo':current==='site-seo'?'Ảnh chia sẻ':'Ảnh bìa','select',options).value=selected?.id??'';field('_coverAlt','Mô tả ảnh','text',[],false,300).value=selected?.alt??'';
   if(current==='products'){const existing=draft.gallery||[],options=media.map(m=>[m.id,m.alt||m.filename]);for(const image of existing)if(!media.some(m=>m.id===image.id))options.push([image.id,image.alt||'Ảnh hiện có']);const gallery=field('_gallery','Ảnh bổ sung (tối đa 20 ảnh)','select',options);gallery.multiple=true;for(const option of gallery.options)option.selected=existing.some(image=>image.id===option.value);gallery.oninput=()=>{dirty=true;draft.gallery=Array.from(gallery.selectedOptions).map(o=>({id:o.value,alt:existing.find(i=>i.id===o.value)?.alt||media.find(i=>i.id===o.value)?.alt||''}));};}
  }catch(error){notice('Không tải được thư viện ảnh: '+error.message,true);}
 }
 if($('#workspace').hidden){draft=null;return;}$('#editor').showModal();$('#editor input')?.focus();dirty=false;
}
$('#edit-form').onsubmit=async e=>{
 e.preventDefault();if(!draft||editing)return;const submit=e.submitter;editing=true;submit.disabled=true;$('#edit-error').hidden=true;
 try{
  if(current!==editorSection)throw new Error('Mục quản trị đã thay đổi. Đóng biểu mẫu và mở lại trước khi lưu.');
  const data=structuredClone(draft),form=new FormData(e.target);
  if(current==='destinations'){data.parentId=form.get('parentId')||null;data.sortOrder=Number(form.get('sortOrder')||0);const lat=form.get('_lat'),lon=form.get('_lon');if((lat==='')!==(lon===''))throw new Error('Nhập cả vĩ độ và kinh độ, hoặc để trống cả hai.');data.coordinates=lat===''?null:{lat:Number(lat),lon:Number(lon)};}
  if(['posts','products','destinations','pages','site-seo','brand'].includes(current)&&form.has('_cover'))data[current==='site-seo'?'image':current==='brand'?'logo':'cover']=form.get('_cover')?{id:form.get('_cover'),alt:form.get('_coverAlt')||''}:null;
  if(current==='products'){for(const key of ['destinationId','supplierId'])if(!data[key])delete data[key];if(!data.destination)data.destination=$('#fields [name=destinationId]')?.selectedOptions[0]?.textContent||'';}
  if(current==='pages'){
   data.sortOrder=Number(form.get('sortOrder')||0);data.showInMenu=form.has('showInMenu');data.showInFooter=form.has('showInFooter');data.english=null;
   if(data.kind==='CONTACT'){data.contact={};for(const key of ['phone','email','address','hours','mapUrl'])data.contact[key]=form.get('contact.'+key)||'';}
   if(form.has('_english')){data.english={};for(const key of ['title','slug','contentHtml','description','menuLabel','seoTitle','seoDescription'])data.english[key]=form.get('_en.'+key)||'';data.english.published=form.has('_en.published');}
  }
  for(const key of Object.keys(data))if(key.startsWith('_'))delete data[key];
  await api(current,data);if(current==='brand')refreshBrand(data);dirty=false;$('#editor').close();draft=null;await render();notice('Đã lưu thay đổi.');
 }catch(error){$('#edit-error').textContent=error.message;$('#edit-error').hidden=false;}finally{editing=false;submit.disabled=false;}
};
function openContact(record){
 draft=null;dirty=false;$('#fields').replaceChildren();$('#edit-error').hidden=true;$('#editor-title').textContent=record.data.subject;$('#edit-form button[type=submit]').hidden=true;
 const details=el('dl',undefined,'full details');for(const [key,label]of [['name','Họ tên'],['email','Email'],['phone','Điện thoại']])details.append(el('dt',label),el('dd',record.data[key]||'—'));details.append(el('dt','Nội dung'),el('dd',record.data.message,'message'));$('#fields').append(details);
 const actions=el('div',undefined,'full row-actions');for(const status of ['NEW','DONE','TRASH'])if(status!==record.status)actions.append(button(labels[status],async()=>{if(!confirm('Chuyển yêu cầu sang “'+labels[status]+'”?'))return;try{await api('contact-requests/'+record.id,{status});$('#editor').close();await render();notice('Đã cập nhật yêu cầu.');}catch(error){$('#edit-error').textContent=error.message;$('#edit-error').hidden=false;}},'secondary'));$('#fields').append(actions);$('#editor').showModal();
}
function renderMedia(screen){
 const form=el('form',undefined,'upload');const fileLabel=el('label','Chọn ảnh (JPG, PNG, WebP · tối đa 5 MB)'),file=el('input');file.type='file';file.name='file';file.accept='image/jpeg,image/png,image/webp';file.required=true;fileLabel.append(file);const altLabel=el('label','Mô tả ảnh'),alt=el('input');alt.name='alt';alt.maxLength=300;altLabel.append(alt);const submit=el('button','Tải ảnh lên','primary');submit.type='submit';form.append(fileLabel,altLabel,submit);
 form.onsubmit=async e=>{e.preventDefault();submit.disabled=true;try{await api('media',new FormData(form));await render();notice('Đã thêm ảnh vào thư viện.');}catch(error){notice(error.message,true);}finally{submit.disabled=false;}};screen.append(form);
 if(!records.length){screen.append(empty('Thư viện chưa có ảnh'));return;}const grid=el('div',undefined,'media-grid');for(const record of records){const card=el('article',undefined,'media-card'),image=el('img');image.src=record.url;image.alt=record.alt;image.loading='lazy';const meta=el('div',record.alt||record.filename);meta.append(el('small',`${record.width} × ${record.height} · ${Math.ceil(record.bytes/1024)} KB`));card.append(image,meta);grid.append(card);}screen.append(grid);
}
function refreshBrand(data){
 for(const mark of document.querySelectorAll('.wordmark')){const text=Array.from(mark.childNodes).find(n=>n.nodeType===Node.TEXT_NODE);if(text)text.textContent=data.name;mark.querySelector('.brand-logo')?.remove();if(data.logo){const logo=el('img',undefined,'brand-logo');logo.src='/api/v1/media/'+encodeURIComponent(data.logo.id);logo.alt='';mark.prepend(logo);}}
 document.title='Quản trị · '+data.name;
}
function renderSettings(screen,settings){
 const bar=toolbar(current==='brand'?'Thông tin thương hiệu dùng trên website.':'Nội dung tìm kiếm và chia sẻ cho trang chủ Việt/English.');bar.append(button('Chỉnh sửa',()=>openEditor(settings),'primary'));screen.append(bar);
 const box=el('div',undefined,'overview-intro');
 if(current==='brand'){box.append(el('h2',settings.name),el('p',settings.companyName||'Chưa có tên công ty'),el('p',[settings.email,settings.phone,settings.address].filter(Boolean).join(' · ')||'Chưa có thông tin liên hệ'));screen.append(box);screen.append(el('p','Thông tin này hiển thị công khai. Nội dung SEO, trang Liên hệ và email cần cập nhật tại từng mục tương ứng khi đổi thương hiệu.','muted'));}
 else {for(const lang of ['vi','en']){const card=el('article',undefined,'overview-intro');card.append(el('p',lang==='vi'?'TIẾNG VIỆT':'ENGLISH','eyebrow'),el('h2',settings[lang].title),el('p',settings[lang].description));screen.append(card);}screen.append(el('p','Website khách đang được chuyển tiếp. Cấu hình được lưu để sử dụng khi giao diện công khai sẵn sàng.','muted'));}
 const image=current==='brand'?settings.logo:settings.image;if(image){const img=el('img');img.src='/api/v1/media/'+encodeURIComponent(image.id);img.alt=image.alt;img.className='settings-image';screen.append(img);}
}
async function startAdmin(){try{const me=await api('me');showWorkspace(me.email);}catch(error){showLogin();if(error.message!=='Vui lòng đăng nhập.'&&error.message!=='Phiên đăng nhập đã hết hạn.'){$('#login-error').textContent=error.message;$('#login-error').hidden=false;}}}
if(document.readyState==='complete')startAdmin();else document.addEventListener('DOMContentLoaded',startAdmin,{once:true});
