window.SenseCMSInterfaceText ??= source => source;
window.SenseCMSInterfaceHtml ??= source => source;
(function(){
'use strict';
const all=(selector,root=document)=>[...root.querySelectorAll(selector)];
let filterTimer=0,hydrating=false,hydrateFrame=0;

const pendingAssets=new Map();
function loadAsset(tag,url){
 if(pendingAssets.has(url))return pendingAssets.get(url);
 if(document.querySelector(`${tag}[${tag==='link'?'href':'src'}="${url}"]`))return Promise.resolve();
 const asset=document.createElement(tag);
 if(tag==='link'){asset.rel='stylesheet';asset.href=url}
 else{asset.src=url;asset.nonce=document.querySelector('script[nonce]')?.nonce||''}
 const ready=new Promise((resolve,reject)=>{
   asset.onload=resolve;
   asset.onerror=()=>{asset.remove();reject(new Error('Could not load '+url))};
 });
 pendingAssets.set(url,ready);
 document.head.append(asset);
 return ready.finally(()=>pendingAssets.delete(url));
}
async function postEditor(){if(!document.querySelector('[data-post-editor]'))return;await loadAsset('link','/assets/lib/quill/quill.snow.css?v=2.0.3');if(!window.Quill)await loadAsset('script','/assets/lib/quill/quill.js?v=2.0.3');await loadAsset('script','/theme/sensecms-post-editor.js?v=i18n2-20261101-media-1');await loadAsset('script','/theme/sensecms-post-ai.js?v=i18n2-20260921-1');document.dispatchEvent(new Event('sensecms:content-ready'));}
async function pageBuilder(){if(!document.querySelector('[data-page-builder]')){window.__senseCMSBuilderLifecycle?.abort();return}await loadAsset('link','/assets/lib/quill/quill.snow.css?v=2.0.3');if(!window.Quill)await loadAsset('script','/assets/lib/quill/quill.js?v=2.0.3');await loadAsset('link','/theme/sensecms-page-builder.css?v=20261106-builder-layout');await loadAsset('script','/theme/sensecms-page-builder.js?v=i18n2-20261106-builder-tools');document.dispatchEvent(new Event('sensecms:navigated'));}
function go(url){if(!url||!window.SenseCMSUI)return;window.SenseCMSUI.navigate(url).then(postEditor).then(pageBuilder).then(hydrate).catch(()=>window.SenseCMSUI.toast('error',window.SenseCMSInterfaceText('The content workspace could not be loaded.')));}
function slug(value){return value.normalize('NFKD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'').slice(0,255);}
function contentState(target,next='dirty'){const form=target.closest?.('[data-content-form]');if(!form)return;const header=all('[data-content-workspace]').find(item=>item.dataset.contentWorkspace===form.getAttribute('id'));if(!header)return;const status=header.querySelector('[data-content-status]'),detail=header.querySelector('[data-content-save-state]'),values={dirty:['pencil-line','Unsaved','Unsaved changes'],saving:['loader-circle','Saving','Saving changes…']},value=values[next]||values.dirty;form.dataset.contentState=next;if(status){status.className='sensecms-content-status is-'+next;status.innerHTML='<i data-lucide="'+value[0]+'"></i>'+value[1];}if(detail){detail.className='sensecms-content-save-state is-'+next;const text=detail.querySelector('b');if(text)text.textContent=value[2];}window.lucide?.createIcons();}
function schedules(root=document){all('[data-publication-status]',root).forEach(select=>{const field=select.closest('form')?.querySelector('[data-schedule-field]');if(field)field.hidden=select.value!=='scheduled';});}
function navigationState(root=document){all('[data-navigation-editor]',root).forEach(editor=>{const items=all('[data-navigation-item]',editor);const empty=editor.querySelector('[data-navigation-empty]');if(empty)empty.hidden=items.length>0;items.forEach((item,index)=>{all('[name]',item).forEach(field=>field.name=field.name.replace(/items\[[^\]]+\]/,'items['+index+']'));const up=item.querySelector('[data-navigation-up]'),down=item.querySelector('[data-navigation-down]'),handle=item.querySelector('[data-navigation-handle]');if(up)up.disabled=index===0;if(down)down.disabled=index===items.length-1;if(handle)handle.draggable=true;});});}
function hydrate(root=document){if(hydrating)return;hydrating=true;schedules(root);navigationState(root);if(window.SenseCMSIcons)window.SenseCMSIcons.render(root);else window.lucide?.createIcons({root});hydrating=false;}

document.addEventListener('click',event=>{
 const link=event.target.closest('a[data-content-link]');
 if(link&&!link.target&&!event.ctrlKey&&!event.metaKey&&!event.shiftKey&&!event.altKey){event.preventDefault();if(link.classList.contains('is-disabled'))return;const dirty=document.querySelector('[data-content-form][data-content-state="dirty"]');const proceed=()=>go(link.href);if(dirty)window.SenseCMSUI?.modal('Unsaved changes','Leave this editor without saving your recent changes?',proceed);else proceed();return;}
 const add=event.target.closest('[data-navigation-add]');if(add){const editor=add.closest('[data-navigation-editor]')||document.querySelector('[data-navigation-editor]'),template=document.querySelector('[data-navigation-template]'),list=editor?.querySelector('[data-navigation-items]');if(!editor||!template||!list)return;const html=template.innerHTML.replaceAll('__INDEX__',String(all('[data-navigation-item]',list).length));list.insertAdjacentHTML('beforeend',html);navigationState(editor);editor.querySelector('[data-navigation-item]:last-child input')?.focus();editor.dispatchEvent(new Event('input',{bubbles:true}));window.lucide?.createIcons();return;}
 const remove=event.target.closest('[data-navigation-remove]');if(remove){const editor=remove.closest('[data-navigation-editor]');remove.closest('[data-navigation-item]')?.remove();navigationState(editor);editor?.dispatchEvent(new Event('input',{bubbles:true}));return;}
 const move=event.target.closest('[data-navigation-up],[data-navigation-down]');if(move){const item=move.closest('[data-navigation-item]'),list=item?.parentElement;if(!item||!list)return;if(move.matches('[data-navigation-up]')&&item.previousElementSibling)list.insertBefore(item,item.previousElementSibling);if(move.matches('[data-navigation-down]')&&item.nextElementSibling)list.insertBefore(item.nextElementSibling,item);navigationState(list.closest('[data-navigation-editor]'));item.dispatchEvent(new Event('input',{bubbles:true}));}
});

document.addEventListener('input',event=>{const input=event.target.closest('[data-seo-image-url]');if(!input)return;const root=input.closest('[data-seo-image]'),preview=root?.querySelector('[data-seo-image-preview]'),internal=input.value.startsWith('/')&&!input.value.startsWith('//');if(preview){preview.hidden=!internal;if(internal)preview.src=input.value;}root.querySelector('[data-seo-image-width]').value=0;root.querySelector('[data-seo-image-height]').value=0;root.querySelector('[data-seo-image-type]').value='';});

document.addEventListener('change',event=>{if(event.target.matches('[data-publication-status]'))schedules(event.target.closest('form')||document);if(!event.target.matches('[data-seo-page],[data-popup-page]'))contentState(event.target);});
document.addEventListener('input',event=>contentState(event.target));
document.addEventListener('input',event=>{const title=event.target.closest('input[name*="[title]"],input[name*="[name]"]');if(!title)return;const panel=title.closest('[data-language-panel]')||title.closest('form'),candidate=panel?.querySelector('input[name*="[slug]"]')||title.closest('form')?.querySelector('input[name="slug"]');if(candidate&&(candidate.value===''||candidate.dataset.autoSlug==='1')){candidate.value=slug(title.value);candidate.dataset.autoSlug='1';candidate.dispatchEvent(new Event('change',{bubbles:true}));}});
document.addEventListener('input',event=>{if(event.target.matches('input[name*="[slug]"],input[name="slug"]')&&!event.target.matches(':focus'))return;if(event.target.matches('input[name*="[slug]"],input[name="slug"]')&&event.isTrusted)event.target.dataset.autoSlug='0';});

document.addEventListener('submit',event=>{const form=event.target.closest('[data-content-filter]');if(!form)return;event.preventDefault();event.stopImmediatePropagation();const url=new URL(form.action||location.href,location.origin);new FormData(form).forEach((value,key)=>{if(String(value).trim()!=='')url.searchParams.set(key,String(value));});go(url.pathname+url.search);},true);
document.addEventListener('submit',event=>{const form=event.target.closest('[data-content-form]');if(form)contentState(form,'saving');},true);
document.addEventListener('input',event=>{const form=event.target.closest('[data-content-filter]');if(!form||event.target.type!=='search')return;clearTimeout(filterTimer);filterTimer=setTimeout(()=>form.requestSubmit(),320);});

let dragged=null;
document.addEventListener('dragstart',event=>{const handle=event.target.closest('[data-navigation-handle]'),item=handle?.closest('[data-navigation-item]');if(!item)return;dragged=item;item.classList.add('is-dragging');event.dataTransfer.effectAllowed='move';});
document.addEventListener('dragover',event=>{const item=event.target.closest('[data-navigation-item]');if(!dragged||!item||item===dragged)return;event.preventDefault();const box=item.getBoundingClientRect();item.parentElement.insertBefore(dragged,event.clientY<box.top+box.height/2?item:item.nextElementSibling);navigationState(item.closest('[data-navigation-editor]'));});
document.addEventListener('dragend',()=>{if(!dragged)return;const editor=dragged.closest('[data-navigation-editor]');dragged.classList.remove('is-dragging');dragged=null;editor?.dispatchEvent(new Event('input',{bubbles:true}));});

document.addEventListener('DOMContentLoaded',()=>{hydrate();const main=document.querySelector('main');if(main)new MutationObserver(records=>{const meaningful=records.some(record=>[...record.addedNodes].some(node=>node.nodeType===1&&!node.matches?.('svg.lucide')));if(!meaningful||hydrateFrame)return;hydrateFrame=requestAnimationFrame(()=>{hydrateFrame=0;hydrate(main)});}).observe(main,{childList:true,subtree:true});});
})();

/* Native disclosures keep secondary row actions available without JavaScript. */
document.addEventListener('toggle', event => {
    const details = event.target;
    if (!details.matches?.('.sensecms-row-more[open]')) return;
    document.querySelectorAll('.sensecms-row-more[open]').forEach(other => { if (other !== details) other.open = false; });
}, true);

/* One library dialog for document fields, rich text and the Builder. */
(()=>{
'use strict';
const picker=document.querySelector('[data-library-picker]');
if(!picker)return;
const facility=()=>target?.facility||'', csrf=document.body.dataset.sensecmsCsrf||'',
grid=picker?.querySelector('[data-media-picker-grid]'),status=picker?.querySelector('[data-media-picker-status]'),foldersHost=picker?.querySelector('[data-media-picker-folders]'),pages=picker?.querySelector('[data-media-picker-pages]'),search=picker?.querySelector('[data-media-picker-search]'),folderEditor=picker?.querySelector('[data-media-folder-editor]'),folderName=picker?.querySelector('[data-media-folder-name]'),file=document.querySelector('[data-library-file]');let serial=0,target=null,searchTimer=0,pickerState={kind:'image',folder:-1,page:1,q:'',folders:[],permissions:{}};
const icons=(scope=document)=>window.SenseCMSIcons?window.SenseCMSIcons.render(scope):window.lucide?.createIcons({root:scope}),toast=(type,message)=>window.SenseCMSUI?.toast?.(type,message),escape=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
const request=async(url,options={})=>{const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json',...(options.headers||{})},...options});const payload=await response.json().catch(()=>({ok:false,message:'The server returned an invalid response.'}));if(!response.ok||payload.ok===false)throw new Error(payload.message||window.SenseCMSInterfaceText('The action could not be completed.'));return payload};
const json=body=>({method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf,...body})});
const detail=item=>({id:Number(item.id),width:Number(item.width)||0,height:Number(item.height)||0,url:item.url||item.path||'',title:item.title||item.original_name||'Media item',mime:item.mime_type||'',kind:item.kind||(String(item.mime_type||'').split('/')[0])});
const closePicker=()=>{++serial;clearTimeout(searchTimer);if(picker.open)picker.close();target=null;folderEditor.hidden=true};
const select=(item,owner=target)=>{if(!owner||target!==owner||!owner.anchor?.isConnected||owner.kind!==item.kind)return;closePicker();owner.select(item)};
const canManage=item=>target?.manage&&pickerState.permissions.delete&&(Number(item.facility_id)>0||pickerState.permissions.global);
const renderFolders=()=>{foldersHost.innerHTML=`<button type="button" data-media-folder="-1"><i data-lucide="library"></i><span>${window.SenseCMSInterfaceHtml("All media")}</span></button><button type="button" data-media-folder="0"><i data-lucide="folder-open"></i><span>${window.SenseCMSInterfaceHtml("Unfiled")}</span></button>`+pickerState.folders.map(folder=>`<div class="sensecms-media-picker-folder"><button type="button" data-media-folder="${Number(folder.id)}"><i data-lucide="folder"></i><span>${escape(folder.name)}<small>${escape(folder.facility_name||'')}</small></span><b>${Number(folder.media_count||0)}</b></button>${canManage(folder)&&Number(folder.media_count||0)===0?`<button type="button" data-media-folder-remove="${Number(folder.id)}" aria-label="Delete empty folder ${escape(folder.name)}"><i data-lucide="trash-2"></i></button>`:''}</div>`).join('');foldersHost.querySelectorAll('[data-media-folder]').forEach(button=>button.classList.toggle('is-active',Number(button.dataset.mediaFolder)===Number(pickerState.folder)))};
const renderPages=pagination=>{const page=Number(pagination?.page||1),count=Number(pagination?.pages||1);pages.innerHTML=count<=1?'':`<button type="button" data-media-page="${page-1}" ${page<=1?'disabled':''} aria-label="${window.SenseCMSInterfaceHtml("Previous page")}"><i data-lucide="chevron-left"></i></button><span>${page} / ${count}</span><button type="button" data-media-page="${page+1}" ${page>=count?'disabled':''} aria-label="${window.SenseCMSInterfaceHtml("Next page")}"><i data-lucide="chevron-right"></i></button>`};
const render=(items,pagination)=>{grid.innerHTML='';if(!items.length){grid.innerHTML=("<div class=\"sensecms-media-picker-empty\"><i data-lucide=\"images\"></i><strong>"+window.SenseCMSInterfaceHtml("No matching media")+"</strong><span>"+window.SenseCMSInterfaceHtml("Try another folder or search term.")+"</span><button class=\"btn bg-primary text-white\" type=\"button\" data-media-empty-upload><i data-lucide=\"cloud-upload\"></i>"+window.SenseCMSInterfaceHtml("Upload media")+"</button></div>")}else items.forEach(raw=>{const item=detail(raw),card=document.createElement('article');card.className='sensecms-media-picker-item';card.innerHTML=`<button type="button" data-media-select>${item.kind==='image'?`<img src="${escape(raw.preview_url||item.url)}" alt="">`:`<span class="sensecms-media-file-icon"><i data-lucide="${item.kind==='audio'?'audio-lines':'video'}"></i></span>`}<span><b>${escape(item.title)}</b><small>${escape(item.mime)}</small></span></button>${canManage(raw)&&!Number(raw.direct_usage||0)?'<button type="button" class="sensecms-media-picker-trash" data-media-trash="'+item.id+'" aria-label="Move '+escape(item.title)+' to trash"><i data-lucide="archive-x"></i></button>':''}`;card.querySelector('[data-media-select]').addEventListener('click',()=>select(item));grid.append(card)});grid.querySelector('[data-media-empty-upload]')?.toggleAttribute('hidden',!target?.manage);renderPages(pagination);icons(picker)};
const loadPicker=async(page=pickerState.page)=>{const ticket=++serial;pickerState.page=Math.max(1,Number(page)||1);status.textContent=window.SenseCMSInterfaceText('Loading media…');grid.classList.add('is-loading');try{const params=new URLSearchParams({kind:pickerState.kind,facility:target?.manage?facility():'',folder:String(pickerState.folder),q:pickerState.q,page:String(pickerState.page),status:'active'}),data=(await request('/api/media?'+params)).data;if(ticket!==serial||!picker.open)return;pickerState.folders=data.folders||[];pickerState.permissions=data.permissions||{};pickerState.page=Number(data.pagination?.page||1);renderFolders();render(data.items||[],data.pagination);status.textContent=`${Number(data.pagination?.total||0)} available`}catch(error){if(ticket!==serial)return;grid.replaceChildren();pages.replaceChildren();status.textContent=error.message;toast('error',error.message)}finally{if(ticket===serial)grid.classList.remove('is-loading')}};
const openPicker=async next=>{closePicker();target=next;const {kind}=next;
picker.querySelector('[data-media-folder-create]').hidden=!next.manage;
picker.querySelector('[data-media-picker-upload]').hidden=!next.manage;
foldersHost.replaceChildren();pages.replaceChildren();pickerState={kind,folder:-1,page:1,q:'',folders:[],permissions:{}};search.value='';picker.querySelector('[data-media-picker-title]').textContent=`Choose ${kind}`;status.textContent=window.SenseCMSInterfaceText('Loading media…');grid.innerHTML='';picker.showModal();search.focus();await loadPicker()};
const accept={image:'image/jpeg,image/png,image/webp',audio:'audio/mpeg,audio/mp4,audio/ogg,audio/wav',video:'video/mp4,video/webm'};
const upload=next=>{if(next)target=next;if(!target)return;file.accept=accept[target.kind]||'';file.value='';file.click()};file?.addEventListener('change',async()=>{if(!file.files?.length||!target)return;const owner=target,body=new FormData;body.set('csrf',csrf);body.set('facility_id',facility());if(picker?.open&&pickerState.folder>0)body.set('folder_id',String(pickerState.folder));body.append('assets[]',file.files[0]);try{const payload=await request('/content/media/upload',{method:'POST',body}),item=detail(payload.data?.items?.[0]||{});if(!item.id)throw new Error(window.SenseCMSInterfaceText('The uploaded media could not be selected.'));if(item.kind!==owner.kind)throw new Error(window.SenseCMSInterfaceText('The uploaded file does not match this field.'));if(target===owner&&owner.anchor.isConnected){select(item,owner);toast('success',window.SenseCMSInterfaceText('Media uploaded and selected.'))}}catch(error){toast('error',error.message)}});
picker?.querySelectorAll('[data-media-picker-close]').forEach(button=>button.addEventListener('click',closePicker));picker?.addEventListener('cancel',event=>{event.preventDefault();closePicker()});picker?.querySelector('[data-media-picker-upload]')?.addEventListener('click',()=>upload());picker?.querySelector('[data-media-picker-refresh]')?.addEventListener('click',()=>loadPicker());search?.addEventListener('input',()=>{clearTimeout(searchTimer);pickerState.q=search.value.trim();searchTimer=setTimeout(()=>loadPicker(1),260)});foldersHost?.addEventListener('click',event=>{const folder=event.target.closest('[data-media-folder]');if(folder){pickerState.folder=Number(folder.dataset.mediaFolder);loadPicker(1);return}const remove=event.target.closest('[data-media-folder-remove]');if(!remove)return;const id=Number(remove.dataset.mediaFolderRemove);window.SenseCMSUI?.modal?.('Delete empty folder','The folder will be removed. Media and child folders are protected automatically.',async()=>{try{await request(`/content/media/folders/${id}/delete`,json({}));if(pickerState.folder===id)pickerState.folder=-1;await loadPicker(1);toast('success',window.SenseCMSInterfaceText('Empty media folder removed.'))}catch(error){toast('error',error.message)}})});pages?.addEventListener('click',event=>{const page=event.target.closest('[data-media-page]');if(page&&!page.disabled)loadPicker(Number(page.dataset.mediaPage))});grid?.addEventListener('click',event=>{if(event.target.closest('[data-media-empty-upload]')){upload();return}const trash=event.target.closest('[data-media-trash]');if(!trash)return;const id=Number(trash.dataset.mediaTrash);window.SenseCMSUI?.modal?.('Move media to trash','The unused asset will remain recoverable in Media Library trash.',async()=>{try{await request(`/content/media/${id}/trash`,json({}));await loadPicker(pickerState.page);toast('success',window.SenseCMSInterfaceText('Media moved to trash.'))}catch(error){toast('error',error.message)}})});
picker?.querySelector('[data-media-folder-create]')?.addEventListener('click',()=>{folderName.value='';folderEditor.hidden=false;folderName.focus()});picker?.querySelectorAll('[data-media-folder-cancel]').forEach(button=>button.addEventListener('click',()=>folderEditor.hidden=true));picker?.querySelector('[data-media-folder-save]')?.addEventListener('click',async()=>{const name=folderName.value.trim();if(!name){folderName.focus();return}try{const payload=await request('/content/media/folders',json({name,facility_id:facility(),parent_id:pickerState.folder>0?pickerState.folder:''}));pickerState.folder=Number(payload.data?.folder?.id||-1);folderEditor.hidden=true;await loadPicker(1);toast('success',window.SenseCMSInterfaceText('Media folder created.'))}catch(error){toast('error',error.message)}});folderName?.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();picker.querySelector('[data-media-folder-save]')?.click()}if(event.key==='Escape')folderEditor.hidden=true});
window.SenseCMSMedia={open:openPicker,upload};
picker.addEventListener('click',event=>{if(event.target===picker)closePicker()});
document.addEventListener('sensecms:navigated',closePicker);
document.addEventListener('click',event=>{
 const button=event.target.closest('[data-library-image],[data-library-upload]');if(!button)return;
 const group=button.closest('[data-seo-image],.sensecms-image-field'),field=group?.querySelector('input:not([type=hidden])');
 if(!field)return;
 const form=field.form,selection={kind:'image',anchor:field,facility:form?.elements.namedItem('facility_id')?.value||'',select:item=>{
   field.value=item.url;
   field.dispatchEvent(new Event('input',{bubbles:true}));
   const key=field.name==='default_social_image'?suffix=>'default_image_'+suffix:field.name.endsWith('[og_image]')?suffix=>field.name.replace('[og_image]','[og_image_'+suffix+']'):null;
   if(key)for(const [suffix,value] of [['width',item.width],['height',item.height],['type',item.mime]]){
     const control=form?.elements.namedItem(key(suffix));if(control)control.value=value||'';
   }
 }};
 if(button.hasAttribute('data-library-upload'))upload(selection);else openPicker(selection);
});
})();
document.addEventListener('click', event => {
    document.querySelectorAll('.sensecms-row-more[open]').forEach(details => { if (!details.contains(event.target)) details.open = false; });
});
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const details = document.activeElement?.closest('.sensecms-row-more[open]');
    if (details) { details.open = false; details.querySelector('summary').focus(); event.preventDefault(); }
});
document.addEventListener('invalid', event => {
    let details = event.target.closest?.('details');
    while (details) { details.open = true; details = details.parentElement?.closest('details'); }
}, true);

document.addEventListener('input', event => {
    if (!event.target.matches('[data-facility-search]')) return;
    const root = event.target.closest('.sensecms-facility-directory');
    const query = event.target.value.trim().toLocaleLowerCase();
    let visible = 0;
    root.querySelectorAll('[data-facility-item]').forEach(item => {
        item.hidden = !item.dataset.facilitySearchText.toLocaleLowerCase().includes(query);
        if (!item.hidden) visible++;
    });
    root.querySelector('[data-facility-empty]').hidden = visible > 0;
});

document.addEventListener('submit', event => {
    const form = event.target.closest('[data-facility-action]');
    const guard = window.SenseCMSUnsavedGuard;
    if (!form || !guard?.dirty() || guard.isBypassing()) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const submitter = event.submitter;
    guard.confirmLeave(() => form.requestSubmit(submitter));
}, true);
