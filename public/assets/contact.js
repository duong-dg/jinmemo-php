'use strict';
const form=document.querySelector('#contact-form');
if(form){
 const en=form.dataset.locale==='en',error=document.querySelector('#contact-error'),success=document.querySelector('#contact-success');let requestId=crypto.randomUUID(),lastPayload=null,busy=false;
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(busy)return;const data=new FormData(form),payload={name:data.get('name'),email:data.get('email'),phone:data.get('phone'),subject:data.get('subject'),message:data.get('message'),website:data.get('website'),locale:form.dataset.locale};const signature=JSON.stringify(payload);
  if(lastPayload!==null && lastPayload!==signature)requestId=crypto.randomUUID();lastPayload=signature;busy=true;error.hidden=true;const fields=form.querySelector('fieldset');fields.disabled=true;
  try{const response=await fetch('/api/v1/site/contact/requests',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-Jingo-Contact':'1'},body:JSON.stringify({id:requestId,...payload})});const result=await response.json().catch(()=>({}));if(!response.ok)throw new Error(result.message||(en?'Could not send your message. Please try again.':'Chưa gửi được yêu cầu. Vui lòng thử lại.'));form.hidden=true;success.hidden=false;success.setAttribute('tabindex','-1');success.focus();}
  catch(problem){error.textContent=problem.message;error.hidden=false;}finally{fields.disabled=false;busy=false;}
 });
 document.querySelector('#another-message').addEventListener('click',()=>{form.reset();requestId=crypto.randomUUID();lastPayload=null;success.hidden=true;form.hidden=false;form.querySelector('input').focus();});
}
