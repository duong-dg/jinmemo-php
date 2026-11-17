'use strict';
(()=>{const menus=Array.from(document.querySelectorAll('.public-mobile-menu,.public-page-menu,.language-picker'));
 for(const menu of menus){menu.addEventListener('toggle',()=>{if(menu.open)for(const other of menus)if(other!==menu)other.open=false;});menu.addEventListener('click',event=>{if(event.target.closest('a'))menu.open=false;});}
 document.addEventListener('pointerdown',event=>{for(const menu of menus)if(!menu.contains(event.target))menu.open=false;});
 document.addEventListener('keydown',event=>{if(event.key==='Escape')for(const menu of menus)if(menu.open){menu.open=false;menu.querySelector('summary').focus();}});
 for(const menu of menus)menu.addEventListener('focusout',event=>{if(event.relatedTarget&&!menu.contains(event.relatedTarget))menu.open=false;});
 const mobile=window.matchMedia('(max-width:1100px)');mobile.addEventListener('change',()=>{for(const menu of menus)menu.open=false;});
})();
