// Generate against the original source snapshot; never use PHP implementation to set expectations.
import {writeFileSync} from 'node:fs';
import {pathToFileURL} from 'node:url';
import {resolve} from 'node:path';
const source=process.argv[2];
if(!source)throw Error('Pass the original payments/protocol.ts path.');
const {sepaySign,sepayAmount,digest}=await import(pathToFileURL(resolve(source)).href);
const secret='fixture-card-secret-1234567890';
const returned='https://merchant.example.test/api/v1/payments/sepay/card-return';
const fields={order_amount:'15000000000',merchant:'MERCHANT_1',currency:'VND',operation:'PURCHASE',order_description:'Thanh toan JC1234567890',order_invoice_number:'JMP'+'A'.repeat(24),payment_method:'CARD',success_url:returned,error_url:returned,cancel_url:returned};
const signatures=[fields,{...fields,customer_id:'Khách + & = , Hội An'},{...fields,customer_id:''},Object.fromEntries(Object.entries({...fields,signature:'ignore',unknown:'ignore'}).reverse())].map(fields=>({fields,signature:sepaySign(fields,secret)}));
const amounts=['1','00000000001','100.0','100.00','15000000000','15000000000.00','0','0.00','15000000001','000000000001','1.01','1.000','1e2','+1','-1',' 1','1 ','1\n','1,000','NaN','Infinity','','.00','１'];
const money=amounts.map(value=>{try{return {value,amount:sepayAmount(value)};}catch{return {value,amount:null};}});
const normalized={invoice:fields.order_invoice_number,order:'ORDER_1',transaction:'TX-1',amount:100,orderAmount:100,currency:'VND',transactionCurrency:'VND',method:'CARD',status:'CAPTURED',transactionStatus:'APPROVED',type:'PAYMENT',notification:'ORDER_PAID'};
writeFileSync(new URL('./fixtures/sepay-card-node.json',import.meta.url),JSON.stringify({secret,signatures,money,normalized,fingerprint:digest(JSON.stringify(normalized))},null,2)+'\n');
