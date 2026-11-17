'use strict';
(()=>{
 const status=document.getElementById('paypal-status'),confirm=document.getElementById('paypal-confirm'),query=new URLSearchParams(location.search),tokens=query.getAll('token');
 if(query.has('cancelled')){status.textContent='Bạn đã quay về từ PayPal. Chưa gửi yêu cầu thu tiền từ trang này; mở tra cứu để xem trạng thái mới nhất.';return;}
 if(tokens.length!==1||!/^[A-Z0-9]{10,40}$/.test(tokens[0])){status.textContent='Thiếu mã thanh toán PayPal hợp lệ. Mở lại liên kết từ PayPal hoặc tra cứu đơn.';return;}
 const payload=JSON.stringify({orderId:tokens[0]});let busy=false,done=false;
 status.textContent='Bấm xác nhận để máy chủ kiểm tra và hoàn tất thanh toán với PayPal.';confirm.hidden=false;
 confirm.onclick=async()=>{
  if(busy||done)return;busy=true;confirm.disabled=true;status.textContent='Đang xác minh với PayPal. Giữ trang này mở.';
  try{const r=await fetch('/api/v1/payments/paypal/capture',{method:'POST',credentials:'omit',cache:'no-store',headers:{'Content-Type':'application/json','X-JinMemo-Request':'1'},body:payload}),data=await r.json();if(!r.ok)throw Error(data.message||'Chưa xác định được kết quả thanh toán.');
   const messages={PAID:'Đã ghi nhận thanh toán. Mở tra cứu đơn để xem kết quả.',TEST:'Đã xác nhận giao dịch TEST. Không ghi thu vào đơn thật.',REVIEW:'PayPal đã trả kết quả cần đối soát. Không thanh toán thêm; liên hệ quản trị viên để kiểm tra.',PENDING:'PayPal chưa xác nhận đã thu tiền. Kiểm tra trạng thái trên PayPal và thử lại cùng mã này.'};if(!Object.hasOwn(messages,data.status))throw Error('Phản hồi thanh toán không hợp lệ.');status.textContent=messages[data.status];done=data.status!=='PENDING';if(done)confirm.hidden=true;
  }catch(e){status.textContent=e.message+' Kiểm tra PayPal trước khi thanh toán thêm; có thể thử xác nhận lại cùng mã.';confirm.textContent='Kiểm tra lại cùng thanh toán';}
  finally{busy=false;confirm.disabled=done;}
 };
})();
