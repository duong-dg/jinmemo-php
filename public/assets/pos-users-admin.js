'use strict';
const posPermissionLabels={SELL:'Tạo đơn bán vé',COLLECT:'Ghi nhận thu tiền',DELIVER:'Giao vé / file',PRINT:'In phiếu và vé',CANCEL:'Hủy đơn chưa thu',SHIFT:'Mở ca, thu/chi và chốt ca'};
async function renderPosUsers(screen,serial){
 screen.append(el('p','Đang tải nhân viên…'));
 try{
  const [users,counters]=await Promise.all([api('pos-users'),api('counters')]);if(serial!==loading||$('#workspace').hidden)return;
  screen.replaceChildren();const bar=toolbar(users.data.length+' nhân viên');bar.append(button('Tạo nhân viên',()=>openPosUser(null,counters.data),'primary'));const portalLink=el('a','Mở cổng nhân viên ↗','quiet');portalLink.href='/quay';portalLink.target='_blank';portalLink.rel='noopener noreferrer';bar.append(portalLink);screen.append(bar,el('p','Quản lý tài khoản và quyền quầy. Mỗi lần cập nhật sẽ đăng xuất mọi phiên nhân viên đó. Nhân viên đăng nhập tại /quay để tạo đơn, thu tiền hoặc hủy theo quyền. Nhân viên có SHIFT đã quản lý ca được; giao file và in phiếu đơn sẽ nối tiếp.','muted'));
  if(!users.data.length){screen.append(empty('Chưa có nhân viên quầy'));return;}
  const names=new Map(counters.data.map(c=>[c.id,c.name+(c.status==='ARCHIVED'?' · lưu trữ':'')])),wrap=el('div',undefined,'table-wrap'),table=el('table'),thead=el('thead'),head=el('tr'),body=el('tbody');
  for(const title of ['Nhân viên','Quầy được gán','Quyền','Trạng thái',''])head.append(el('th',title));thead.append(head);table.append(thead);
  for(const user of users.data){const row=el('tr'),who=el('td'),actions=el('td');who.append(el('strong',user.name),el('small',user.email));actions.append(button('Chỉnh sửa',()=>openPosUser(user,counters.data)));row.append(who,el('td',user.counterIds.map(id=>names.get(id)||id).join(', ')||'Chưa gán quầy'),el('td',user.permissions.map(p=>posPermissionLabels[p]||p).join(', ')||'Chưa có quyền'),el('td',user.status==='ACTIVE'?'Hoạt động':'Đã khóa'),actions);body.append(row);}
  table.append(body);wrap.append(table);screen.append(wrap);
 }catch(error){if(serial===loading)notice(error.message,true);}
}
function openPosUser(user,counters){
 const dialog=shiftDialog(user?'Chỉnh sửa nhân viên quầy':'Tạo nhân viên quầy'),box=el('div',undefined,'full'),inputs=[],confirmation=el('div',undefined,'toolbar'),status=el('p');let pending=null;
 const id=user?.id||crypto.randomUUID();box.append(el('p','Mã nhân viên: '+id,'muted'));
 function input(title,type='text',max=100){const label=el('label',title),n=el('input');n.type=type;n.maxLength=max;n.oninput=()=>{dirty=true;};label.append(n);box.append(label);inputs.push(n);return n;}
 const name=input('Tên nhân viên'),email=input('Email nhân viên','email',200),password=input(user?'Mật khẩu mới (để trống để giữ nguyên)':'Mật khẩu nhân viên','password',200);password.autocomplete='new-password';password.minLength=12;
 const stateLabel=el('label','Trạng thái nhân viên'),state=el('select');for(const [value,title]of [['ACTIVE','Hoạt động'],['DISABLED','Đã khóa']]){const o=el('option',title);o.value=value;state.append(o);}stateLabel.append(state);state.onchange=()=>{dirty=true;};inputs.push(state);box.append(stateLabel);
 name.value=user?.name||'';email.value=user?.email||'';state.value=user?.status||'ACTIVE';
 box.append(el('h3','Quầy được gán'),el('p','Tài khoản hoạt động cần ít nhất một quầy. Giữ quầy lưu trữ để xem lịch sử; không thể tạo đơn mới ở quầy lưu trữ.','muted'));
 const counterChecks=[];for(const counter of counters){const label=el('label'),check=el('input');check.type='checkbox';check.checked=user?.counterIds.includes(counter.id)||false;check.onchange=()=>{dirty=true;};label.append(check,document.createTextNode(counter.name+' · '+counter.id+(counter.status==='ARCHIVED'?' · lưu trữ':'')));box.append(label);inputs.push(check);counterChecks.push([counter.id,check]);}
 if(!counters.length)box.append(el('p','Tạo quầy bán vé trước hoặc lưu nhân viên ở trạng thái Đã khóa.','muted'));
 box.append(el('h3','Quyền thao tác'));const permissionChecks=[];
 for(const [key,title]of Object.entries(posPermissionLabels)){const label=el('label'),check=el('input');check.type='checkbox';check.checked=user?user.permissions.includes(key):['SELL','PRINT'].includes(key);check.onchange=()=>{dirty=true;};label.append(check,document.createTextNode(title));box.append(label);inputs.push(check);permissionChecks.push([key,check]);}
 box.append(el('p','Dùng email riêng, không trùng tài khoản admin. Mật khẩu 12–200 ký tự; hosting không có Argon2id giới hạn 72 byte.','muted'));
 const prepare=button('Kiểm tra thông tin',()=>{
  const counterIds=counterChecks.filter(([,n])=>n.checked).map(([key])=>key),permissions=permissionChecks.filter(([,n])=>n.checked).map(([key])=>key);
  if(!name.value.trim()||!email.value||!email.checkValidity()||state.value==='ACTIVE'&&!counterIds.length||(!user||password.value)&&password.value.length<12){status.textContent='Nhập tên/email hợp lệ, mật khẩu ít nhất 12 ký tự và gán quầy cho tài khoản hoạt động.';return;}
  pending={id,name:name.value.trim(),email:email.value.trim(),status:state.value,counterIds,permissions,version:user?.version||0};if(password.value)pending.password=password.value;
  for(const n of inputs)n.disabled=true;prepare.disabled=true;status.textContent='';
  confirmation.replaceChildren(el('p',user?'Xác nhận lưu? Mọi phiên nhân viên này sẽ bị đăng xuất.':'Xác nhận tạo tài khoản nhân viên với quầy và quyền đã chọn?'),button('Xác nhận lưu nhân viên',async()=>{
   if(editing||!pending)return;editing=true;dialog.querySelectorAll('button').forEach(b=>b.disabled=true);
   try{await api('pos-users',pending);password.value='';pending=null;dirty=false;dialog.close();await render();notice(user?'Đã cập nhật nhân viên và thu hồi phiên đăng nhập.':'Đã tạo nhân viên.');}catch(error){status.textContent=error.message+' Nếu lỗi mạng, tải lại danh sách để kiểm tra trước khi gửi lại.';}finally{editing=false;dialog.querySelectorAll('button').forEach(b=>b.disabled=false);prepare.disabled=confirmation.childElementCount>0;}
  },'primary'),button('Quay lại',()=>{pending=null;for(const n of inputs)n.disabled=false;prepare.disabled=false;confirmation.replaceChildren();}));
 },'primary');box.append(prepare,confirmation,status);$('#fields').append(box);dialog.addEventListener('close',()=>{password.value='';pending=null;},{once:true});dialog.showModal();name.focus();
}
