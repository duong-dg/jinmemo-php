'use strict';
document.querySelector('#print-document').addEventListener('click',()=>window.print());
const receipts=document.querySelector('#include-receipts');
if(receipts)receipts.addEventListener('change',()=>{document.querySelector('#receipt-annex').hidden=!receipts.checked;});
