window.SenseCMSInterfaceText ??= source => source;
window.SenseCMSInterfaceHtml ??= source => source;
(function(){
const q=(s,r=document)=>r.querySelector(s),qa=(s,r=document)=>[...r.querySelectorAll(s)],csrf=()=>document.body.dataset.sensecmsCsrf||q('input[name="csrf"]')?.value||'';
const toast=(type,message)=>window.SenseCMSUI?.toast?.(type,message);
const languageKey=()=>{try{return 'sensecms-content-language-'+JSON.parse(document.body.dataset.sensecmsUser||'{}').id}catch{return 'sensecms-content-language'}};
function languageState(){
 const state=q('[data-editor-languages]');let available=[];try{available=JSON.parse(state?.dataset.editorLanguages||'[]')}catch{}
 const fallback=state?.dataset.editorLocale||available[0]||'en',requested=new URL(location.href).searchParams.get('lang');
 let saved='';try{saved=sessionStorage.getItem(languageKey())||''}catch{}
 return {available,value:requested!==null?(available.includes(requested)?requested:fallback):(available.includes(saved)?saved:fallback)};
}
function languageUrl(href,value=languageState().value){
 const url=new URL(href,location.href);
 if(url.origin===location.origin&&/^\/(?:content|seo|appearance|conversations|surveys|forms|ai|system|dashboard|marketplace)(?:\/|$)/.test(url.pathname))url.searchParams.set('lang',value);
 return url.pathname+url.search+url.hash;
}
function rememberLanguage(value){
 if(!languageState().available.includes(value))return;
 try{sessionStorage.setItem(languageKey(),value)}catch{}
 const url=new URL(location.href);url.searchParams.set('lang',value);history.replaceState(history.state,'',url);
 qa('a[href^="/"]').forEach(link=>{if(!link.target&&!link.hasAttribute('download')&& !link.getAttribute('href').startsWith('//'))link.setAttribute('href',languageUrl(link.getAttribute('href'),value))});
 qa('form[data-content-filter]').forEach(form=>{let input=q('[name="lang"]',form);if(!input){input=document.createElement('input');input.type='hidden';input.name='lang';form.append(input)}input.value=value});
 qa('[data-workspace-switch] [name="return"]').forEach(input=>{input.value=input.closest('[data-workspace-home]')?languageUrl('/dashboard',value):location.pathname+location.search});
}
function applyLanguage(value){
 qa('[data-language-tabs]').forEach(tabs=>{
  if(!qa('[data-language-tab]',tabs).some(button=>button.dataset.languageTab===value))return;
  const root=tabs.closest('form')||tabs.parentElement;
  qa('[data-language-tab]',tabs).forEach(button=>{const active=button.dataset.languageTab===value;button.classList.toggle('is-active',active);button.setAttribute('aria-selected',String(active));button.tabIndex=active?0:-1});
  qa('[data-language-panel]',root).forEach(panel=>panel.hidden=panel.dataset.languagePanel!==value);
  qa('[data-ui-locale]',root).forEach(input=>input.value=value);
 });
 qa('[data-post-ai-locale]').forEach(select=>{if([...select.options].some(option=>option.value===value))select.value=value});
 rememberLanguage(value);
}
function locale(button){applyLanguage(button.dataset.languageTab)}
window.SenseCMSContentLanguage={current:()=>languageState().value,remember:rememberLanguage,url:languageUrl};
function initializeLanguages(){if(q('[data-editor-languages]'))applyLanguage(languageState().value)}
document.addEventListener('DOMContentLoaded',initializeLanguages);
document.addEventListener('sensecms:content-ready',initializeLanguages);
document.addEventListener('keydown',event=>{
 const extension=event.target.closest('[data-extension-tab]');if(extension&&['ArrowLeft','ArrowRight','Home','End'].includes(event.key)){const buttons=qa('[data-extension-tab]',extension.closest('[data-extension-tabs]')),index=buttons.indexOf(extension),next=event.key==='Home'?0:event.key==='End'?buttons.length-1:(index+(event.key==='ArrowRight'?1:-1)+buttons.length)%buttons.length;event.preventDefault();tab(buttons[next].dataset.extensionTab);buttons[next].focus();return}
 const button=event.target.closest('[data-language-tab]');if(!button||!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
 const buttons=qa('[data-language-tab]',button.closest('[data-language-tabs]')),index=buttons.indexOf(button),next=event.key==='Home'?0:event.key==='End'?buttons.length-1:(index+(event.key==='ArrowRight'?1:-1)+buttons.length)%buttons.length;
 event.preventDefault();locale(buttons[next]);buttons[next].focus();
});
let revealingInvalid=false;
document.addEventListener('invalid',event=>{
 const panel=event.target.closest('[data-language-panel]');if(!panel||revealingInvalid)return;
 revealingInvalid=true;setTimeout(()=>{revealingInvalid=false},0);applyLanguage(panel.dataset.languagePanel);
 const chatPanel=panel.closest('[data-chat-config-panel]');if(chatPanel?.hidden)q('[data-chat-config-tab="'+chatPanel.dataset.chatConfigPanel+'"]')?.click();
 requestAnimationFrame(()=>event.target.focus());
},true);
function tab(value){const root=q('[data-extension-tabs]');if(!root)return;qa('[data-extension-tab]',root).forEach(item=>{const active=item.dataset.extensionTab===value;item.classList.toggle('is-active',active);item.setAttribute('aria-selected',String(active));item.tabIndex=active?0:-1});qa('[data-extension-panel]').forEach(panel=>panel.hidden=panel.dataset.extensionPanel!==value);const url=new URL(location.href);url.searchParams.set('tab',value);history.replaceState(history.state,'',url);window.lucide?.createIcons()}
function navigate(url){return window.SenseCMSUI?.navigate?window.SenseCMSUI.navigate(url):(location.href=url)}
async function json(url,options={}){const response=await fetch(url,{...options,headers:{Accept:'application/json','X-SenseCMS-Request':'1',...(options.headers||{})}});let payload;try{payload=await response.json()}catch{throw new Error(window.SenseCMSInterfaceText('The server returned an invalid response.'))}if(!response.ok||!payload.ok)throw new Error(payload.message||window.SenseCMSInterfaceText('The requested action could not be completed.'));return payload}
function busy(button,state,label='Processing…'){if(!button)return;if(state){button.dataset.label=button.innerHTML;button.disabled=true;button.innerHTML='<span class="sensecms-inline-spinner"></span>'+label}else{button.disabled=false;if(button.dataset.label){button.innerHTML=button.dataset.label;delete button.dataset.label}window.lucide?.createIcons()}}
function review(data){const panel=q('[data-package-review]');if(!panel){return Promise.resolve(navigate('/system/extensions?tab=catalog')).then(()=>review(data))}panel.hidden=false;panel.dataset.token=data.token;q('[data-review-name]',panel).textContent=data.name;q('[data-review-publisher]',panel).textContent=data.publisher.name+' · '+data.publisher.key_id;q('[data-review-type]',panel).textContent=data.type[0].toUpperCase()+data.type.slice(1);q('[data-review-version]',panel).textContent=data.version;q('[data-review-engine]',panel).textContent='SenseCMS '+data.engine;q('[data-review-files]',panel).textContent=data.files+' signed files';q('[data-review-migrations]',panel).textContent=data.migrations?data.migrations+' reversible':window.SenseCMSInterfaceText('None');q('[data-review-checksum]',panel).textContent=data.checksum.slice(0,14)+'…'+data.checksum.slice(-10);q('[data-review-description]',panel).textContent=data.description||window.SenseCMSInterfaceText('No package description was supplied.');const permissions=q('[data-review-permissions]',panel);permissions.replaceChildren(...(data.permissions.length?data.permissions:['No elevated permissions declared']).map(value=>{const span=document.createElement('span');span.textContent=value;return span}));panel.scrollIntoView({behavior:'smooth',block:'nearest'});window.lucide?.createIcons()}
function closeReview(){const panel=q('[data-package-review]');if(panel){panel.hidden=true;delete panel.dataset.token}}
async function packageAction(button,url,body,label){busy(button,true,label);try{const payload=await json(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:csrf(),...body})});toast('success',payload.message);return payload.data}catch(error){toast('error',error.message);throw error}finally{busy(button,false)}}
document.addEventListener('click',event=>{
 const language=event.target.closest('[data-language-tab]');if(language){locale(language);return}
 const extensionTab=event.target.closest('[data-extension-tab]');if(extensionTab){tab(extensionTab.dataset.extensionTab);return}
 const jump=event.target.closest('[data-extension-tab-jump]');if(jump){tab(jump.dataset.extensionTabJump);return}
 const notification=event.target.closest('[data-notification-toggle]');if(notification){event.stopPropagation();const panel=q('[data-notification-panel]');if(panel){panel.hidden=!panel.hidden;if(!panel.hidden)loadNotifications()}return}
 if(!event.target.closest('[data-notification-panel]')){const panel=q('[data-notification-panel]');if(panel)panel.hidden=true}
 const select=event.target.closest('[data-package-select]');if(select){q('[name="package"]',select.closest('form'))?.click();return}
 if(event.target.closest('[data-review-close]')){closeReview();return}
 const install=event.target.closest('[data-package-install]');if(install){const token=q('[data-package-review]')?.dataset.token;if(!token)return;packageAction(install,'/system/extensions/packages/install',{token},'Installing…').then(data=>navigate('/system/extensions?tab='+(data.type==='theme'?'catalog':data.type+'s'))).catch(()=>{});return}
 const check=event.target.closest('[data-package-check-updates]');if(check){packageAction(check,'/system/extensions/packages/updates/check',{},'Checking…').then(()=>navigate('/system/extensions?tab=catalog')).catch(()=>{});return}
 const update=event.target.closest('[data-package-update]');if(update){const [type,slug]=update.dataset.packageUpdate.split(':');packageAction(update,`/system/extensions/packages/${type}/${slug}/update`,{},'Verifying…').then(review).catch(()=>{});return}
 const rollback=event.target.closest('[data-package-rollback]');if(rollback){const [type,slug]=rollback.dataset.packageRollback.split(':');window.SenseCMSUI?.modal?.('Roll back package','Restore the previous verified release and reverse migrations introduced by the current version?',()=>packageAction(rollback,`/system/extensions/packages/${type}/${slug}/rollback`,{},'Restoring…').then(()=>navigate(type==='theme'?'/appearance/themes':'/system/extensions?tab='+type+'s')).catch(()=>{}));return}
 const publisher=event.target.closest('[data-publisher-toggle]');if(publisher){const active=publisher.dataset.active==='1';window.SenseCMSUI?.modal?.(active?'Enable publisher key':'Disable publisher key',(active?'Allow':'Block')+' future packages signed by this publisher key?',()=>packageAction(publisher,'/system/extensions/publishers/'+encodeURIComponent(publisher.dataset.publisherToggle)+'/toggle',{active},active?'Enabling…':'Disabling…').then(()=>navigate('/system/extensions?tab=catalog')).catch(()=>{}));return}
});
document.addEventListener('change',event=>{
 const picker=event.target;
 if(picker.matches('[data-seo-page],[data-popup-page]')){
   const seo=picker.hasAttribute('data-seo-page'),url=new URL(seo?'/seo':'/system/extensions/popups',location.origin);
   url.searchParams.set('document',picker.value);
   if(seo)url.searchParams.set('type',picker.dataset.seoType||'page');
   const lang=new URLSearchParams(location.search).get('lang');if(lang)url.searchParams.set('lang',lang);
   const go=()=>navigate(url.pathname+url.search),guard=window.SenseCMSUnsavedGuard;
   if(guard?.dirty()){
     picker.selectedIndex=Math.max(0,[...picker.options].findIndex(option=>option.defaultSelected));
     guard.confirmLeave(go);
   }else go();
   return;
 }
 if(event.target.matches('[name="package"]')){const file=event.target.files?.[0],label=q('[data-package-file]',event.target.closest('form'));if(label)label.textContent=file?file.name+' · '+Math.max(.01,file.size/1048576).toFixed(2)+' MB':window.SenseCMSInterfaceText('No package selected · max. 25 MB');if(file)event.target.closest('form')?.requestSubmit()}});
document.addEventListener('submit',async event=>{
 const toggle=event.target.closest('[data-extension-toggle]');if(toggle){event.preventDefault();event.stopImmediatePropagation();const button=q('button',toggle);let next=null;busy(button,true);try{const payload=await json(toggle.action,{method:'POST',body:new FormData(toggle)}),active=!!payload.data?.active,card=toggle.closest('[data-extension-card]'),status=q('[data-extension-status]',card),input=q('[name=active]',toggle);status.textContent=active?window.SenseCMSInterfaceText('Active'):window.SenseCMSInterfaceText('Disabled');status.className='sensecms-status '+(active?'is-active':'is-inactive');input.value=active?'0':'1';next={active,label:active?'Disable':'Enable'};toast('success',payload.message)}catch(error){toast('error',error.message)}finally{busy(button,false);if(next){button.textContent=next.label;button.classList.toggle('is-disable',next.active)}}return}
 const inspect=event.target.closest('[data-package-inspect]');if(inspect){event.preventDefault();event.stopImmediatePropagation();const button=q('[data-package-select]',inspect),file=q('[name="package"]',inspect);if(!file?.files?.length)return;busy(button,true,'Verifying…');try{const payload=await json(inspect.action,{method:'POST',body:new FormData(inspect)});toast('success',payload.message);review(payload.data)}catch(error){toast('error',error.message)}finally{busy(button,false)}return}
 const publisher=event.target.closest('[data-publisher-form]');if(publisher){event.preventDefault();event.stopImmediatePropagation();const button=q('button[type="submit"]',publisher);busy(button,true,'Trusting…');try{const payload=await json(publisher.action,{method:'POST',body:new FormData(publisher)});toast('success',payload.message);navigate('/system/extensions?tab=catalog')}catch(error){toast('error',error.message)}finally{busy(button,false)}}
},true);
async function loadNotifications(){const list=q('[data-notification-list]');if(!list)return;try{const payload=await json('/api/notifications'),data=payload.data||{},items=data.items||[];list.innerHTML=items.map(item=>`<a class="sensecms-notification-item" href="${item.url}"><i><span data-lucide="${item.icon}"></span></i><div><strong>${escape(item.title)}</strong><p>${escape(item.message)}</p></div>${item.count?`<b>${item.count>99?'99+':item.count}</b>`:'<span></span>'}</a>`).join('')||("<div class=\"sensecms-notification-loading\">"+window.SenseCMSInterfaceHtml("No notifications.")+"</div>");qa('[data-notification-count]').forEach(badge=>{badge.textContent=data.total>99?'99+':data.total;badge.hidden=!data.total});const status=q('[data-notification-status]');if(status)status.textContent=data.total?data.total+' requiring attention':window.SenseCMSInterfaceText('All caught up');window.lucide?.createIcons()}catch(error){list.innerHTML='<div class="sensecms-notification-loading">'+escape(error.message)+'</div>'}}
function escape(value){const node=document.createElement('span');node.textContent=String(value??'');return node.innerHTML}
let last=-1;async function poll(){try{const payload=await json('/api/notifications'),data=payload.data||{};qa('[data-notification-count]').forEach(badge=>{badge.textContent=data.total>99?'99+':data.total;badge.hidden=!data.total});if(last!==data.total&&!q('[data-notification-panel]')?.hidden)loadNotifications();last=data.total}catch{}setTimeout(poll,15000)}
document.addEventListener('DOMContentLoaded',()=>{poll();window.lucide?.createIcons()});
})();
