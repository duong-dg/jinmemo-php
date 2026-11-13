'use strict';
const ticketSchedule={
 period(variant,date){const day=new Date(date+'T00:00:00Z').getUTCDay()||7;return (variant.schedulePeriods||[]).map((p,index)=>({...p,index})).filter(p=>p.startDate<=date&&date<=p.endDate&&(!p.weekdays||p.weekdays.includes(day))).sort((a,b)=>(b.priority??10)-(a.priority??10)||(Date.parse(a.endDate)-Date.parse(a.startDate))-(Date.parse(b.endDate)-Date.parse(b.startDate))||b.index-a.index)[0];},
 slots(variant,date){return this.period(variant,date)?.timeSlots??variant.timeSlots??[];},
 allSlots(variant){return [...new Map([...(variant.timeSlots||[]),...(variant.schedulePeriods||[]).flatMap(p=>p.timeSlots)].map(s=>[s.id,s])).values()];}
};
