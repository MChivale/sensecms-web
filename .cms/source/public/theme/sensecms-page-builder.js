window.SenseCMSInterfaceText ??= source => source;
window.SenseCMSInterfaceHtml ??= source => source;
(()=>{
const clone=value=>JSON.parse(JSON.stringify(value??{})),dateInput=value=>String(value||'').replace(' ','T').slice(0,16),icon=(name,cls='')=>{const el=document.createElement('i');el.dataset.lucide=name;el.className=cls;return el},button=(label,name,title=label)=>{const el=document.createElement('button');el.type='button';el.dataset.action=name;el.title=title;el.setAttribute('aria-label',title);el.append(icon(label));return el},uuid=()=>crypto.randomUUID?.()||'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const n=Math.random()*16|0;return(c==='x'?n:(n&3)|8).toString(16)});
const boot=()=>{
 const root=document.querySelector('[data-page-builder]');if(!root||root.dataset.bound)return;root.dataset.bound='1';window.__senseCMSBuilderLifecycle?.abort();const lifecycle=new AbortController();window.__senseCMSBuilderLifecycle=lifecycle;
 const payload=JSON.parse(root.querySelector('[data-builder-payload]').textContent),catalog=payload.catalog,patterns=payload.patterns||[],layouts=payload.layouts||[],appearances=payload.appearance||{},collectionOptions=payload.collection_options||{},languages=payload.languages,aiProviders=payload.ai_providers||[],aiActions=payload.ai_actions||{},list=root.querySelector('[data-builder-list]'),empty=root.querySelector('[data-builder-empty]'),count=root.querySelector('[data-builder-count]'),stateBox=root.querySelector('[data-builder-state]'),saveButton=root.querySelector('[data-builder-save]'),undoButton=root.querySelector('[data-builder-undo]'),redoButton=root.querySelector('[data-builder-redo]'),inspector=root.querySelector('[data-builder-inspector]'),qualityBox=root.querySelector('[data-builder-quality]'),reviewBox=root.querySelector('[data-builder-review]'),aiBox=root.querySelector('[data-builder-ai]'),canEdit=!!payload.permissions?.edit,canReview=!!payload.permissions?.review,canAi=!!payload.permissions?.ai;
 let liveFrame=root.querySelector('[data-builder-live-frame]'),pendingFrame=null;
 let version=Number(payload.page.version||0),activeLocale=window.SenseCMSContentLanguage?.current()||(languages.find(item=>Number(item.is_default))||languages[0]||{locale:'en'}).locale,dirty=false,saving=false,saveError=false,draftSaving=false,draftError=false,draftSavedAt='',draftFingerprint='',previewFingerprint='',liveMode=false,inspectorUid='',dragUid='',selected=new Set(),clipboard=payload.clipboard||null,globals=payload.globals||[],revisions=payload.revisions||[],collaboration=payload.collaboration||{workflow:{},comments:{},locks:{},open_comments:0},collaborators=payload.collaborators||[],revisionSelection=new Set(),quality=payload.quality||null,qualityLoading=false,qualityFingerprint='',aiProposal=null,aiBaseFingerprint='',aiAbort=null,shareTarget=null,renameTarget=null,layoutTarget=null,lastTypingField=null,lastTypingAt=0,draftTimer=0,draftMaxTimer=0,liveTimer=0,qualityTimer=0,collaborationTimer=0,lockTimer=0,lockedUid='',history=[],historyIndex=-1,blocks=(payload.blocks||[]).filter(block=>catalog[block.type]).map(normalize),savedFingerprint='';root.classList.toggle('is-review-only',!canEdit);lifecycle.signal.addEventListener('abort',()=>{clearTimeout(draftTimer);clearTimeout(draftMaxTimer);clearTimeout(liveTimer);clearTimeout(qualityTimer);clearTimeout(collaborationTimer);clearInterval(lockTimer);aiAbort?.abort()},{once:true});
 inspectorUid = blocks[0]?.uid || '';
 function localeDefaults(definition,locale){return clone(definition.localized_defaults?.[locale]||definition.defaults)}
 function defaultLayout(){const point=()=>({span:12,order:0,hidden:false});return{group:null,mode:'stack',container:'content',gap:'medium',align:'stretch',wrap:true,desktop:point(),tablet:point(),mobile:point()}}
 function defaultAppearance(){return{variant:'default',surface:'default',spacing:'default',radius:'default',align:'default'}}
 function normalizeAppearance(value){const result=defaultAppearance(),input=value&&typeof value==='object'?value:{};Object.entries(appearances).forEach(([key,definition])=>{const choice=String(input[key]||'default');result[key]=Object.prototype.hasOwnProperty.call(definition.options||{},choice)?choice:'default'});return result}
 function normalizeLayout(value){const base=defaultLayout(),input=value&&typeof value==='object'?value:{},allowed=(value,values,fallback)=>values.includes(value)?value:fallback,point=name=>{const item=input[name]&&typeof input[name]==='object'?input[name]:{};return{span:Math.max(1,Math.min(12,Number(item.span)||12)),order:Math.max(-20,Math.min(20,Number(item.order)||0)),hidden:!!item.hidden}};return{group:/^[a-f0-9-]{36}$/.test(String(input.group||''))?String(input.group):null,mode:allowed(input.mode,['stack','columns','grid','sidebar'],base.mode),container:allowed(input.container,['content','wide','full'],base.container),gap:allowed(input.gap,['none','small','medium','large'],base.gap),align:allowed(input.align,['start','center','end','stretch'],base.align),wrap:input.wrap!==false,desktop:point('desktop'),tablet:point('tablet'),mobile:point('mobile')}}
 function normalize(block){const definition=catalog[block.type],localized={};languages.forEach(language=>localized[language.locale]=Object.assign(localeDefaults(definition,language.locale),clone(block.localized?.[language.locale]||{})));return{uid:block.uid||uuid(),type:block.type,source:block.source_theme||block.source||definition.source,global_section_id:Number(block.global_section_id||0)||null,global_name:String(block.global_name||''),global_version:Number(block.global_version??block.version??0),visible:block.visible===true||Number(block.visible)===1,visible_from:dateInput(block.visible_from),visible_until:dateInput(block.visible_until),layout:normalizeLayout(block.layout),appearance:normalizeAppearance(block.appearance),shared:Object.assign(clone(definition.shared_defaults),clone(block.shared||{})),localized}}
 const request=async(url,options={})=>{const response=await fetch(url,{...options,headers:{Accept:'application/json','X-SenseCMS-Request':'1',...(options.headers||{})}}),result=await response.json();if(!response.ok||!result.ok){const error=new Error(result.message||window.SenseCMSInterfaceText('The operation could not be completed.'));error.status=response.status;throw error}return result};
 let themeEdits=[];
 const toast=(type,message)=>window.SenseCMSUI?.toast(type,message),blockPayload=()=>blocks.map(({uid,type,source,global_section_id,global_version,visible,visible_from,visible_until,layout,appearance,shared,localized})=>({uid,type,source,global_section_id,global_version,visible,visible_from,visible_until,layout,appearance,shared,localized})),fingerprint=()=>JSON.stringify({blocks:blockPayload(),themeEdits});
 const pendingEdit=(id,locale)=>themeEdits.find(e=>e.id===id&&e.locale===locale);
 function queueThemeEdit(edit,base){
   if(saving)throw Error(message('Wait for publication to finish.','Poczekaj na zakończenie publikacji.'));
   const previous=pendingEdit(edit.id,edit.locale);
   edit.baseValues=previous?previous.baseValues:base;
   if(previous)edit.revision=previous.revision;
   themeEdits=themeEdits.filter(e=>e!==previous);
   if(Object.keys(base).some(k=>edit.values[k]!==base[k]))themeEdits.push(edit);
   setDirty(); if(!dirty&&liveMode)refreshLivePreview();
 }
 function updateHistoryControls(){undoButton.disabled=!canEdit||historyIndex<1;redoButton.disabled=!canEdit||historyIndex<0||historyIndex>=history.length-1}
 const visitorBox=root.querySelector('[data-visitor-state]'),retryDraft=root.querySelector('[data-retry-draft]');
 let openedAt=performance.now();
 let visibilityTimer=0,publicationUnconfirmed=false;
 const message=(en,pl)=>activeLocale==='pl'?pl:en;
 const setText=(node,value)=>{if(node.textContent!==value)node.textContent=value};
 function publicationState(){
   if(payload.page.visibility!=='public')return 'hidden';
   if(payload.page.status==='published')return 'live';
   if(payload.page.status!=='scheduled'||!payload.page.publishAt)return 'hidden';
   const remaining=payload.page.publishAt*1000-(payload.page.serverTime*1000+performance.now()-openedAt);
   if(remaining<=0)return 'live';
   if(!visibilityTimer)visibilityTimer=setTimeout(()=>{visibilityTimer=0;updateState()},Math.min(remaining+50,2147483647));
   return 'scheduled';
 }
 lifecycle.signal.addEventListener('abort',()=>clearTimeout(visibilityTimer),{once:true});
 let mergeNeeded=false,mergeOpen=false;
 const mergeLabel=()=>message('Merge draft with newer version','Połącz szkic z nowszą wersją');
 const needsLeaveWarning=()=>saving||draftSaving||(dirty&&(draftError||fingerprint()!==draftFingerprint));
 function updateState(){
   const publication=publicationState(),live=publication==='live',recovered=dirty&&draftFingerprint===fingerprint()&&!draftError;
   const draftText=!canEdit?message('Review only','Tylko do odczytu'):
     !dirty?message('All edits saved','Wszystkie zmiany zapisane'):
     draftError?message('Draft save failed','Nie udało się zapisać szkicu'):
     recovered?message('Saved privately','Zapisany prywatnie'):
     draftSaving?message('Saving draft…','Zapisywanie szkicu…'):message('Unsaved edits','Niezapisane zmiany');
   const detail=mergeNeeded&&dirty?message('A newer version is available.','Dostępna jest nowsza wersja strony.'):draftError?message('Keep this tab open and retry.','Pozostaw kartę otwartą i ponów zapis.'):
     saveError?message('Last page save was not confirmed.','Ostatni zapis strony nie został potwierdzony.'):
     dirty&&!recovered&&!draftSaving?message('Only in this tab','Tylko w tej karcie'):'';
   stateBox.toggleAttribute('data-builder-unsaved',needsLeaveWarning());
   stateBox.className='sensecms-builder-save-state'+(!canEdit?' is-review':draftError?' is-error':recovered?' is-recovered':draftSaving?' is-saving':dirty?' is-dirty':'');
   setText(stateBox.querySelector('[data-state-caption]'),message('Draft','Szkic'));
   setText(stateBox.querySelector('b'),draftText);
   setText(stateBox.querySelector('[data-builder-state-detail]'),detail);
   retryDraft.hidden=!canEdit||!dirty||(!draftError&&!mergeNeeded)||draftSaving||saving;
   setText(retryDraft,mergeNeeded?mergeLabel():message('Retry draft save','Ponów zapis szkicu'));
   let visitorText=message('Page is not public','Strona nie jest publiczna');
   if(publication==='scheduled'){
     const date=new Intl.DateTimeFormat(activeLocale,{dateStyle:'medium',timeStyle:'short',timeZone:payload.page.timezone}).format(new Date(payload.page.publishAt*1000));
     visitorText=message('Scheduled for ','Zaplanowana na ')+date+' ('+payload.page.timezone+')';
   }else if(live){
     visitorText=publicationUnconfirmed?message('Publication unconfirmed — reload to check','Brak potwierdzenia publikacji — odśwież, aby sprawdzić'):
       saving?message('Publishing changes…','Publikowanie zmian…'):
       dirty?message('Previous version is live','Na stronie jest poprzednia wersja'):
       message('Current version is live','Na stronie jest bieżąca wersja');
   }
   visitorBox.classList.toggle('is-live',live&&!publicationUnconfirmed);
   setText(visitorBox.querySelector('[data-state-caption]'),message('Visitors','Odwiedzający'));
   setText(visitorBox.querySelector('b'),visitorText);
   setText(saveButton.querySelector('[data-save-label]'),live?message('Publish changes','Opublikuj zmiany'):message('Save changes','Zapisz zmiany'));
   const changes=root.querySelector('[data-changes-open]');if(changes)changes.hidden=!dirty;
   if(root.querySelector('[data-changes-dialog]')?.open)renderChangeSummary();saveButton.disabled=!canEdit||saving||!dirty;
 }
 retryDraft.onclick=()=>mergeNeeded?openDraftMerge():flushDraft();
 function renderChangeSummary() {
   const before=JSON.parse(savedFingerprint).blocks, after=blockPayload();
   const oldById=new Map(before.map(block=>[block.uid,block])), newById=new Map(after.map(block=>[block.uid,block]));
   const originalOrder=before.filter(block=>newById.has(block.uid)).map(block=>block.uid);
   const currentOrder=after.filter(block=>oldById.has(block.uid)).map(block=>block.uid);
   const rows=[];
   function addRow(block,notes) {
     const row=document.createElement('li'), title=document.createElement('strong'), description=document.createElement('span');
     title.textContent=blockTitle(block);
     description.textContent=notes.join(' · ');
     row.append(title,description);
     rows.push(row);
   }
   for(const block of after) {
     const old=oldById.get(block.uid);
     if(!old) { addRow(block,['Added']); continue; }
     const notes=[];
     if(originalOrder.indexOf(block.uid)!==currentOrder.indexOf(block.uid))
       notes.push('Order changed (now '+(after.indexOf(block)+1)+')');
     const locales=languages.filter(({locale})=>JSON.stringify(old.localized[locale])!==JSON.stringify(block.localized[locale]));
     if(locales.length)notes.push('Content: '+locales.map(({locale})=>locale.toUpperCase()).join(', '));
     const settings=Object.keys(block).filter(key=>key!=='localized'&&JSON.stringify(old[key])!==JSON.stringify(block[key]));
     if(settings.length)notes.push('Section settings');
     if(block.global_section_id&&(locales.length||settings.includes('shared')))notes.push('Shared across linked pages');
     if(notes.length)addRow(block,notes);
   }
   for(const block of before)if(!newById.has(block.uid))addRow(block,['Removed']);
   for(const edit of themeEdits){const row=document.createElement('li');row.textContent=(edit.id==='banner'?'AI Chatbot':message('Theme element','Element motywu'))+' · '+edit.locale.toUpperCase();rows.push(row)}
   if(!rows.length){const row=document.createElement('li');row.textContent=window.SenseCMSInterfaceText('All changes saved');rows.push(row)}
   root.querySelector('[data-changes-list]').replaceChildren(...rows);
 }
 root.querySelector('[data-changes-open]')?.addEventListener('click',()=>{
   renderChangeSummary();
   openDialog(root.querySelector('[data-changes-dialog]'));
 });
 function recordHistory(current, typingField) {
   if (history[historyIndex] === current) return;
   const now = performance.now();
   const merge = typingField && typingField === lastTypingField && now - lastTypingAt < 350 && historyIndex === history.length - 1;
   history.length = historyIndex + 1;
   if (merge) history[historyIndex] = current;
   else history.push(current);
   if (history.length > 50) history.shift();
   historyIndex = history.length - 1;
   lastTypingField = typingField;
   lastTypingAt = now;
   updateHistoryControls();
 }
 function scheduleDraft(){clearTimeout(draftTimer);if(!canEdit||!dirty)return;draftTimer=setTimeout(()=>flushDraft(),2500);if(!draftMaxTimer)draftMaxTimer=setTimeout(()=>{draftMaxTimer=0;flushDraft()},15000)}
 function setDirty(value=true,{remember=true,recover=true,typingField=null}={}){if(!canEdit)return;const wasDirty=dirty;const current=fingerprint();dirty=!!value&&current!==savedFingerprint;if(value&&remember)recordHistory(current,typingField);if(dirty){saveError=false;draftError=false;if(recover)scheduleDraft();scheduleQuality();if(liveMode){clearTimeout(liveTimer);setLiveStatus('Updating private preview…','is-updating');liveTimer=setTimeout(()=>flushDraft({quiet:true}),700)}}else if(wasDirty&&draftFingerprint&&!draftSaving)discardDraft(true);updateState()}
 function applyHistory(index){if(index<0||index>=history.length||index===historyIndex)return;lastTypingField=null;historyIndex=index;const entry=JSON.parse(history[index]);themeEdits=entry.themeEdits;blocks=entry.blocks.filter(block=>catalog[block.type]).map(normalize);render();setDirty(true,{remember:false});updateHistoryControls()}
 async function flushDraft(options={}){if(!canEdit||saving||mergeOpen||mergeNeeded)return;if(!dirty){if(liveMode&&previewFingerprint!==fingerprint())refreshLivePreview();return}clearTimeout(draftTimer);clearTimeout(draftMaxTimer);clearTimeout(liveTimer);draftMaxTimer=0;if(draftSaving){scheduleDraft();return}const current=fingerprint(),baseVersion=version;if(current===draftFingerprint){if(liveMode&&previewFingerprint!==current)refreshLivePreview();updateState();return}draftSaving=true;draftError=false;updateState();try{const result=await request('/content/builder/'+payload.page.id+'/draft',{method:'POST',keepalive:!!options.keepalive,headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf,version,blocks:blockPayload(),themeEdits,openedBase:JSON.parse(savedFingerprint).blocks})});if(saving||baseVersion!==version)return;if(!dirty){await discardDraft(true);return}draftFingerprint=current;draftSavedAt=result.data.updated_at||'';if(liveMode&&current===fingerprint())refreshLivePreview()}catch(error){if(dirty&&!saving&&baseVersion===version){draftError=true;if(error.status===409)mergeNeeded=true;setLiveStatus('Preview update failed','is-error');if(!options.quiet)toast('error',error.message||window.SenseCMSInterfaceText('The recovery draft could not be saved.'))}}finally{draftSaving=false;if(!dirty)draftError=false;updateState()}}
 async function discardDraft(quiet=false){clearTimeout(draftTimer);clearTimeout(draftMaxTimer);draftMaxTimer=0;try{await request('/content/builder/'+payload.page.id+'/draft/discard',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf})});draftFingerprint='';draftSavedAt='';if(liveMode&&!dirty)refreshLivePreview();if(dirty)scheduleDraft();return true}catch(error){if(!quiet)toast('error',error.message||window.SenseCMSInterfaceText('The recovery draft could not be discarded.'));return false}}
 const filled=value=>Array.isArray(value)?value.length>0:String(value??'').trim()!=='';
 const coverage=block=>{const fields=catalog[block.type].fields,all=fields.length*languages.length||1,done=languages.reduce((total,language)=>total+fields.filter(field=>filled(block.localized[language.locale]?.[field.key])).length,0);return Math.round(done/all*100)};
 const blockTitle=block=>String(block.localized[activeLocale]?.title||block.localized[activeLocale]?.eyebrow||catalog[block.type].label);
 function scheduleQuality(immediate=false){clearTimeout(qualityTimer);qualityTimer=setTimeout(()=>loadQuality(),immediate?0:650)}
 async function loadQuality(force=false){const current=fingerprint();if(qualityLoading)return scheduleQuality();if(!force&&quality&&qualityFingerprint===current){renderQuality();return}qualityLoading=true;renderQuality();try{const result=await request('/content/builder/'+payload.page.id+'/quality',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf,blocks:blockPayload()})});quality=result.data;qualityFingerprint=current}catch(error){toast('error',error.message||window.SenseCMSInterfaceText('The quality inspection could not be completed.'));quality={ready:false,score:0,counts:{error:1,warning:0,advice:0},issues:[{severity:'error',category:'System',message:error.message||'The quality inspection could not be completed.'}]}}finally{qualityLoading=false;renderQuality()}}
 function qualityTarget(issue){if(issue.block_uid){if(issue.locale&&languages.some(language=>language.locale===issue.locale)){activeLocale=issue.locale;localeNavigation()}selectCanvasBlock(issue.block_uid,true);return}const url=issue.target==='seo'?'/seo?document='+payload.page.id:issue.target==='page'?'/content/pages/'+payload.page.id+'/edit':'';if(!url)return;const go=()=>location.assign(window.SenseCMSContentLanguage?.url(url)||url),guard=window.SenseCMSUnsavedGuard;if(!needsLeaveWarning())go();else if(guard?.confirmLeave)guard.confirmLeave(go);else window.SenseCMSUI?.modal('Leave unsaved changes?','Your recent Page Builder changes have not been saved.',go)}
 function renderQuality(){if(!qualityBox)return;const actionCount=root.querySelector('[data-builder-quality-action-count]'),tabCount=root.querySelector('[data-builder-quality-count]');if(qualityLoading&&!quality){qualityBox.innerHTML=("<div class=\"sensecms-builder-global-empty\"><i data-lucide=\"loader-circle\"></i><strong>"+window.SenseCMSInterfaceHtml("Checking the current draft")+"</strong><span>"+window.SenseCMSInterfaceHtml("Core is reviewing content, accessibility, SEO and responsive behaviour.")+"</span></div>");return}const report=quality||{ready:true,score:100,counts:{error:0,warning:0,advice:0},issues:[]},counts=report.counts||{},issues=report.issues||[],important=Number(counts.error||0)+Number(counts.warning||0);if(actionCount)actionCount.textContent=String(important);if(tabCount)tabCount.textContent=String(issues.length);const summary=document.createElement('header');summary.className='sensecms-builder-quality-summary '+(report.ready?'is-ready':'is-blocked');summary.innerHTML='<div class="sensecms-builder-quality-score"><b>'+Number(report.score||0)+("</b><span>/100</span></div><div><small>"+window.SenseCMSInterfaceHtml("PRE-PUBLICATION CHECK")+"</small><strong>")+(report.ready?'No blocking issues':'Resolve blocking issues')+'</strong><span>'+Number(report.checked_sections||blocks.length)+' sections · '+Number(report.checked_languages||languages.length)+' languages</span></div>'+(qualityLoading?'<i class="sensecms-builder-quality-spinner" data-lucide="loader-circle"></i>':'<i data-lucide="'+(report.ready?'shield-check':'shield-alert')+'"></i>');const counters=document.createElement('div');counters.className='sensecms-builder-quality-counters';[['error','Errors','circle-x'],['warning','Warnings','triangle-alert'],['advice','Advice','lightbulb']].forEach(([severity,label,glyph])=>{const item=document.createElement('span');item.className='is-'+severity;item.append(icon(glyph));const copy=document.createElement('b'),small=document.createElement('small');copy.textContent=String(counts[severity]||0);small.textContent=label;item.append(copy,small);counters.append(item)});const list=document.createElement('div');list.className='sensecms-builder-quality-list';if(!issues.length)list.innerHTML=("<div class=\"sensecms-builder-quality-empty\"><i data-lucide=\"badge-check\"></i><strong>"+window.SenseCMSInterfaceHtml("This draft passes every current Core check")+"</strong><span>"+window.SenseCMSInterfaceHtml("Review the live canvas once more, then save or publish with confidence.")+"</span></div>");else issues.forEach(issue=>{const row=document.createElement('button');row.type='button';row.className='sensecms-builder-quality-issue is-'+issue.severity;const tile=document.createElement('i');tile.append(icon(issue.severity==='error'?'circle-x':issue.severity==='warning'?'triangle-alert':'lightbulb'));const copy=document.createElement('span'),category=document.createElement('small'),message=document.createElement('strong'),meta=document.createElement('em');category.textContent=issue.category||window.SenseCMSInterfaceText('Quality');message.textContent=issue.message||window.SenseCMSInterfaceText('Review this item.');meta.textContent=[issue.locale?issue.locale.toUpperCase():'',issue.field||'',issue.block_uid?'Open section':issue.target?'Open settings':''].filter(Boolean).join(' · ');copy.append(category,message);if(meta.textContent)copy.append(meta);row.append(tile,copy);if(issue.block_uid||issue.target){row.append(icon('arrow-up-right'));row.onclick=()=>qualityTarget(issue)}else row.disabled=true;list.append(row)});qualityBox.replaceChildren(summary,counters,list);window.lucide?.createIcons()}
 function updateAiTarget(){if(!aiBox)return;const form=aiBox.querySelector('[data-builder-ai-form]');if(!form)return;const block=blocks.find(item=>item.uid===inspectorUid),target=aiBox.querySelector('[data-builder-ai-target]');target.textContent=block?blockTitle(block)+' · '+catalog[block.type].label:window.SenseCMSInterfaceText('No section selected');const locale=form.elements.locale;if(locale&&languages.some(item=>item.locale===activeLocale))locale.value=activeLocale;updateAiScopes()}
 function updateAiScopes(){if(!aiBox)return;const form=aiBox.querySelector('[data-builder-ai-form]');if(!form)return;const option=form.elements.action.selectedOptions[0],allowed=String(option?.dataset.scopes||'page').split(','),scope=form.elements.scope;[...scope.options].forEach(item=>item.disabled=!allowed.includes(item.value));if(!allowed.includes(scope.value))scope.value=allowed[0]||'page';if(scope.value==='section'&&!blocks.some(item=>item.uid===inspectorUid)&&allowed.includes('page'))scope.value='page'}
 function renderAiProposal(){if(!aiBox)return;const target=aiBox.querySelector('[data-builder-ai-result]');if(!target)return;if(!aiProposal){target.hidden=true;target.replaceChildren();return}const diff=aiProposal.diff||{},summary=diff.summary||{},head=document.createElement('header'),headIcon=icon('sparkles'),copy=document.createElement('div'),overline=document.createElement('small'),title=document.createElement('strong'),meta=document.createElement('span');overline.textContent='AI PROPOSAL · '+String(aiProposal.provider?.name||'Provider');title.textContent=String(aiProposal.summary||'Proposal ready for review.');meta.textContent=String(aiProposal.provider?.model||'')+' · '+String(aiProposal.locale||'').toUpperCase();copy.append(overline,title,meta);head.append(headIcon,copy);const stats=document.createElement('div');stats.className='sensecms-builder-ai-stats';[['added','Added'],['removed','Removed'],['changed','Changed'],['moved','Moved']].forEach(([key,label])=>{const item=document.createElement('span'),count=document.createElement('b'),caption=document.createElement('small');count.textContent=String(Number(summary[key]||0));caption.textContent=label;item.append(count,caption);stats.append(item)});const note=document.createElement('p'),noteCopy=document.createElement('span'),noteTitle=document.createElement('strong'),noteText=document.createElement('small');noteTitle.textContent=window.SenseCMSInterfaceText('Nothing has been saved');noteText.textContent=window.SenseCMSInterfaceText('Apply this proposal to the local draft, inspect it in Live Canvas, then save manually.');noteCopy.append(noteTitle,noteText);note.append(icon('info'),noteCopy);const actions=document.createElement('footer'),discard=document.createElement('button'),apply=document.createElement('button');discard.type=apply.type='button';discard.className='btn bg-default-150';apply.className='btn bg-primary text-white';discard.append(icon('x'),document.createTextNode(window.SenseCMSInterfaceText('Discard')));apply.append(icon('check'),document.createTextNode(window.SenseCMSInterfaceText('Apply to draft')));discard.onclick=()=>{aiProposal=null;aiBaseFingerprint='';renderAiProposal()};apply.onclick=()=>{if(fingerprint()!==aiBaseFingerprint){toast('warning',window.SenseCMSInterfaceText('The page changed after this proposal was generated. Generate a fresh proposal.'));return}blocks=(aiProposal.blocks||[]).filter(block=>catalog[block.type]).map(normalize);inspectorUid=blocks[0]?.uid||'';aiProposal=null;aiBaseFingerprint='';render();setDirty(true);switchLibraryTab('ai');toast('success',window.SenseCMSInterfaceText('AI proposal applied to the unsaved draft. Review it before saving.'))};actions.append(discard,apply);target.hidden=false;target.replaceChildren(head,stats,note,actions);window.lucide?.createIcons()}
 async function runAiProposal(form){if(!canAi||aiAbort)return;updateAiScopes();const data=new FormData(form),scope=String(data.get('scope')||'page');if(scope==='section'&&!blocks.some(item=>item.uid===inspectorUid)){toast('warning',window.SenseCMSInterfaceText('Select a section before using a section-scoped AI action.'));return}const run=form.querySelector('[data-builder-ai-run]'),cancel=form.querySelector('[data-builder-ai-cancel]'),result=aiBox.querySelector('[data-builder-ai-result]');aiBaseFingerprint=fingerprint();aiProposal=null;run.hidden=true;cancel.hidden=false;result.hidden=false;const loading=document.createElement('div');loading.className='sensecms-builder-ai-loading';const loadingTitle=document.createElement('strong'),loadingText=document.createElement('span');loadingTitle.textContent=window.SenseCMSInterfaceText('Preparing a controlled proposal');loadingText.textContent=window.SenseCMSInterfaceText('The provider receives only this selected Builder context.');loading.append(icon('loader-circle'),loadingTitle,loadingText);result.replaceChildren(loading);window.lucide?.createIcons();aiAbort=new AbortController();cancel.onclick=()=>aiAbort?.abort();try{const response=await request('/content/builder/'+payload.page.id+'/ai',{method:'POST',signal:aiAbort.signal,headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf,provider:data.get('provider'),action:data.get('action'),scope,locale:data.get('locale'),tone:data.get('tone'),audience:data.get('audience'),instructions:data.get('instructions'),section_uid:inspectorUid,blocks:blockPayload()})});aiProposal=response.data;renderAiProposal()}catch(error){if(error.name==='AbortError'){result.hidden=true;toast('warning',window.SenseCMSInterfaceText('AI request cancelled.'))}else{const failure=document.createElement('div'),failureTitle=document.createElement('strong'),failureText=document.createElement('span');failure.className='sensecms-builder-ai-error';failureTitle.textContent=window.SenseCMSInterfaceText('Proposal unavailable');failureText.textContent=String(error.message||'Try another configured provider.');failure.append(icon('triangle-alert'),failureTitle,failureText);result.replaceChildren(failure);toast('error',error.message||window.SenseCMSInterfaceText('The AI proposal could not be completed.'));window.lucide?.createIcons()}}finally{aiAbort=null;run.hidden=false;cancel.hidden=true}}
 function workflowFor(uid){return collaboration.workflow?.[uid]||{status:'draft',assigned_user_id:null,assigned_name:''}}
 function commentsFor(uid){return Array.isArray(collaboration.comments?.[uid])?collaboration.comments[uid]:[]}
 function lockFor(uid){return collaboration.locks?.[uid]||null}
 function lockedByOther(uid){const lock=lockFor(uid);return!!lock&&!lock.is_mine}
 function workflowLabel(status){return status==='approved'?'Approved':status==='in_review'?'In review':'Draft'}
 function applyCollaboration(data){collaboration=data||collaboration;if(canEdit&&Number(data?.version)>version&&!saving){mergeNeeded=true;updateState()}const count=root.querySelector('[data-builder-review-count]');if(count)count.textContent=String(collaboration.open_comments||0);renderReview()}
 async function syncCollaboration(quiet=true){try{const result=await request('/api/content/builder/'+payload.page.id+'/collaboration');applyCollaboration(result.data)}catch(error){if(!quiet)toast('error',error.message||window.SenseCMSInterfaceText('Collaboration could not be refreshed.'))}finally{clearTimeout(collaborationTimer);collaborationTimer=setTimeout(()=>syncCollaboration(true),20000)}}
 async function releaseSection(uid=lockedUid){if(!canEdit||!uid)return;lockedUid=lockedUid===uid?'':lockedUid;clearInterval(lockTimer);try{const result=await request('/content/builder/'+payload.page.id+'/sections/'+encodeURIComponent(uid)+'/unlock',{method:'POST',keepalive:true,headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf})});applyCollaboration(result.data)}catch(error){}}
 async function reserveSection(uid){if(!canEdit||!uid)return true;if(!JSON.parse(savedFingerprint).blocks.some(block=>block.uid===uid))return true;if(lockedUid===uid){return true}const previous=lockedUid;if(previous)await releaseSection(previous);try{const result=await request('/content/builder/'+payload.page.id+'/sections/'+encodeURIComponent(uid)+'/lock',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf})});lockedUid=uid;applyCollaboration(result.data);clearInterval(lockTimer);lockTimer=setInterval(async()=>{if(lockedUid!==uid)return;try{const refreshed=await request('/content/builder/'+payload.page.id+'/sections/'+encodeURIComponent(uid)+'/lock',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf})});applyCollaboration(refreshed.data)}catch(error){lockedUid='';clearInterval(lockTimer);syncCollaboration()}},30000);return true}catch(error){lockedUid='';await syncCollaboration(true);toast('warning',error.message||window.SenseCMSInterfaceText('This section is being edited by another person.'));return false}}
 async function saveSectionWorkflow(uid,status,assigned){try{const result=await request('/content/builder/'+payload.page.id+'/sections/'+encodeURIComponent(uid)+'/workflow',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf,status,assigned_user_id:assigned||null})});applyCollaboration(result.data);render();switchLibraryTab('review');toast('success',result.message)}catch(error){toast('error',error.message);renderReview()}}
 async function postSectionComment(uid,body,button){button.disabled=true;try{const result=await request('/content/builder/'+payload.page.id+'/sections/'+encodeURIComponent(uid)+'/comments',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf,body})});applyCollaboration(result.data);toast('success',result.message);return true}catch(error){toast('error',error.message);return false}finally{button.disabled=false}}
 async function toggleSectionComment(comment,resolved,button){button.disabled=true;try{const result=await request('/content/builder/'+payload.page.id+'/comments/'+comment.id+'/resolve',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf,resolved})});applyCollaboration(result.data);toast('success',result.message)}catch(error){toast('error',error.message)}finally{button.disabled=false}}
 function renderReview(){if(!reviewBox)return;const block=blocks.find(item=>item.uid===inspectorUid);if(!block){reviewBox.innerHTML=("<div class=\"sensecms-builder-global-empty\"><i data-lucide=\"messages-square\"></i><strong>"+window.SenseCMSInterfaceHtml("Select a section to review")+"</strong><span>"+window.SenseCMSInterfaceHtml("Comments, assignment and approval status are stored independently for every section.")+"</span></div>");window.lucide?.createIcons();return}const state=workflowFor(block.uid),comments=commentsFor(block.uid),lock=lockFor(block.uid),head=document.createElement('header');head.className='sensecms-builder-review-head';const tile=document.createElement('i');tile.append(icon(catalog[block.type].icon));const copy=document.createElement('div'),name=document.createElement('strong'),meta=document.createElement('span');name.textContent=blockTitle(block);meta.textContent=catalog[block.type].label+' · '+comments.filter(item=>!item.resolved).length+' open comments';copy.append(name,meta);head.append(tile,copy);if(lock){const lockBadge=document.createElement('em');lockBadge.className=lock.is_mine?'is-mine':'is-locked';lockBadge.append(icon(lock.is_mine?'pencil-line':'lock-keyhole'),document.createTextNode(lock.is_mine?' Editing now':lock.user_name+' editing'));head.append(lockBadge)}const workflow=document.createElement('section');workflow.className='sensecms-builder-review-workflow';const workflowTitle=document.createElement('header');workflowTitle.innerHTML=("<strong>"+window.SenseCMSInterfaceHtml("Section workflow")+"</strong><span>"+window.SenseCMSInterfaceHtml("Independent from page publication")+"</span>");const controls=document.createElement('div'),statusLabel=document.createElement('label'),statusCaption=document.createElement('span'),status=document.createElement('select');statusCaption.textContent=window.SenseCMSInterfaceText('Status');[['draft','Draft'],['in_review','In review'],['approved','Approved']].forEach(([value,label])=>{const option=document.createElement('option');option.value=value;option.textContent=label;if(value==='approved'&&!canReview)option.disabled=true;status.append(option)});status.value=state.status||'draft';statusLabel.append(statusCaption,status);const assignLabel=document.createElement('label'),assignCaption=document.createElement('span'),assign=document.createElement('select'),unassigned=document.createElement('option');assignCaption.textContent=window.SenseCMSInterfaceText('Assigned to');unassigned.value='';unassigned.textContent=window.SenseCMSInterfaceText('Unassigned');assign.append(unassigned);collaborators.forEach(user=>{const option=document.createElement('option');option.value=String(user.id);option.textContent=user.name+(user.job_title?' · '+user.job_title:'');assign.append(option)});assign.value=state.assigned_user_id?String(state.assigned_user_id):'';assignLabel.append(assignCaption,assign);const update=()=>saveSectionWorkflow(block.uid,status.value,Number(assign.value)||null);status.onchange=update;assign.onchange=update;controls.append(statusLabel,assignLabel);workflow.append(workflowTitle,controls);const thread=document.createElement('section');thread.className='sensecms-builder-review-thread';const threadTitle=document.createElement('header'),threadName=document.createElement('strong'),threadCount=document.createElement('span');threadName.textContent=window.SenseCMSInterfaceText('Discussion');threadCount.textContent=comments.length+' '+(comments.length===1?'comment':'comments');threadTitle.append(threadName,threadCount);const list=document.createElement('div');list.className='sensecms-builder-review-comments';if(!comments.length){const blank=document.createElement('div');blank.className='sensecms-builder-review-empty';blank.append(icon('message-circle'));const blankText=document.createElement('span'),blankTitle=document.createElement('strong'),blankMeta=document.createElement('small');blankTitle.textContent=window.SenseCMSInterfaceText('No comments yet');blankMeta.textContent=window.SenseCMSInterfaceText('Start a focused discussion about this section.');blankText.append(blankTitle,blankMeta);blank.append(blankText);list.append(blank)}else comments.forEach(comment=>{const row=document.createElement('article');row.className='sensecms-builder-review-comment'+(comment.resolved?' is-resolved':'');const commentHead=document.createElement('header'),author=document.createElement('strong'),time=document.createElement('time'),body=document.createElement('p'),action=document.createElement('button');author.textContent=comment.author+(comment.is_mine?' · You':'');time.textContent=new Date(String(comment.created_at).replace(' ','T')).toLocaleString();commentHead.append(author,time);body.textContent=comment.body;action.type='button';action.append(icon(comment.resolved?'rotate-ccw':'check'),document.createTextNode(comment.resolved?' Reopen':' Resolve'));action.onclick=()=>toggleSectionComment(comment,!comment.resolved,action);row.append(commentHead,body,action);list.append(row)});const form=document.createElement('form'),textarea=document.createElement('textarea'),submit=document.createElement('button');textarea.className='form-input';textarea.rows=3;textarea.maxLength=2000;textarea.placeholder=window.SenseCMSInterfaceText('Add a precise, actionable comment…');submit.type='submit';submit.className='btn bg-primary text-white';submit.append(icon('send'),document.createTextNode(' Post comment'));form.append(textarea,submit);form.onsubmit=event=>{event.preventDefault();const body=textarea.value.trim();if(!body){textarea.focus();return}postSectionComment(block.uid,body,submit).then(saved=>{if(saved)textarea.value='';else textarea.focus()})};thread.append(threadTitle,list,form);reviewBox.replaceChildren(head,workflow,thread);window.lucide?.createIcons()}
 function visibleField(field,data){return !field.show_when||Object.entries(field.show_when).every(([key,value])=>String(data[key]??'')===String(value))}
 function contentChoices(field,data){if(field.options_source)return Array.isArray(collectionOptions[field.options_source])?collectionOptions[field.options_source]:[];const source=String(data[field.options_source_field]||'');return Array.isArray(collectionOptions.sources?.[source]?.items)?collectionOptions.sources[source].items:[]}
 function contentMultiselectNode(field,data,onChange){const label=document.createElement('section');label.className='sensecms-builder-field sensecms-builder-content-picker'+(field.wide?' is-wide':'');const caption=document.createElement('span'),count=document.createElement('small');caption.textContent=field.label;caption.append(count);const toolbar=document.createElement('div');toolbar.className='sensecms-builder-content-picker-toolbar';const search=document.createElement('input');search.type='search';search.className='form-input';search.placeholder=window.SenseCMSInterfaceText('Search published content…');toolbar.append(search);const list=document.createElement('div');list.className='sensecms-builder-content-picker-list';const selected=()=>Array.isArray(data[field.key])?data[field.key].map(Number).filter(value=>value>0):[];data[field.key]=selected();const paint=()=>{const values=selected(),chosen=new Set(values),query=search.value.trim().toLocaleLowerCase(),choices=contentChoices(field,data).map(item=>({...item,value:Number(item.value)}));values.filter(value=>!choices.some(item=>item.value===value)).forEach(value=>choices.push({value,label:'Unavailable item #'+value,meta:'Remove or republish this content'}));const filtered=choices.filter(item=>!query||`${item.label||''} ${item.meta||''}`.toLocaleLowerCase().includes(query));count.textContent=values.length+' / '+field.max+' selected';list.replaceChildren(...filtered.map(item=>{const row=document.createElement('label'),input=document.createElement('input'),copy=document.createElement('span'),name=document.createElement('strong'),meta=document.createElement('small');input.type='checkbox';input.checked=chosen.has(item.value);input.disabled=!input.checked&&values.length>=field.max;name.textContent=String(item.label||('Item #'+item.value));meta.textContent=String(item.meta||'Published content');copy.append(name,meta);row.append(input,copy);input.onchange=()=>{const current=selected(),index=current.indexOf(item.value);if(input.checked&&index<0&&current.length<field.max)current.push(item.value);else if(!input.checked&&index>=0)current.splice(index,1);data[field.key]=current;onChange(false);paint()};return row}));if(!filtered.length){const empty=document.createElement('p');empty.textContent=choices.length?window.SenseCMSInterfaceText('No matching published content.'):window.SenseCMSInterfaceText('No published content is available for this source.');list.append(empty)}};search.oninput=paint;label.append(caption,toolbar,list);paint();return label}
 function fieldNode(field,data,onChange,block=null,slideIndex=null){
   if(field.type==='repeater')return repeaterNode(field,data,onChange,block);if(field.type==='content-multiselect')return contentMultiselectNode(field,data,onChange);
   const label=document.createElement('label');label.className='sensecms-builder-field'+(field.wide?' is-wide':'');const caption=document.createElement('span');caption.textContent=field.label;if(field.required){const required=document.createElement('small');required.textContent=window.SenseCMSInterfaceText('Required');caption.append(required)}label.append(caption);
   let control;if(field.type==='select'||field.type==='content-select'){control=document.createElement('select');if(field.type==='select')Object.entries(field.options||{}).forEach(([value,text])=>{const option=document.createElement('option');option.value=value;option.textContent=text;control.append(option)});else{const empty=document.createElement('option');empty.value='';empty.textContent=field.empty_label||window.SenseCMSInterfaceText('Any');control.append(empty);const choices=contentChoices(field,data);choices.forEach(item=>{const option=document.createElement('option');option.value=String(item.value);option.textContent=String(item.label)+(item.meta?' · '+item.meta:'');control.append(option)});const current=String(data[field.key]??'');if(current&&!choices.some(item=>String(item.value)===current)){const missing=document.createElement('option');missing.value=current;missing.textContent='Unavailable item #'+current;control.append(missing)}}control.value=String(data[field.key]??'')}else if(field.type==='checkbox'){control=document.createElement('input');control.type='checkbox';control.checked=!!data[field.key];label.classList.add('is-checkbox')}else{control=field.type==='textarea'||field.type==='code'?document.createElement('textarea'):document.createElement('input');control.value=String(data[field.key]??'');if(field.type==='textarea'||field.type==='code'){control.rows=field.type==='code'?10:4;if(field.type==='code')control.classList.add('is-code')}else control.type=field.type==='number'?'number':field.type==='email'?'email':'text';if(field.min!==undefined)control.min=field.min;if(field.max!==undefined&&field.type==='number')control.max=field.max;else control.maxLength=field.max||500}control.classList.add(field.type==='checkbox'?'form-checkbox':field.type==='select'||field.type==='content-select'?'form-select':'form-input');control.required=!!field.required;control.addEventListener(field.type==='checkbox'?'change':'input',()=>{data[field.key]=field.type==='checkbox'?control.checked:field.type==='number'?Number(control.value):control.value;if(field.key==='source'&&Array.isArray(data.selected_ids))data.selected_ids=[];onChange(field.type==='select'||field.type==='content-select', control.matches('textarea,input[type=text],input[type=email],input[type=number]') ? control : null)});
   if(['image','video'].includes(field.type)){const media=document.createElement('div');media.className='sensecms-builder-media';media.append(control);const choose=document.createElement('button'),upload=document.createElement('button');choose.type=upload.type='button';choose.textContent=window.SenseCMSInterfaceText('Media Library');upload.textContent='Upload '+field.type;choose.addEventListener('click',()=>openMedia(field.type,control,label));upload.addEventListener('click',()=>uploadMedia(field.type,control,label,onChange));media.append(choose,upload);label.append(media);renderMediaPreview(label,control.value,field.type)}else label.append(control);
   if(block&&field.key==='text'&&(slideIndex!==null||['hero','gallery','admissions','story','values','programs','statistics','motion','news','cta','text','image-text'].includes(block.type))){
     const edit=document.createElement('button');edit.type='button';edit.className='btn bg-default-150';edit.textContent=window.SenseCMSInterfaceText('Format text');
     edit.disabled=!canEdit||!!lockedByOther(block.uid);
     edit.onclick=event=>{event.preventDefault();const value=String(data[field.key]||'');openInlineEditor(edit,block,field,activeLocale,value,value,'whole',slideIndex)};
     const wrapper=document.createElement('div'),source=document.createElement('details'),summary=document.createElement('summary');
     wrapper.className=label.className+' sensecms-format-field';
     const heading=document.createElement('strong');heading.textContent=field.label;
     summary.textContent=window.SenseCMSInterfaceText('HTML source');source.append(summary,label);
     if(!richFragment(String(data[field.key]||''),'whole'))source.open=true;
     wrapper.append(heading,edit,source);return wrapper;
   }
   return label;
 }
 function renderMediaPreview(label,url,kind){label.querySelector('.sensecms-builder-media-preview')?.remove();if(!url)return;const preview=document.createElement('div');preview.className='sensecms-builder-media-preview';const media=document.createElement(kind==='video'?'video':'img');media.src=url;if(kind==='video'){media.muted=true;media.preload='metadata'}const text=document.createElement('span');text.textContent=url;preview.append(media,text);label.append(preview)}
 function uploadMedia(kind,control,label,onChange){const input=document.createElement('input');input.type='file';input.accept=kind==='video'?'video/mp4':'image/jpeg,image/png,image/webp';input.onchange=async()=>{const file=input.files?.[0];if(!file)return;const form=new FormData();form.set('csrf',payload.csrf);form.set('page_id',String(payload.page.id));form.set('kind',kind);form.set('asset',file);label.classList.add('sensecms-builder-uploading');try{const response=await fetch('/content/builder/media',{method:'POST',body:form,headers:{Accept:'application/json','X-SenseCMS-Request':'1'}}),result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||window.SenseCMSInterfaceText('Upload failed.'));control.value=result.data.url;control.dispatchEvent(new Event('input',{bubbles:true}));renderMediaPreview(label,result.data.url,kind);toast('success',result.message)}catch(error){toast('error',error.message||window.SenseCMSInterfaceText('Upload failed.'))}finally{label.classList.remove('sensecms-builder-uploading')}};input.click()}
 function openMedia(kind,control,label){window.SenseCMSMedia.open({kind,anchor:control,select:item=>{control.value=item.url;control.dispatchEvent(new Event('input',{bubbles:true}));renderMediaPreview(label,item.url,kind)}})}
  function repeaterNode(field,data,onChange,block){const root=document.createElement('section');root.className='sensecms-builder-repeater';const head=document.createElement('header'),title=document.createElement('strong'),add=document.createElement('button');title.textContent=field.label;add.type='button';add.textContent='+ Add '+field.item_label.toLowerCase();head.append(title,add);const box=document.createElement('div');box.className='sensecms-builder-repeater-list';root.append(head,box);let items=Array.isArray(data[field.key])?data[field.key]:[];data[field.key]=items;
   const paint=()=>{box.replaceChildren();items.forEach((item,index)=>{const card=document.createElement('article');card.className='sensecms-builder-repeater-item';const itemHead=document.createElement('header'),name=document.createElement('strong'),remove=document.createElement('button');name.textContent=field.item_label+' '+(index+1);remove.type='button';remove.append(icon('trash-2'));remove.disabled=items.length<=field.min;remove.onclick=()=>{items.splice(index,1);onChange();paint()};itemHead.append(name,remove);const fields=document.createElement('div');fields.className='sensecms-builder-fields';field.fields.filter(child=>visibleField(child,item)).forEach(child=>fields.append(fieldNode(child,item,onChange,block?.type==='hero-slider'&&field.key==='slides'?block:null,index)));card.append(itemHead,fields);box.append(card)});window.lucide?.createIcons()};
    add.onclick=()=>{if(items.length>=field.max){toast('warning','This section allows up to '+field.max+' items.');return}const item={};if(mediaField(block?.type)===field.key)item._item_id=uuid();field.fields.forEach(child=>item[child.key]=child.type==='repeater'?[]:child.type==='checkbox'?false:child.type==='number'?(child.min||0):child.type==='select'?Object.keys(child.options||{})[0]||'':'');items.push(item);onChange();paint()};paint();return root;
  }
  function setLiveStatus(message,state=''){const target=root.querySelector('[data-builder-live-state]');if(!target)return;target.textContent=message;target.closest('span')?.classList.toggle('is-updating',state==='is-updating');target.closest('span')?.classList.toggle('is-error',state==='is-error')}
  function highlightLive(scroll=false){
    if(!liveFrame?.contentWindow||schedule)return;
    liveFrame.contentWindow.postMessage({type:'sensecms-builder-highlight',uid:inspectorUid,scroll,boundaries:canEdit&&blocks.length<60?blockUnits().map(unit=>unit[0].uid):[]},location.origin);
    const toolbar=liveFrame.contentDocument?.querySelector('.sensecms-builder-preview-toolbar');
    if(!canEdit||!toolbar)return;
    let grip=toolbar.querySelector('[data-canvas-move]');
    if(!grip){
      grip=liveFrame.contentDocument.createElement('button');
      grip.type='button';grip.dataset.canvasMove='';grip.textContent='⠿';
      grip.title=window.SenseCMSInterfaceText('Drag section or choose a position');grip.setAttribute('aria-label',grip.title);
      grip.style.cursor='grab';
      grip.onclick=()=>choosePosition(inspectorUid,true);
      toolbar.prepend(grip);enableCanvasDrag(grip);
    }
    const unit=blockUnits().find(items=>items.some(block=>block.uid===inspectorUid));
    grip.disabled=blockUnits().length<2||!unit||unit.some(block=>lockedByOther(block.uid));
  }
  function preserveCanvasPosition(from,to){
    const previous=from.contentWindow,next=to.contentWindow;
    if(!previous||!next||!from.contentDocument||!to.contentDocument)return;
    const y=previous.scrollY;
    if(!y&&!previous.scrollX)return;
    const section=[...from.contentDocument.querySelectorAll('[data-sensecms-builder-uid]')].find(node=>{
      const rect=node.getBoundingClientRect();
      return rect.top<=0&&rect.bottom>0;
    });
    const anchor=section&&to.contentDocument.querySelector('[data-sensecms-builder-uid="'+CSS.escape(section.dataset.sensecmsBuilderUid)+'"]');
    next.scrollTo({left:previous.scrollX,top:anchor?next.scrollY+anchor.getBoundingClientRect().top-section.getBoundingClientRect().top:y,behavior:'instant'});
  }
  const scheduleAt=root.querySelector('[data-schedule-at]'),scheduleClock=root.querySelector('[data-schedule-clock]');
  const scheduleClear=root.querySelector('[data-schedule-clear]'),scheduleLabel=root.querySelector('[data-schedule-label]');
  let schedule=null;
  function applySchedule(value) {
    schedule=value;
    scheduleClear.hidden=!value;
    scheduleLabel.textContent=value?'Scheduled preview · '+value.at.replace('T',' ')+' · '+value.zone:window.SenseCMSInterfaceText('Preview at a date and time');
    previewFingerprint='';
    if(dirty)flushDraft({quiet:true});else refreshLivePreview();
  }
  root.querySelector('[data-schedule-form]').onsubmit=event=>{
    event.preventDefault();
    if(scheduleAt.reportValidity())applySchedule({at:scheduleAt.value,clock:scheduleClock.value,zone:scheduleClock.selectedOptions[0].textContent});
  };
  scheduleClear.onclick=()=>applySchedule(null);
  function refreshLivePreview(){
    if(!liveMode||!liveFrame)return;
    const url=new URL(payload.page.live_preview_url,location.origin),current=fingerprint();
    url.searchParams.set('locale',activeLocale);url.searchParams.set('_',String(Date.now()));
    if(schedule){url.searchParams.set('preview_at',schedule.at);url.searchParams.set('clock',schedule.clock)}
    setLiveStatus('Rendering private draft…','is-updating');
    pendingFrame?.remove();
    const next=document.createElement('iframe');
    pendingFrame=next;next.className='sensecms-preview-pending';next.tabIndex=-1;next.setAttribute('aria-hidden','true');
    next.addEventListener('load',()=>{
      if(pendingFrame!==next||!root.isConnected)return;
      pendingFrame=null;
      if(!next.contentDocument?.body?.hasAttribute('data-sensecms-builder-preview')){
        next.remove();liveFrame.hidden=true;previewFingerprint='';
        setLiveStatus(schedule?'Preview unavailable for this time. Check the date or try UTC.':'Preview unavailable. Open Live Canvas to retry.','is-error');return;
      }
      preserveCanvasPosition(liveFrame,next);
      next.title=liveFrame.title;next.dataset.builderLiveFrame='';next.classList.remove('sensecms-preview-pending');
      next.removeAttribute('aria-hidden');next.removeAttribute('tabindex');
      const returnToGrip=document.activeElement===liveFrame&&liveFrame.contentDocument?.activeElement?.hasAttribute('data-canvas-move');
      cancelCanvasDrag();
      const previous=liveFrame;liveFrame=next;previous.remove();
      setLiveStatus(schedule?'Scheduled preview ready':'Live Canvas ready');
      if(!schedule){
        highlightLive(returnToGrip);bindInlineFields();bindChatbotBanner(next);
        if(returnToGrip)next.contentDocument.querySelector('[data-canvas-move]:enabled')?.focus({preventScroll:true});
      }
      else {
        next.contentDocument.addEventListener('submit',event=>event.preventDefault(),true);
        next.contentDocument.addEventListener('click',event=>{if(event.target.closest('a'))event.preventDefault()},true);
      }
    },{once:true});
    next.src=url.pathname+url.search;liveFrame.after(next);previewFingerprint=current;
  }
  function switchLibraryTab(name){const tab=root.querySelector('[data-library-tab="'+name+'"]');if(!tab)return;showToolGroup(tab);if(name!=='inspector'&&name!=='review')delete root.dataset.sectionEditing;root.querySelectorAll('[data-library-tab]').forEach(item=>item.classList.toggle('is-active',item===tab));root.querySelectorAll('[data-library-panel]').forEach(panel=>panel.hidden=panel.dataset.libraryPanel!==name);if(name==='quality')loadQuality();if(name==='review')renderReview();if(name==='ai')updateAiTarget()}
  function selectCanvasBlock(uid,scroll=false){const block=blocks.find(item=>item.uid===uid);if(!block)return;inspectorUid=uid;for (const row of list.querySelectorAll('article[data-uid]')) row.classList.toggle('is-open',row.dataset.uid===uid);renderOutline();renderInspector();renderReview();updateAiTarget();switchLibraryTab(canEdit?'inspector':'review');enterSectionEditor();if(canEdit)reserveSection(uid).then(ok=>{if(!ok){renderInspector();render()}});highlightLive(scroll);window.lucide?.createIcons()}
  function removeBlock(block){const index=blocks.indexOf(block);if(index<0)return;window.SenseCMSUI?.modal('Archive section','This removes the section from the page after you save. Its previous database record remains archived for data safety.',()=>{const group=block.layout.group;blocks.splice(index,1);repairGroup(group);selected.delete(block.uid);if(inspectorUid===block.uid)inspectorUid=blocks[Math.min(index,blocks.length-1)]?.uid||'';setDirty();render();highlightLive()})}
  function runLiveAction(uid,action){const block=blocks.find(item=>item.uid===uid),index=block?blocks.indexOf(block):-1;if(!block)return;if(action==='settings'){selectCanvasBlock(uid);return}if(!canEdit||lockedByOther(uid)){switchLibraryTab('review');toast('warning',lockedByOther(uid)?lockFor(uid).user_name+' is editing this section.':window.SenseCMSInterfaceText('Review mode does not change section content.'));return}if(action==='layout'){openLayout(block);return}if(action==='up'&&index>0){move(index,-1);selectCanvasBlock(uid);return}if(action==='down'&&index<blocks.length-1){move(index,1);selectCanvasBlock(uid);return}if(action==='visibility'){block.visible=!block.visible;setDirty();render();selectCanvasBlock(uid);return}if(action==='duplicate'){duplicateBlock(block,index);return}if(action==='remove')removeBlock(block)}
 let copiedStyle=null;
 function appearanceActions(block) {
   const actions=document.createElement('div'), copy=document.createElement('button'), paste=document.createElement('button');
   actions.className='sensecms-builder-style-tools';
   copy.type=paste.type='button';
   copy.className=paste.className='btn bg-default-150';
   copy.textContent=window.SenseCMSInterfaceText('Copy appearance');
   paste.textContent=window.SenseCMSInterfaceText('Apply copied appearance');
   paste.disabled=!copiedStyle||JSON.stringify(block.appearance)===JSON.stringify(copiedStyle.value);
   copy.onclick=()=>{
     copiedStyle={value:clone(block.appearance),title:blockTitle(block)};
     paste.disabled=true;
     updateStyleSelection();
     toast('success',window.SenseCMSInterfaceText('Appearance copied. Select destination sections in Navigator, or apply it in another Inspector.'));
   };
   paste.onclick=()=>applyAppearance([block]);
   actions.append(copy,paste);
   return actions;
 }
 function updateStyleSelection() {
   const tools=root.querySelector('[data-style-tools]');
   if(!tools)return;
   tools.hidden=!copiedStyle;
   if(!copiedStyle)return;
   const targets=blocks.filter(block=>selected.has(block.uid)), apply=tools.querySelector('[data-apply-style]');
   tools.querySelector('[data-style-source]').textContent='Appearance from '+copiedStyle.title;
   apply.disabled=!targets.length||targets.some(block=>lockedByOther(block.uid))||
     targets.every(block=>JSON.stringify(block.appearance)===JSON.stringify(copiedStyle.value));
   apply.textContent='Apply appearance to selected ('+targets.length+')';
 }
 function applyAppearance(targets) {
   if(!canEdit||!copiedStyle||!targets.length||targets.some(block=>lockedByOther(block.uid)))return;
   const changed=targets.filter(block=>JSON.stringify(block.appearance)!==JSON.stringify(copiedStyle.value));
   if(!changed.length)return;
   changed.forEach(block=>{block.appearance=clone(copiedStyle.value)});
   setDirty();
   render();
   toast('success','Appearance applied to '+changed.length+' section'+(changed.length===1?'':'s')+'.');
 }
 root.querySelector('[data-apply-style]')?.addEventListener('click',()=>applyAppearance(blocks.filter(block=>selected.has(block.uid))));
  function appearanceGroup(block){const group=document.createElement('section'),title=document.createElement('header'),fields=document.createElement('div'),reset=document.createElement('button');group.className='sensecms-builder-inspector-group sensecms-builder-appearance';title.innerHTML=("<strong>"+window.SenseCMSInterfaceHtml("Appearance")+"</strong><span>"+window.SenseCMSInterfaceHtml("Portable Core styles")+"</span>");fields.className='sensecms-builder-appearance-fields';Object.entries(appearances).forEach(([key,definition])=>{const label=document.createElement('label'),caption=document.createElement('span'),select=document.createElement('select');caption.textContent=definition.label||key;Object.entries(definition.options||{}).forEach(([value,text])=>{const option=document.createElement('option');option.value=value;option.textContent=text;select.append(option)});select.value=block.appearance[key]||'default';select.onchange=()=>{block.appearance[key]=select.value;setDirty();renderInspector()};label.append(caption,select);fields.append(label)});reset.type='button';reset.className='sensecms-builder-appearance-reset';reset.append(icon('rotate-ccw'),document.createTextNode(window.SenseCMSInterfaceText('Use theme defaults')));reset.onclick=()=>{block.appearance=defaultAppearance();setDirty();renderInspector()};group.append(title,fields,reset);if(canEdit)group.append(appearanceActions(block));return group}
 function visibilitySchedule(block) {
   const schedule = document.createElement('section');
   schedule.className = 'sensecms-builder-schedule';
   schedule.innerHTML = ("<header><i data-lucide=\"calendar-clock\"></i><div><strong>"+window.SenseCMSInterfaceHtml("Visibility schedule")+"</strong><small>"+window.SenseCMSInterfaceHtml("Optional publishing window in the website timezone")+"</small></div></header>");
   const fields = document.createElement('div');
   fields.className = 'sensecms-builder-schedule-fields';
   for (const [key, caption] of [['visible_from','Visible from'], ['visible_until','Visible until']]) {
     const label = document.createElement('label'), input = document.createElement('input');
     input.type = 'datetime-local';
     input.value = block[key] || '';
     input.onchange = () => {
       block[key] = input.value;
       if (block.visible_from && block.visible_until && block.visible_from >= block.visible_until) toast('warning',window.SenseCMSInterfaceText('The end date must be later than the start date.'));
       setDirty();
       render();
     };
     label.append(caption, input);
     fields.append(label);
   }
   schedule.append(fields);
   return schedule;
 }
 function inspectorChanged(block, refresh=true, typingField=null) {
   setDirty(true, {typingField});
   const title = blockTitle(block), completeness = coverage(block) + '% content';
   const summary = list.querySelector('[data-uid="' + CSS.escape(block.uid) + '"] .sensecms-builder-block-title > span');
   if (summary) summary.textContent = title + ' · ' + completeness;
   const outlineTitle = root.querySelector('[data-builder-outline] [data-uid="' + CSS.escape(block.uid) + '"] .sensecms-builder-outline-jump strong');
   if (outlineTitle) outlineTitle.textContent = title;
   if(root.querySelector('[data-outline-query]').value)filterOutline();
   if (refresh) renderInspector();
   else {
     inspector.querySelector('.sensecms-builder-inspector-head strong').textContent = title;
     inspector.querySelector('.sensecms-builder-inspector-head span').textContent = catalog[block.type].label + ' · ' + completeness;
   }
 }
 let compareLanguages=false,referenceLocale='';
 function languageName(lang){return lang.native_name||lang.name||lang.locale}
 function translatedText(field,value){
   if(value==null||value==='')return '';
   if(field.type==='repeater'&&Array.isArray(value)){
     return value.map((item,index)=>{
       const parts=field.fields.filter(child=>visibleField(child,item)).map(child=>{
         const text=translatedText(child,item[child.key]);
         return text?child.label+' — '+text:'';
       }).filter(Boolean);
       return (index+1)+'. '+parts.join('\n');
     }).join('\n\n');
   }
   if(Array.isArray(value))return value.join(', ');
   if(field.options&&Object.hasOwn(field.options,value))return String(field.options[value]);
   if(field.key==='text'&&typeof value==='string'&&/<[a-z][^>]*>/i.test(value)){
     const template=document.createElement('template');
     template.innerHTML=value;
     return template.content.textContent.trim();
   }
   return String(value);
 }
 function languagePicker(labelText,options,value,onChange){
   const label=document.createElement('label'),select=document.createElement('select');
   label.textContent=labelText;select.className='form-select';select.dataset.translationTool='';
   options.forEach(lang=>select.add(new Option(languageName(lang)+' ('+lang.locale.toUpperCase()+')',lang.locale)));
   select.value=value;select.onchange=()=>onChange(select.value);label.append(select);return label;
 }
 function translationFields(block,definition,editable){
   const group=document.createElement('section'),head=document.createElement('header'),name=document.createElement('strong'),meta=document.createElement('span');
   group.className='sensecms-builder-inspector-group';
   name.textContent=languageName(languages.find(lang=>lang.locale===activeLocale)||{locale:activeLocale});
   meta.textContent=block.global_section_id?window.SenseCMSInterfaceText('Localized content · shared across linked pages'):window.SenseCMSInterfaceText('Localized content');
   head.append(name,meta);group.append(head);
   const compare=compareLanguages&&languages.length>1;
   if(languages.length>1){
     const toggle=document.createElement('button');
     toggle.type='button';toggle.className='btn bg-default-150';toggle.dataset.translationTool='';
     toggle.dataset.translationToggle='';toggle.textContent=compare?window.SenseCMSInterfaceText('Close comparison'):window.SenseCMSInterfaceText('Compare translations');
     toggle.setAttribute('aria-expanded',String(compare));
     toggle.onclick=()=>{compareLanguages=!compare;renderInspector();inspector.querySelector('[data-translation-toggle]').focus()};
     group.append(toggle);
   }
   const data=block.localized[activeLocale],fields=document.createElement('div');
   fields.className='sensecms-builder-fields';
   let reference;
   if(compare){
     const choices=languages.filter(lang=>lang.locale!==activeLocale);
     if(!choices.some(lang=>lang.locale===referenceLocale))referenceLocale=(choices.find(lang=>Number(lang.is_default))||choices[0]).locale;
     reference=block.localized[referenceLocale]||{};
     const selectors=document.createElement('div');
     selectors.className='sensecms-translation-pair';
     selectors.append(languagePicker('Reference (read only)',choices,referenceLocale,value=>{
       referenceLocale=value;renderInspector();inspector.querySelector('.sensecms-translation-pair select').focus();
     }),languagePicker('Editing language',languages,activeLocale,value=>{
       if(value===referenceLocale)referenceLocale=activeLocale;
       activeLocale=value;localeNavigation();render();if(liveMode)refreshLivePreview();
       inspector.querySelector('.sensecms-translation-pair label:last-child select').focus();
     }));
     group.append(selectors);fields.dataset.translationRows='';
   }
   for(const field of definition.fields.filter(field=>visibleField(field,data))){
     const editor=fieldNode(field,data,(refresh,input)=>inspectorChanged(block,refresh,input),block);
     if(!compare){fields.append(editor);continue}
     const row=document.createElement('div'),original=document.createElement('div'),caption=document.createElement('strong'),text=document.createElement('span');
     row.className='sensecms-translation-pair';original.className='sensecms-translation-source';
     const sourceText=translatedText(field,reference[field.key]);
     row.dataset.hasReference=String(!!sourceText.trim());
     caption.textContent=field.label;text.textContent=sourceText||'—';
     original.append(caption,text);row.append(original,editor);fields.append(row);
   }
   if(compare&&editable){
     const next=document.createElement('button'),status=document.createElement('small');let previous=null;
     next.type='button';next.className='btn bg-default-150';next.textContent=window.SenseCMSInterfaceText('Next empty field');
     status.setAttribute('role','status');
     next.onclick=()=>{
       const empty=Array.from(fields.querySelectorAll('input:not([type=checkbox]):not([type=number]):not([type=hidden]),textarea')).filter(el=>!el.disabled&&!el.readOnly&&!translatedText({key:el.tagName==='TEXTAREA'?'text':''},el.value).trim());
       empty.sort((a,b)=>Number(!!b.closest('[data-has-reference=true]'))-Number(!!a.closest('[data-has-reference=true]')));
       if(!empty.length){status.textContent=window.SenseCMSInterfaceText('No empty text fields in this section.');return}
       previous=empty[(empty.indexOf(previous)+1)%empty.length];
       const details=previous.closest('details');if(details)details.open=true;
       previous.focus();status.textContent=(empty.indexOf(previous)+1)+' of '+empty.length+' empty fields';
     };
     group.append(next,status);
   }
   group.append(fields);
   return group;
 }
  function renderInspector(){if(!inspector)return;const block=blocks.find(item=>item.uid===inspectorUid);if(!block){inspector.innerHTML=("<div class=\"sensecms-builder-global-empty\"><i data-lucide=\"mouse-pointer-click\"></i><strong>"+window.SenseCMSInterfaceHtml("Select a section on the canvas")+"</strong><span>"+window.SenseCMSInterfaceHtml("Its content, visibility and responsive settings will appear here.")+"</span></div>");return}const editable=canEdit&&!lockedByOther(block.uid),definition=catalog[block.type],head=document.createElement('header');head.className='sensecms-builder-inspector-head';const tile=document.createElement('i');tile.append(icon(definition.icon));const copy=document.createElement('div'),name=document.createElement('strong'),meta=document.createElement('span');name.textContent=blockTitle(block);meta.textContent=definition.label+' · '+coverage(block)+'% content';copy.append(name,meta);head.append(tile,copy);const actions=document.createElement('div');actions.className='sensecms-builder-inspector-actions';[['columns-3','layout','Responsive settings'],['copy-plus','duplicate','Duplicate section'],[block.visible?'eye':'eye-off','visibility',block.visible?'Hide section':'Show section'],['trash-2','remove','Remove section']].forEach(([glyph,action,title])=>{const control=button(glyph,action,title);control.disabled=!editable;if(action==='remove')control.classList.add('is-danger');control.onclick=()=>runLiveAction(block.uid,action);actions.append(control)});head.append(actions);const body=document.createElement('div');body.className='sensecms-builder-inspector-body';if(!editable){const notice=document.createElement('div');notice.className='sensecms-builder-edit-lock';notice.append(icon(lockedByOther(block.uid)?'lock-keyhole':'shield-check'));const noticeCopy=document.createElement('span'),noticeTitle=document.createElement('strong'),noticeText=document.createElement('small');noticeTitle.textContent=lockedByOther(block.uid)?lockFor(block.uid).user_name+' is editing':window.SenseCMSInterfaceText('Review mode');noticeText.textContent=lockedByOther(block.uid)?window.SenseCMSInterfaceText('Content controls unlock automatically when that editing session ends.'):window.SenseCMSInterfaceText('Use the Review tab to comment, assign and approve this section.');noticeCopy.append(noticeTitle,noticeText);notice.append(noticeCopy);body.append(notice)}const visibility=document.createElement('label');visibility.className='sensecms-builder-inspector-toggle';const toggle=document.createElement('input');toggle.type='checkbox';toggle.checked=block.visible;toggle.disabled=!editable;const toggleCopy=document.createElement('span');toggleCopy.innerHTML=("<b>"+window.SenseCMSInterfaceHtml("Visible on website")+"</b><small>"+window.SenseCMSInterfaceHtml("Hidden sections remain available as a faded canvas item.")+"</small>");toggle.onchange=()=>{block.visible=toggle.checked;setDirty();render();selectCanvasBlock(block.uid)};visibility.append(toggle,toggleCopy);const design=document.createElement('details'),summary=document.createElement('summary');design.className='sensecms-builder-design';design.dataset.uid=block.uid;design.open=inspector.querySelector('details[open]')?.dataset.uid===block.uid;summary.textContent=window.SenseCMSInterfaceText('Design & layout');design.append(summary,visibility,visibilitySchedule(block),appearanceGroup(block));if(definition.shared_fields?.length){const group=document.createElement('section'),title=document.createElement('header'),fields=document.createElement('div');group.className='sensecms-builder-inspector-group';title.innerHTML=("<strong>"+window.SenseCMSInterfaceHtml("Section settings")+"</strong><span>"+window.SenseCMSInterfaceHtml("Shared by every language")+"</span>");fields.className='sensecms-builder-fields';definition.shared_fields.filter(field=>visibleField(field,block.shared)).forEach(field=>fields.append(fieldNode(field,block.shared,(refresh,field)=>inspectorChanged(block,refresh,field))));group.append(title,fields);body.append(group)}if(definition.fields.length)body.append(translationFields(block,definition,editable));const footer=document.createElement('footer'),responsive=document.createElement('button');responsive.type='button';responsive.className='btn bg-default-150';responsive.disabled=!editable;responsive.append(icon('monitor-smartphone'),document.createTextNode(window.SenseCMSInterfaceText('Responsive layout')));responsive.onclick=()=>openLayout(block);footer.append(responsive);design.append(footer);body.append(design);if(!editable)body.querySelectorAll('input,textarea,select,button').forEach(control=>{if(!control.hasAttribute('data-translation-tool'))control.disabled=true});if(block.global_section_id)head.append(sharedActions(block,editable));inspector.replaceChildren(head,body);window.lucide?.createIcons()}
  function switchBuilderView(mode){liveMode=mode==='live';root.querySelectorAll('[data-builder-library] button:enabled').forEach(control=>control.draggable=liveMode);root.querySelector('[data-builder-structure]').hidden=liveMode;root.querySelector('[data-builder-live]').hidden=!liveMode;root.querySelectorAll('[data-builder-view]').forEach(control=>control.classList.toggle('is-active',control.dataset.builderView===mode));root.querySelector('[data-builder-canvas-help]').textContent=liveMode?window.SenseCMSInterfaceText('Click text, a button or an image to edit it. Select a section, then drag its grip to move it.'):window.SenseCMSInterfaceText('Drag sections into the required order. Every section has independent content for each active language.');if(!liveMode)return;if(!inspectorUid&&blocks[0])inspectorUid=blocks[0].uid;renderInspector();switchLibraryTab('inspector');if(dirty)flushDraft({quiet:true});else refreshLivePreview()}
 function blockNode(block,index){const editable=canEdit&&!lockedByOther(block.uid),definition=catalog[block.type],article=document.createElement('article');article.className='sensecms-builder-block'+(inspectorUid===block.uid?' is-open':'')+(block.visible?'':' is-hidden')+(editable?'':' is-edit-locked');article.dataset.uid=block.uid;article.style.setProperty('--block-accent',index%3===0?'#2f7df6':index%3===1?'#c92635':'#0f9f67');
   const head=document.createElement('header');head.className='sensecms-builder-block-head';const drag=button('grip-vertical','drag','Drag to reorder');drag.className='sensecms-builder-drag';drag.disabled=!editable;enableDrag(drag,block.uid,article);drag.onclick=()=>choosePosition(block.uid);
    const tile=document.createElement('span');tile.className='sensecms-builder-block-icon';tile.append(icon(definition.icon));const title=document.createElement('div');title.className='sensecms-builder-block-title';const strong=document.createElement('strong');strong.textContent=definition.label;if(definition.singleton){const badge=document.createElement('em');badge.textContent=window.SenseCMSInterfaceText('Single');strong.append(badge)}const description=document.createElement('span');description.textContent=blockTitle(block)+' · '+coverage(block)+'% content';title.append(strong,description);const badges=document.createElement('div');badges.className='sensecms-builder-block-badges';const workflow=workflowFor(block.uid),comments=commentsFor(block.uid),lock=lockFor(block.uid),workflowBadge=document.createElement('em');workflowBadge.className='is-workflow is-'+workflow.status;workflowBadge.append(icon(workflow.status==='approved'?'badge-check':workflow.status==='in_review'?'scan-eye':'pencil-line'),document.createTextNode(workflowLabel(workflow.status)));badges.append(workflowBadge);if(comments.length){const badge=document.createElement('em');badge.className='is-comments';badge.append(icon('message-circle'),document.createTextNode(String(comments.filter(item=>!item.resolved).length)));badges.append(badge)}if(lock){const badge=document.createElement('em');badge.className=lock.is_mine?'is-editing':'is-locked';badge.append(icon(lock.is_mine?'pencil-line':'lock-keyhole'),document.createTextNode(lock.is_mine?window.SenseCMSInterfaceText('Editing'):lock.user_name));badges.append(badge)}if(block.global_section_id){const badge=document.createElement('em');badge.append(icon('combine'),document.createTextNode(block.global_name||window.SenseCMSInterfaceText('Shared section')));badges.append(badge)}if(block.layout.group||block.layout.container!=='content'||block.layout.desktop.span!==12){const badge=document.createElement('em');badge.className='is-layout';badge.append(icon('columns-3'),document.createTextNode((block.layout.group?block.layout.mode:'section')+' · '+block.layout.desktop.span+'/12'));badges.append(badge)}const now=new Date(),from=block.visible_from?new Date(block.visible_from):null,until=block.visible_until?new Date(block.visible_until):null;if(from&&from>now){const badge=document.createElement('em');badge.className='is-scheduled';badge.append(icon('clock-3'),document.createTextNode(window.SenseCMSInterfaceText('Scheduled')));badges.append(badge)}else if(until&&until<=now){const badge=document.createElement('em');badge.className='is-expired';badge.append(icon('calendar-x'),document.createTextNode(window.SenseCMSInterfaceText('Expired')));badges.append(badge)}if(badges.children.length)title.append(badges);
    const controls=document.createElement('div');controls.className='sensecms-builder-block-controls';const toggle=document.createElement('label');toggle.className='sensecms-builder-toggle';toggle.title=block.visible?window.SenseCMSInterfaceText('Visible on website'):window.SenseCMSInterfaceText('Hidden from website');const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.checked=block.visible;const slider=document.createElement('span');checkbox.onchange=()=>{block.visible=checkbox.checked;setDirty();render()};toggle.append(checkbox,slider);const layout=button('columns-3','layout','Responsive layout settings'),share=button(block.global_section_id?'unlink':'combine',block.global_section_id?'detach':'share',block.global_section_id?'Detach shared section':'Save as shared section'),copySection=button('clipboard-copy','copy','Copy section to private clipboard'),up=button('arrow-up','up','Move section up'),down=button('arrow-down','down','Move section down'),duplicate=button('copy-plus','duplicate','Duplicate section'),remove=button('trash-2','remove','Archive section');share.className='sensecms-builder-share-action'+(block.global_section_id?' is-linked':'');layout.className='sensecms-builder-layout-action';remove.classList.add('is-danger');const expand=button('pencil-line','edit',canEdit?'Edit section in Inspector':'Review section');up.disabled=index===0;down.disabled=index===blocks.length-1;
    [checkbox,layout,share,copySection,up,down,duplicate,remove].forEach(control=>control.disabled=!editable||control.disabled);layout.onclick=()=>openLayout(block);share.onclick=()=>block.global_section_id?detachSection(block):openShare(block);copySection.onclick=()=>copyBlocks([block]);up.onclick=()=>move(index,-1);down.onclick=()=>move(index,1);duplicate.onclick=()=>duplicateBlock(block,index);remove.onclick=()=>removeBlock(block);expand.onclick=()=>openStructureEditor(block.uid);controls.append(toggle,layout,share,copySection,up,down,duplicate,remove,expand);head.append(drag,tile,title,controls);article.append(head);
   return article;
 }
 function render(){selected=new Set([...selected].filter(uid=>blocks.some(block=>block.uid===uid)));if(!blocks.some(block=>block.uid===inspectorUid))inspectorUid=blocks[0]?.uid||'';const rows=[];let index=0;for(const unit of blockUnits()){if(canEdit)rows.push(gapButton(unit[0]));for(const block of unit)rows.push(blockNode(block,index++))}list.replaceChildren(...rows);if(appendButton)appendButton.disabled=blocks.length>=60;empty.hidden=blocks.length>0;root.toggleAttribute('data-empty-canvas',!blocks.length);count.textContent=String(blocks.length);root.querySelector('[data-builder-outline-count]').textContent=String(blocks.length);renderOutline();renderInspector();renderReview();renderClipboard();renderLibrary();renderPatterns();renderLayouts();renderGlobals();renderQuality();updateAiTarget();renderAiProposal();highlightLive();window.lucide?.createIcons()}
 function renderOutline(){updateStyleSelection();const target=root.querySelector('[data-builder-outline]'),selectedCount=root.querySelector('[data-builder-outline-selected]'),copySelected=root.querySelector('[data-builder-copy-selected]');selectedCount.textContent=String(selected.size);copySelected.disabled=!canEdit||!selected.size;if(!blocks.length){root.querySelector('[data-outline-results]').textContent='';target.innerHTML=("<div class=\"sensecms-builder-global-empty\"><i data-lucide=\"list-tree\"></i><strong>"+window.SenseCMSInterfaceHtml("No sections on this page")+"</strong><span>"+window.SenseCMSInterfaceHtml("Add a section to start building the page outline.")+"</span></div>");window.lucide?.createIcons();return}target.replaceChildren(...blocks.map((block,index)=>{const row=document.createElement('article');row.dataset.uid=block.uid;row.className='sensecms-builder-outline-item'+(inspectorUid===block.uid?' is-active':'')+(block.visible?'':' is-hidden');const select=document.createElement('input');select.type='checkbox';select.disabled=!canEdit||lockedByOther(block.uid);select.checked=selected.has(block.uid);select.setAttribute('aria-label','Select '+blockTitle(block));select.onchange=()=>{select.checked?selected.add(block.uid):selected.delete(block.uid);renderOutline();renderLayouts()};const jump=document.createElement('button');jump.type='button';jump.className='sensecms-builder-outline-jump';const tile=document.createElement('i');tile.append(icon(catalog[block.type].icon));const copy=document.createElement('span'),name=document.createElement('strong'),meta=document.createElement('small'),state=workflowFor(block.uid),comments=commentsFor(block.uid).filter(item=>!item.resolved).length,lock=lockFor(block.uid);name.textContent=blockTitle(block);meta.textContent=[catalog[block.type].label,workflowLabel(state.status),state.assigned_name||'',comments?comments+' open':'',lock&&!lock.is_mine?lock.user_name+' editing':''].filter(Boolean).join(' · ');copy.append(name,meta);jump.append(tile,copy);jump.onclick=()=>openStructureEditor(block.uid);const actions=document.createElement('span'),up=button('chevron-up','outline-up','Move section up'),down=button('chevron-down','outline-down','Move section down');up.disabled=!canEdit||lockedByOther(block.uid)||index===0;down.disabled=!canEdit||lockedByOther(block.uid)||index===blocks.length-1;up.onclick=()=>move(index,-1);down.onclick=()=>move(index,1);actions.append(up,down);
   if (canEdit) {
     const place=button('list-ordered','outline-place','Move to position');
     place.disabled=blockUnits().length<2||unitAt(index).items.some(item=>lockedByOther(item.uid));
     place.append(document.createTextNode(window.SenseCMSInterfaceText('Move…')));
     place.title=window.SenseCMSInterfaceText('Drag to reorder or click to choose a position');
     enableDrag(place,block.uid,row);
     place.onclick=()=>choosePosition(block.uid);
     actions.append(place);
   }
   row.append(select,jump,actions);return row}));filterOutline();window.lucide?.createIcons()}
 function filterOutline() {
   const query=root.querySelector('[data-outline-query]').value.trim().toLocaleLowerCase();
   let matches=0;
   for(const row of root.querySelectorAll('[data-builder-outline] article[data-uid]')) {
     const block=blocks.find(item=>item.uid===row.dataset.uid);
     const searchable=[blockTitle(block),block.type,catalog[block.type].label,block.global_name].join(' ').toLocaleLowerCase();
     row.hidden=!searchable.includes(query);
     if(!row.hidden)matches++;
   }
   root.querySelector('[data-outline-results]').textContent=query
     ? (matches ? matches+' of '+blocks.length+' sections' : window.SenseCMSInterfaceText('No matching sections. Try another name or type.')) : '';
 }
 root.querySelector('[data-outline-query]').addEventListener('input',filterOutline);
 function selectedBlocks(){return blocks.filter(block=>selected.has(block.uid))}
 function applyLayoutPreset(preset){const items=selectedBlocks(),indexes=items.map(item=>blocks.indexOf(item));if(items.length<preset.min||items.length>preset.max){toast('warning','Select '+(preset.min===preset.max?preset.min:preset.min+' to '+preset.max)+' consecutive sections for this layout.');return}if(indexes[indexes.length-1]-indexes[0]+1!==indexes.length){toast('warning',window.SenseCMSInterfaceText('Layout groups require consecutive sections. Reorder or adjust your selection first.'));return}const previousGroups=new Set(items.map(block=>block.layout.group).filter(Boolean));for(const oldGroup of previousGroups)blocks.filter(block=>block.layout.group===oldGroup).forEach(block=>block.layout=defaultLayout());const group=items.length>1?uuid():null,count=items.length,even=Math.max(1,Math.floor(12/count));items.forEach((block,index)=>{const span=Number(preset.spans?.[index]??preset.spans?.[0]??even),layout=defaultLayout();layout.group=group;layout.mode=preset.mode;layout.container=preset.container;layout.desktop.span=span;layout.tablet.span=preset.mode==='stack'?12:(count===2?6:Math.max(6,span));layout.mobile.span=12;block.layout=layout});setDirty();render();toast('success',preset.label+' applied to '+items.length+' '+(items.length===1?'section':'sections')+'.')}
 function renderLayouts(){const target=root.querySelector('[data-builder-layouts]'),selectedCount=root.querySelector('[data-builder-layout-selected]');if(!target||!selectedCount)return;selectedCount.textContent=String(selected.size);target.replaceChildren(...layouts.map(preset=>{const card=document.createElement('button');card.type='button';card.className='sensecms-builder-layout-card';const tile=document.createElement('i');tile.append(icon(preset.icon||'columns-3'));const copy=document.createElement('span'),name=document.createElement('strong'),description=document.createElement('small'),requirement=document.createElement('em');name.textContent=preset.label;description.textContent=preset.description;requirement.textContent=preset.min===preset.max?preset.min+' selected':preset.min+'–'+preset.max+' selected';copy.append(name,description,requirement);card.append(tile,copy,icon('arrow-right'));card.onclick=()=>applyLayoutPreset(preset);return card}),...['separator','spacer'].filter(type=>catalog[type]).map(type=>{const item=catalog[type],card=document.createElement('button');card.type='button';card.className='sensecms-builder-layout-card is-structure';const tile=document.createElement('i');tile.append(icon(item.icon));const copy=document.createElement('span'),name=document.createElement('strong'),description=document.createElement('small'),requirement=document.createElement('em');name.textContent=item.label;description.textContent=item.description;requirement.textContent=window.SenseCMSInterfaceText('Add structural section');copy.append(name,description,requirement);card.append(tile,copy,icon('plus'));card.onclick=()=>addBlock(type);return card}));window.lucide?.createIcons()}
 function renderClipboard(){const box=root.querySelector('[data-builder-clipboard]'),paste=root.querySelector('[data-builder-paste]'),items=Array.isArray(clipboard?.blocks)?clipboard.blocks.filter(block=>catalog[block.type]):[];paste.disabled=!items.length;if(!items.length){box.innerHTML=("<i data-lucide=\"clipboard\"></i><span><strong>"+window.SenseCMSInterfaceHtml("Private clipboard is empty")+"</strong><small>"+window.SenseCMSInterfaceHtml("Copy up to 20 sections and paste them on another page.")+"</small></span>");return}const source=String(clipboard.source_title||'another page'),time=clipboard.updated_at?new Date(String(clipboard.updated_at).replace(' ','T')).toLocaleString():'';box.replaceChildren();const mark=icon('clipboard-check'),copy=document.createElement('span'),name=document.createElement('strong'),meta=document.createElement('small'),clear=document.createElement('button');name.textContent=items.length+' copied '+(items.length===1?'section':'sections');meta.textContent='From '+source+(time?' · '+time:'');copy.append(name,meta);clear.type='button';clear.title=window.SenseCMSInterfaceText('Clear private clipboard');clear.setAttribute('aria-label',window.SenseCMSInterfaceText('Clear private clipboard'));clear.append(icon('x'));clear.onclick=clearClipboard;box.append(mark,copy,clear)}
 async function copyBlocks(items){if(!items.length)return;try{const result=await request('/content/builder/'+payload.page.id+'/clipboard',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf,blocks:items.map(block=>blockPayload().find(item=>item.uid===block.uid)).filter(Boolean)})});clipboard=result.data;selected.clear();render();toast('success',result.message)}catch(error){toast('error',error.message||window.SenseCMSInterfaceText('The sections could not be copied.'))}}
 function pasteClipboard(){const source=Array.isArray(clipboard?.blocks)?clipboard.blocks:[],copies=[],room=Math.max(0,60-blocks.length),groupMap=new Map();let skipped=0;source.forEach(raw=>{if(copies.length>=room||!catalog[raw.type]||(catalog[raw.type].singleton&&blocks.some(block=>block.type===raw.type))){skipped++;return}const copy=normalize(raw),oldGroup=copy.layout.group;copy.uid=uuid();copy.global_section_id=null;copy.global_name='';copy.global_version=0;if(oldGroup){if(!groupMap.has(oldGroup))groupMap.set(oldGroup,uuid());copy.layout.group=groupMap.get(oldGroup)}copies.push(copy)});if(!copies.length){toast('warning',room<1?window.SenseCMSInterfaceText('A page can contain up to 60 sections.'):skipped?window.SenseCMSInterfaceText('The copied singleton sections already exist on this page.'):window.SenseCMSInterfaceText('The private clipboard has no compatible sections.'));return}for(const group of groupMap.values())if(copies.filter(block=>block.layout.group===group).length<2)copies.filter(block=>block.layout.group===group).forEach(block=>{block.layout.group=null;block.layout.mode='stack'});const indexes=[...selected].map(uid=>blocks.findIndex(block=>block.uid===uid)).filter(index=>index>=0),at=indexes.length?Math.max(...indexes)+1:blocks.length;blocks.splice(at,0,...copies);selected.clear();inspectorUid=copies[0].uid;setDirty();render();setTimeout(()=>list.querySelector('[data-uid="'+CSS.escape(copies[0].uid)+'"]')?.scrollIntoView({behavior:'smooth',block:'center'}),20);toast('success',copies.length+' '+(copies.length===1?'section':'sections')+' pasted'+(skipped?' · '+skipped+' incompatible or over limit skipped':'')+'.')}
 async function clearClipboard(){try{const result=await request('/content/builder/clipboard/clear',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf})});clipboard=null;renderClipboard();window.lucide?.createIcons();toast('success',result.message)}catch(error){toast('error',error.message||window.SenseCMSInterfaceText('The private clipboard could not be cleared.'))}}
 const moveDialog=root.querySelector('[data-builder-move-dialog]');
 let movingUid='',moveFromCanvas=false;
 function plannedMove(sourceUid, targetUid, side) {
   const units=blockUnits();
   const from=units.findIndex(unit=>unit.some(block=>block.uid===sourceUid));
   const to=units.findIndex(unit=>unit.some(block=>block.uid===targetUid));
   if (from<0 || to<0 || from===to) return {reason:'Choose a different destination.'};
   const insertion=to+(side==='after'?1:0)-(from<to?1:0);
   if (insertion===from) return {reason:'Already at this position.'};
   const affected=units.slice(Math.min(from,insertion),Math.max(from,insertion)+1);
   if (affected.some(unit=>unit.some(block=>lockedByOther(block.uid)))) return {reason:'A section in this range is being edited by another person.'};
   const destination=units[to];
   const [source]=units.splice(from,1);
   units.splice(insertion,0,source);
   return {order:units.flat(), edge:side==='before'?destination[0].uid:destination.at(-1).uid};
 }
 function updateMove() {
   const plan=plannedMove(movingUid,moveDialog.querySelector('[data-move-target]').value,moveDialog.querySelector('[data-move-side]').value);
   moveDialog.querySelector('[data-move-note]').textContent=plan.reason||window.SenseCMSInterfaceText('Applies to the draft. You can undo this move.');
   moveDialog.querySelector('[data-move-apply]').disabled=!plan.order;
   return plan;
 }
 function choosePosition(uid,fromCanvas=false) {
   if (!canEdit || !moveDialog) return;
   const units=blockUnits(),source=units.find(unit=>unit.some(block=>block.uid===uid));
   if (!source || units.length<2) return;
   movingUid=uid;moveFromCanvas=fromCanvas;
   const target=moveDialog.querySelector('[data-move-target]');
   target.replaceChildren();
   let position=1;
   for (const unit of units) {
     const name=unit.length===1?blockTitle(unit[0]):unit.map(blockTitle).join(' / ');
     const label=(unit.length===1?position:position+'–'+(position+unit.length-1))+'. '+name;
     if (unit===source) moveDialog.querySelector('[data-move-source]').textContent=(unit.length===1?'Section: ':'Layout group: ')+label;
     else target.add(new Option(label,unit[0].uid));
     position+=unit.length;
   }
   moveDialog.querySelector('[data-move-side]').value=source===units[0]?'after':'before';
   updateMove();
   moveDialog.showModal();
 }
 if (moveDialog) {
   moveDialog.addEventListener('change',updateMove);
   moveDialog.querySelector('[data-move-cancel]').onclick=()=>moveDialog.close();
   moveDialog.querySelector('[data-move-apply]').onclick=()=>{
     const {order}=updateMove();
     if (!canEdit || !order) return;
     moveDialog.close();
     blocks=order;
     setDirty();
     render();
     if(moveFromCanvas)liveFrame.contentDocument?.querySelector('[data-canvas-move]')?.focus();
     else root.querySelector('[data-builder-outline] [data-uid="'+CSS.escape(movingUid)+'"] [data-action="outline-place"]')?.focus();
     toast('success','Moved to position '+(blocks.findIndex(block=>block.uid===movingUid)+1)+' of '+blocks.length+'.');
   };
 }
 function unitAt(index){const group=blocks[index]?.layout.group;if(!group)return{start:index,end:index,items:blocks.slice(index,index+1)};const indexes=blocks.map((block,position)=>block.layout.group===group?position:-1).filter(position=>position>=0);return{start:Math.min(...indexes),end:Math.max(...indexes),items:blocks.slice(Math.min(...indexes),Math.max(...indexes)+1)}}
 function blockUnits(){const units=[];for(const block of blocks){const last=units[units.length-1];if(block.layout.group&&last?.[0]?.layout.group===block.layout.group)last.push(block);else units.push([block])}return units}
 function repairGroup(group){if(!group)return;const members=blocks.filter(block=>block.layout.group===group);if(members.length<2)members.forEach(block=>{block.layout.group=null;block.layout.mode='stack'})}
 function revealTool(panel) {
   panel.tabIndex=-1;
   panel.focus({preventScroll:true});
   panel.scrollIntoView({block:'start'});
 }
 root.querySelectorAll('[data-builder-jump]').forEach(button=>{
   button.addEventListener('click',()=>{
     const target=root.querySelector(button.dataset.builderJump==='tools' ? '[data-builder-jump="canvas"]' : '[data-builder-jump="tools"]');
     target.focus({preventScroll:true});
     target.scrollIntoView({block:'start'});
   });
 });
 function openStructureEditor(uid) {
   selectCanvasBlock(uid);
   const heading = inspector.querySelector('header');
   if (canEdit && heading && !backButton.offsetWidth) {
     heading.tabIndex = -1;
     heading.focus();
   }
 }
 function move(index,direction){const unit=unitAt(index);if(direction<0&&unit.start===0||direction>0&&unit.end===blocks.length-1)return;const neighbour=unitAt(direction<0?unit.start-1:unit.end+1),start=Math.min(unit.start,neighbour.start),count=unit.items.length+neighbour.items.length,replacement=direction<0?[...unit.items,...neighbour.items]:[...neighbour.items,...unit.items];const uid=blocks[index].uid, action=document.activeElement?.dataset.action;
   blocks.splice(start,count,...replacement);setDirty();render();
   if (['up','down','outline-up','outline-down'].includes(action)) {
     const inOutline=action.startsWith('outline-');
     const row=(inOutline?root.querySelector('[data-builder-outline]'):list).querySelector('[data-uid="'+CSS.escape(uid)+'"]');
     const same=row?.querySelector('[data-action="'+action+'"]:enabled');
     (same||row?.querySelector(inOutline?'.sensecms-builder-outline-jump':'[data-action="edit"]'))?.focus();
   }}
 function duplicateBlock(block,index){if(catalog[block.type].singleton){toast('warning',catalog[block.type].label+' can only be added once.');return}const copy=clone(block),unit=unitAt(index);copy.uid=uuid();copy.global_section_id=null;copy.global_name='';copy.layout=defaultLayout();blocks.splice(unit.end+1,0,copy);inspectorUid=copy.uid;setDirty();render()}
 function mediaField(type){return type==='hero-slider'?'slides':type==='gallery'?'items':null}
 function freshBlock(type){const definition=catalog[type],localized={};languages.forEach(language=>localized[language.locale]=localeDefaults(definition,language.locale));const key=mediaField(type);if(key)for(const data of Object.values(localized))for(const row of data[key]||[])row._item_id=uuid();return{uid:uuid(),type,source:definition.source,global_section_id:null,global_name:'',global_version:0,visible:true,visible_from:'',visible_until:'',layout:defaultLayout(),appearance:defaultAppearance(),shared:clone(definition.shared_defaults),localized}}
 const inserter=root.querySelector('[data-builder-inserter]'),appendButton=root.querySelector('[data-builder-append]'),backButton=root.querySelector('[data-builder-back]');
 let insertionAnchor='',insertKind='sections',returnFocus=null;
 function enterSectionEditor(){
   root.dataset.sectionEditing='true';
   if(backButton.offsetWidth)backButton.focus();
 }
 backButton.onclick=()=>{
   delete root.dataset.sectionEditing;
   const target=liveMode?liveFrame:list.querySelector('[data-uid="'+CSS.escape(inspectorUid)+'"] [data-action="edit"]');
   (target||appendButton)?.focus();
 };

 const inlineDialog=root.querySelector('[data-inline-dialog]'),inlineDocuments=new WeakSet();
 let inlineSequence=0;
 const toolTabs={edit:'inspector',build:'modules',review:'review'};
 root.querySelectorAll('[data-tool-workspace]').forEach(control=>control.onclick=()=>switchLibraryTab(toolTabs[control.dataset.toolWorkspace]));
 function showToolGroup(tab){
   const group=tab.closest('[data-tool-group]')?.dataset.toolGroup;
   if(!group)return;
   toolTabs[group]=tab.dataset.libraryTab;
   root.querySelectorAll('[data-tool-group]').forEach(panel=>panel.hidden=panel.dataset.toolGroup!==group);
   root.querySelectorAll('[data-tool-workspace]').forEach(control=>control.setAttribute('aria-pressed',String(control.dataset.toolWorkspace===group)));
 }
 function richTextParts(html){
   const template=document.createElement('template');template.innerHTML=html;
   const walker=document.createTreeWalker(template.content,NodeFilter.SHOW_TEXT),parts=[];
   for(let node=walker.nextNode();node;node=walker.nextNode()){
     if(node.textContent.trim()&&!node.parentElement?.closest('script,style,iframe,object,svg,math'))parts.push(node);
   }
   return {template,parts};
 }
 function bindPageHeader(doc){
   const title=doc.querySelector('main h1');
   if(!title||title.closest('[data-sensecms-builder-uid]'))return;
   const owner=title.closest('[data-sensecms-header-source]');
   const header=owner||title.closest('.product-hero,.subpage-hero,.platform-hero,.industry-hero')||title.closest('section,header')||title.parentElement;
   const productTheme=payload.theme.slug==='sensecms';
   const declared=owner?.dataset.sensecmsHeaderSource;
   const home=declared?declared==='theme':productTheme&&!!title.closest('.product-hero');
   const pageFields=declared?declared==='page':productTheme?header.matches('.subpage-hero,.platform-hero,.industry-hero'):
     payload.theme.slug==='sense-demo'?header.matches('.hero,.page-hero'):payload.theme.slug==='villa-theme'&&header.matches('.villa-page-intro,.villa-fallback-hero');
   const action=doc.createElement('button');
   action.type='button';action.className='sensecms-header-owner';
   action.textContent=pageFields?window.SenseCMSInterfaceText('Page header · Edit details'):home?window.SenseCMSInterfaceText('Page header · Theme settings'):window.SenseCMSInterfaceText('Page header · About this template');
   header.classList.add('sensecms-template-header');header.prepend(action);
   const explain=()=>{
     const dialog=root.querySelector('[data-header-dialog]');
     dialog.querySelector('p').textContent=pageFields?window.SenseCMSInterfaceText('In Page details, edit the Page title and Excerpt for the current page. The surrounding design comes from the active theme.'):home?window.SenseCMSInterfaceText('This header uses settings from the active theme. Changes to shared settings may appear on other pages.'):window.SenseCMSInterfaceText('This header is rendered by the active theme. Its design and fixed copy are defined in the theme template.');
     const link=dialog.querySelector('[data-header-go]');
     link.hidden=!pageFields&&!(home&&document.querySelector('a[href^="/appearance/themes"]'));
     link.textContent=pageFields?window.SenseCMSInterfaceText('Edit page details'):window.SenseCMSInterfaceText('Open theme settings');
     const destination=pageFields?'/content/pages/'+payload.page.id+'/edit?return='+encodeURIComponent(location.pathname+location.search):'/appearance/themes?configure='+encodeURIComponent(payload.theme.slug)+'#theme-workspace';
     dialog.querySelector('button').onclick=()=>dialog.close();
     link.onclick=event=>{event.preventDefault();dialog.close();const navigate=()=>location.assign(destination);if(needsLeaveWarning())window.SenseCMSUnsavedGuard.confirmLeave(navigate);else navigate()};
     dialog.showModal();
   };
   header.addEventListener('click',event=>{if(event.target.closest('[data-sensecms-builder-uid]'))return;event.preventDefault();event.stopPropagation();explain()});
   if(pageFields)for(const node of [title,title.nextElementSibling?.matches('p')?title.nextElementSibling:null]){
     editTarget(node,node===title?'page title':'page excerpt',()=>openSource('/content/pages/'+payload.page.id+'/edit'));
   }
 }
 function openSource(path){
   const url=new URL(path,location.origin);
   url.searchParams.set('return',location.pathname+location.search);url.searchParams.set('lang',activeLocale);
   const go=()=>location.assign(url.href);
   if(needsLeaveWarning())window.SenseCMSUnsavedGuard.confirmLeave(go);else go();
 }
 const bannerDialog=root.querySelector('[data-banner-dialog]'),bannerForm=bannerDialog.querySelector('form');
 let bannerSession=null,bannerPublishing=false;
 function bindChatbotBanner(frame){
   const banner=frame.contentDocument.querySelector('#private-ai-chatbot.chatbot-feature');
   if(!banner||!payload.theme?.slug)return;
   const fields={title:banner.querySelector('#chatbot-title'),intro:banner.querySelector('.chatbot-lead'),text:banner.querySelector('.chatbot-copy > p:not([class])')};
   if(!Object.values(fields).every(Boolean))return;
   const control=frame.contentDocument.createElement('button');
   control.type='button';control.className='sensecms-banner-edit';control.textContent=payload.permissions.banner?window.SenseCMSInterfaceText('Edit AI Chatbot banner'):window.SenseCMSInterfaceText('About this banner');
   banner.prepend(control);
   banner.addEventListener('click',event=>{
     if(event.target.closest('[data-canvas-element]'))return;
     event.preventDefault();event.stopPropagation();
     const key=Object.keys(fields).find(name=>fields[name].contains(event.target))||'title';
     openBannerEditor(frame,fields,key);
   },true);
 }
 async function openBannerEditor(frame,fields,key){
   if(bannerDialog.open)return;
   const locale=new URL(frame.src).searchParams.get('locale')==='pl'?'pl':'en';
   const endpoint='/appearance/themes/'+encodeURIComponent(payload.theme.slug)+'/chatbot-text';
   const session={locale,endpoint,fields};
   bannerSession=session;
   const status=bannerForm.querySelector('[data-banner-status]'),settings=bannerForm.querySelector('[data-banner-settings]');
   const controls=bannerForm.querySelector('fieldset');
   settings.hidden=true;settings.href='/appearance/themes?configure='+encodeURIComponent(payload.theme.slug)+'#theme-workspace';
   bannerForm.reset();controls.disabled=true;controls.hidden=!(canEdit&&payload.permissions.banner);
   bannerForm.querySelector('[data-banner-publish]').hidden=!(canEdit&&payload.permissions.banner);
   bannerForm.querySelector('[data-banner-publish]').disabled=true;
   bannerForm.querySelector('[data-banner-publish]').textContent=message('Apply to draft','Zastosuj do szkicu');
   bannerForm.querySelector('[data-banner-scope]').textContent=locale.toUpperCase()+' · '+message('Shared banner. Visitors see changes only after publication.','Wspólny baner. Odwiedzający zobaczą zmiany dopiero po publikacji.');
   status.textContent=canEdit&&payload.permissions.banner?window.SenseCMSInterfaceText('Loading banner text…'):window.SenseCMSInterfaceText('This banner is shared across the website. A user with permission to manage Appearance can edit it.');
   bannerDialog.showModal();
   if(!(canEdit&&payload.permissions.banner))return;
   try{
     const result=await request(endpoint+'?locale='+locale);
     if(bannerSession!==session||!bannerDialog.open)return;
     session.base=result.data;session.initial=pendingEdit('banner',locale)?.values||session.base.values;
     for(const name of Object.keys(fields)){
       const input=bannerForm.elements.namedItem(name);
       input.maxLength=session.base.limits[name];input.value=session.initial[name];
     }
     controls.disabled=false;status.textContent='';bannerForm.elements.namedItem(key).focus();
   }catch(error){if(bannerSession===session){status.textContent=error.message;settings.hidden=false}}
 }
 bannerForm.oninput=()=>{
   const base=bannerSession?.base;
   bannerForm.querySelector('[data-banner-publish]').disabled=bannerPublishing||!base||
     !Object.keys(base.values).some(key=>bannerForm.elements.namedItem(key).value.trim()!==bannerSession.initial[key]);
 };
 bannerDialog.addEventListener('cancel',event=>{if(bannerPublishing)event.preventDefault()});
 bannerDialog.addEventListener('close',()=>{
   bannerSession=null;
   liveFrame.contentDocument?.querySelector('.sensecms-banner-edit')?.focus({preventScroll:true});
 });
 bannerForm.onsubmit=async event=>{
   event.preventDefault();
   const session=bannerSession;
   if(bannerPublishing||!session?.base||!bannerForm.reportValidity())return;
   const values=Object.fromEntries(Object.keys(session.fields).map(key=>[key,bannerForm.elements.namedItem(key).value.trim()]));
   const status=bannerForm.querySelector('[data-banner-status]'),close=bannerForm.querySelector('[data-dialog-close]');
   bannerPublishing=true;close.disabled=true;bannerForm.querySelector('fieldset').disabled=true;bannerForm.oninput();
   try{
     queueThemeEdit({id:'banner',locale:session.locale,revision:session.base.revision,values,reset:false},session.base.values);
     for(const [key,node] of Object.entries(session.fields))node.textContent=values[key];
     bannerDialog.close();
   }catch(error){status.textContent=error.message;bannerForm.querySelector('[data-banner-settings]').hidden=false}
   finally{bannerPublishing=false;close.disabled=false;bannerForm.querySelector('fieldset').disabled=false;bannerForm.oninput()}
 };
 function bindInlineFields(){
   if(!canEdit||!inlineDialog||!liveFrame.contentDocument)return;
   const doc=liveFrame.contentDocument,locale=new URL(liveFrame.src).searchParams.get('locale');
   if(inlineDocuments.has(doc)||locale!==activeLocale)return;
   inlineDocuments.add(doc);
   bindPageHeader(doc);
   bindThemeElements(doc,locale);
   const sources=JSON.parse(doc.querySelector('[data-canvas-sources]')?.textContent||'{}');
   doc.querySelectorAll('[data-sensecms-builder-uid]').forEach(section=>{
     const block=blocks.find(item=>item.uid===section.dataset.sensecmsBuilderUid);
     if(!block)return;
     const data=block.localized[locale],definition=catalog[block.type];
     if(block.type==='news')section.querySelectorAll('.sense-block-news-grid article').forEach(article=>{
       const href=article.querySelector('h3 a')?.getAttribute('href'),path=sources[block.uid]?.[href];
       if(path)editTarget(article,'original '+(path.includes('/posts/')?'post':'page'),()=>openSource(path));
     });
     bindTextScope(section,block,locale,definition.fields,data,null);
     bindSectionElements(section,block,locale,definition.fields,data);
     if(block.type==='custom-html')bindMarkupObjects(section.querySelector('.sense-block-custom'),block,locale,data,'html',()=>blocks.find(b=>b.uid===block.uid)?.localized[locale]);
     else if(typeof data.text==='string')bindMarkupObjects(section.querySelector('.sense-block-copy'),block,locale,data,'text',()=>blocks.find(b=>b.uid===block.uid)?.localized[locale]);
     if(block.type==='custom-html')bindMarkupText(section,block,locale,definition.fields,data);
     if(block.type==='hero-slider'&&Array.isArray(data.slides)){
       const fields=definition.fields.find(item=>item.key==='slides')?.fields||[];
       const slides=[...section.querySelectorAll('[data-slide]')];
       if(slides.length===data.slides.length)slides.forEach((slide,index)=>{
         bindTextScope(slide,block,locale,fields,data.slides[index],index);
         bindMarkupObjects(slide.querySelector('.sense-block-copy'),block,locale,data.slides[index],'text',()=>blocks.find(b=>b.uid===block.uid)?.localized[locale]?.slides?.[index]);
       });
     }
   });
 }
 function bindMarkupObjects(surface,block,locale,data,key,read){
   if(!surface||!data[key])return;
   const original=data[key],source=richTextParts(original).template;
   const selector='a,img',nodes=[...source.content.querySelectorAll(selector)],shown=[...surface.querySelectorAll(selector)];
   if(nodes.length!==shown.length||nodes.some((n,i)=>n.tagName!==shown[i].tagName||n.textContent!==shown[i].textContent))return;
   nodes.forEach((node,i)=>{
     const image=node.tagName==='IMG',target=shown[i],name=image?'image':'link';
     const map=image?[['image','Image','image','src'],['alt','Image description','text','alt']]:[['url','Link destination','url','href']];
     if(!image&&node.childNodes.length===1&&node.firstChild.nodeType===Node.TEXT_NODE)map.unshift(['label','Link text','text',null]);
     const fields=map.map(([key,label,type])=>({key,label,type,max:key==='alt'?500:2048,required:key!=='alt'}));
     const initial=Object.fromEntries(map.map(([k,,,attr])=>[k,attr?node.getAttribute(attr)||'':node.textContent]));
     const current=()=>activeLocale===locale&&read()?.[key]===original&&!lockedByOther(block.uid);
     const open=()=>elementEditor(target,{title:'Edit '+name,scope:blockTitle(block)+' · '+locale.toUpperCase()+' · Save the page when your draft is ready.',fields,initial,ready:async()=>current()&&await reserveSection(block.uid)&&current(),commit:values=>{
       if(!current())throw Error('This section changed. Reopen the editor before applying.');
       const t=richTextParts(original).template,n=t.content.querySelectorAll(selector)[i];
       map.forEach(([k,,,attr])=>{if(attr)n.setAttribute(attr,values[k]);else n.textContent=values[k]});
       const limit=(catalog[block.type].fields.find(f=>f.key===key)?.max)||40000;
       if(t.innerHTML.length>limit)throw Error('The section exceeds its character limit.');
       read()[key]=t.innerHTML;inspectorUid=block.uid;setDirty();render();switchLibraryTab('inspector');
     }});
     editTarget(target,name,open);
     // A link inside a formatted paragraph takes precedence over the paragraph editor.
     target.addEventListener('click',e=>{if(e.target.closest('img')&&target.tagName!=='IMG')return;e.preventDefault();e.stopImmediatePropagation();open()},true);
   });
 }
 const elementDialog=root.querySelector('[data-element-dialog]');
 function editTarget(node,name,open){
   if(!node||node.hasAttribute('data-sense-inline'))return;
   node.dataset.senseInline=name;node.tabIndex=0;node.title='Edit '+name;
   const activate=event=>{
     if(event.type==='keydown'&&!['Enter',' '].includes(event.key))return;
     event.preventDefault();event.stopPropagation();open();
   };
   node.addEventListener('click',activate);node.addEventListener('keydown',activate);
   node.addEventListener('dblclick',event=>{event.preventDefault();event.stopPropagation()});
 }
 function validCanvasAddress(value,action){
   if(!value)return true;
   if(/[\s\\<>"'\x00-\x1f]/.test(value))return false;
   if(value.startsWith('/'))return !value.startsWith('//')&&!value.includes('..');
   if(action&&/^(#|tel:|mailto:)/i.test(value))return true;
   try{return ['https:','http:'].includes(new URL(value).protocol)}catch{return false}
 }
 async function elementEditor(target,{title,scope,fields,initial,commit,ready,reset=false,live=false}){
   if(!elementDialog||elementDialog.open)return;
   const form=elementDialog.querySelector('form'),group=form.querySelector('fieldset'),status=form.querySelector('[role=status]'),apply=form.querySelector('[data-element-apply]'),cancel=form.querySelector('[data-element-cancel]'),restore=form.querySelector('[data-element-reset]');
   const values={...initial},token={};elementEditor.session=token;let busy=false;
   form.querySelector('h3').textContent=title;form.querySelector('[data-element-scope]').textContent=scope;
   group.replaceChildren(...fields.map(f=>fieldNode(f,values,()=>update())));
   const update=()=>{apply.disabled=busy||group.disabled||fields.every(f=>values[f.key]===initial[f.key])};
   apply.textContent=message('Apply to draft','Zastosuj do szkicu');restore.hidden=!reset;
   group.disabled=true;restore.disabled=true;cancel.disabled=false;status.textContent=ready?window.SenseCMSInterfaceText('Opening editor…'):'';update();
   cancel.onclick=()=>elementDialog.close();
   elementDialog.oncancel=e=>{if(busy)e.preventDefault()};
   elementDialog.onclose=()=>{if(target.isConnected)target.focus({preventScroll:true});else liveFrame.focus()};
   async function save(revert){
     if(busy||group.disabled||(!revert&&(!form.reportValidity()||apply.disabled)))return;
     if(!revert&&fields.some(f=>['image','video','url'].includes(f.type)&&!validCanvasAddress(String(values[f.key]||'').trim(),f.type==='url'))){status.textContent='Enter a valid web address or a path beginning with /.';return}
     busy=true;group.disabled=restore.disabled=cancel.disabled=true;update();status.textContent=live?window.SenseCMSInterfaceText('Publishing…'):'';
     try{await commit(values,revert);elementDialog.close()}catch(error){status.textContent=error.message}
     finally{busy=false;group.disabled=restore.disabled=cancel.disabled=false;update()}
   }
   form.onsubmit=e=>{e.preventDefault();save(false)};restore.onclick=()=>save(true);
   elementDialog.showModal();
   try{if(ready&&!(await ready()))throw Error('This section is locked or has changed. Reopen the editor.');
     if(!elementDialog.open||elementEditor.session!==token)return;
     group.disabled=restore.disabled=false;status.textContent='';update();group.querySelector('input,textarea,select')?.focus();
   }catch(err){if(elementEditor.session===token)status.textContent=err.message}
 }
 function bindThemeElements(doc,locale){
   doc.querySelectorAll('[data-canvas-element]').forEach(node=>{
     const values=JSON.parse(node.dataset.canvasValues);
     const kind='image'in values?'image':'url'in values?'button or link':'text';
     editTarget(node,kind,()=>{
       if(!payload.permissions.pageElement){toast('warning',window.SenseCMSInterfaceText('A publisher for this page can edit this theme element.'));return}
       const names={text:['Text','textarea',4000],url:['Link destination','url',2048],image:['Image','image',2048],alt:['Image description','text',500]};
       const fields=['text','image','alt','url'].filter(k=>k in values).map(key=>({key,label:names[key][0],type:names[key][1],max:names[key][2],required:key!=='alt'}));
       const id=node.dataset.canvasElement,initial=pendingEdit(id,locale)?.values||values;
       elementEditor(node,{title:'Edit '+kind,scope:payload.page.title+' · '+locale.toUpperCase()+' · '+message('Only your draft changes until you publish.','Zmiana pozostaje w szkicu aż do publikacji.'),fields,initial,reset:node.hasAttribute('data-canvas-modified'),commit:async(next,reset)=>{
         const values=reset?JSON.parse(node.dataset.canvasDefaults):{...next};
         queueThemeEdit({id,locale,revision:node.dataset.canvasRevision,values,reset},JSON.parse(node.dataset.canvasBase));
       }});
     });
   });
 }
 function bindSectionElements(section,block,locale,fields,data){
   function scope(surface,defs,values,read,nested=false){
     const find=key=>defs.find(f=>f.key===key&&visibleField(f,values));
     const bind=(node,keys,name)=>{
       const controls=keys.map(find).filter(Boolean);
       if(!node||!controls.length)return;
       const initial=Object.fromEntries(controls.map(f=>[f.key,String(values[f.key]??'')]));
       const current=()=>{const item=read();return activeLocale===locale&&item&&controls.every(f=>String(item[f.key]??'')===initial[f.key])&&!lockedByOther(block.uid)};
       editTarget(node,name,()=>elementEditor(node,{title:'Edit '+name,scope:blockTitle(block)+' · '+locale.toUpperCase()+' · Save the page when your draft is ready.',fields:controls,initial,ready:async()=>current()&&await reserveSection(block.uid)&&current(),commit:next=>{
         if(!current())throw Error('This section changed. Reopen the editor before applying.');
         const item=read();for(const f of controls)item[f.key]=next[f.key];
         inspectorUid=block.uid;setDirty();render();switchLibraryTab('inspector');
       }}));
     };
     const own=node=>nested||!node.closest('[data-slide],.sense-block-gallery-grid figure,.sense-block-value-list article,.sense-block-service-grid article,.sense-block-stat-grid article,.sense-block-steps li,.sense-block-news-grid,.sense-block-custom,.contact-field');
     const nodes=selector=>[...surface.querySelectorAll(selector)].filter(own);
     for(const a of nodes('a[href]')){
       if(a.closest('.sense-block-copy'))continue;
       const prefix=nested?(a.classList.contains('button')?'primary':a.closest('[data-slide]')?'secondary':''):(a.closest('.sense-block-cta aside')?'panel':a.closest('.sense-block-services aside')?'note':block.type==='hero'?(a.classList.contains('button')?'primary':'secondary'):'cta');
       const keys=prefix?[prefix+'_label',prefix+'_url']:['label','url'];
       if(values[keys[0]]===a.textContent.trim())bind(a,keys,'button');
     }
     for(const img of nodes('img,video')){
       const key=img.tagName==='VIDEO'?'video':img.closest('.sense-block-services aside')?'note_image':'image';
       if(values[key]!==img.getAttribute('src'))continue;
       bind(img,[key,...(key==='video'?['poster']:['mobile_image','image_alt','alt'])],key==='video'?'video':'image');
     }
     const slots={statement:'blockquote',stamp_title:'.sense-block-story aside strong',stamp_text:'.sense-block-story aside p',stage_eyebrow:'figcaption span',stage_title:'figcaption strong',note_eyebrow:'.sense-block-services aside .eyebrow',note_title:'.sense-block-services aside h3',note_text:'.sense-block-services aside p',panel_eyebrow:'.sense-block-cta aside .eyebrow',panel_title:'.sense-block-cta aside h3',panel_text:'.sense-block-cta aside p',privacy_text:'.contact-form-card .form-note:not([id])',submit_label:'.contact-submit button'};
     if(nested&&block.type!=='hero-slider'&&block.type!=='contact-form')Object.assign(slots,{eyebrow:'figcaption span',title:'h3,figcaption strong',text:'p',value:'strong',label:'.sense-block-stat-grid article > span'});
     if(block.type==='contact-form'&&!nested)slots.text='.contact-form-card > p:first-of-type';
     for(const [key,selector] of Object.entries(slots)){
       if(find(key))bind(nodes(selector)[0],[key],find(key).label);
     }
     return bind;
   }
   const current=()=>blocks.find(b=>b.uid===block.uid)?.localized[locale];
   scope(section,fields,data,current);
   for(const [key,selector] of [['slides','[data-slide]'],['items',({gallery:'.sense-block-gallery-grid figure',values:'.sense-block-value-list article',programs:'.sense-block-service-grid article',statistics:'.sense-block-stat-grid article'})[block.type]],['steps','.sense-block-steps li'],['fields','.contact-field']]){
     const def=fields.find(f=>f.key===key);
     if(!def||!selector||!Array.isArray(data[key]))continue;
     const entries=data[key].map((item,index)=>({item,index})).filter(({item})=>block.type!=='gallery'||item.image);
     const shown=[...section.querySelectorAll(selector)];
     if(entries.length!==shown.length)continue;
     entries.forEach(({item,index},i)=>{
       const read=()=>current()?.[key]?.[index]===item?item:null;
       const bind=scope(shown[i],def.fields,item,read,true);
       if(key==='fields'){bind(shown[i].querySelector('label'),['label'],'field label');bind(shown[i].querySelector('input:not([type=checkbox]),textarea,select'),['label','placeholder','options'],'form field')}
     });
   }
 }
 const markupSelector='p,h2,h3,h4,h5,h6';
 function bindMarkupText(section,block,locale,fields,data){
   const field=fields.find(item=>item.key==='html'&&item.type==='code'),surface=section.querySelector('.sense-block-custom');
   if(!field||!surface||!data.html)return;
   const source=richTextParts(data.html).template.content.querySelectorAll(markupSelector),shown=surface.querySelectorAll(markupSelector);
   if(source.length!==shown.length||[...source].some((el,i)=>el.tagName!==shown[i].tagName||el.textContent!==shown[i].textContent))return;
   source.forEach((el,index)=>{
     if(!el.textContent.trim()||!simpleRichContent(el))return;
     const name=el.tagName==='P'?'Paragraph':'Heading';
     bindInlineTarget(shown[index],block,{...field,label:name},locale,data.html,el.textContent,{index});
   });
   const parts=richTextParts(data.html).parts,walker=section.ownerDocument.createTreeWalker(surface,NodeFilter.SHOW_TEXT),visible=[];
   for(let node=walker.nextNode();node;node=walker.nextNode())if(node.textContent.trim())visible.push(node);
   if(parts.length!==visible.length||parts.some((p,i)=>p.textContent!==visible[i].textContent))return;
   visible.forEach((node,index)=>{
     if(node.parentElement.closest('[data-sense-inline],a,script,style'))return;
     const target=section.ownerDocument.createElement('span');node.replaceWith(target);target.append(node);
     bindInlineTarget(target,block,{...field,label:'Text'},locale,data.html,node.textContent,index);
   });
 }
 function bindTextScope(section,block,locale,fields,data,slideIndex){
     const doc=section.ownerDocument;
     for(const key of ['title','eyebrow','text']){
       const field=fields.find(item=>item.key===key&&['text','textarea'].includes(item.type));
       if(!field||!visibleField(field,data)||typeof data[key]!=='string'||!data[key].trim())continue;
       const original=data[key];
       if(key==='text'){
         const copies=section.querySelectorAll('.sense-block-copy');
         if(copies.length!==1)continue;
         const source=richTextParts(original),walker=doc.createTreeWalker(copies[0],NodeFilter.SHOW_TEXT),nodes=[];
         for(let node=walker.nextNode();node;node=walker.nextNode())if(node.textContent.trim())nodes.push(node);
         if(!source.parts.length||nodes.length!==source.parts.length||nodes.some((node,index)=>node.textContent!==source.parts[index].textContent))continue;
         nodes.forEach((node,index)=>{
           const span=doc.createElement('span');node.replaceWith(span);span.append(node);
           bindInlineTarget(span,block,field,locale,original,node.textContent,index,slideIndex);
         });
       }else{
         const matches=[...section.querySelectorAll(key==='title'?'h1,h2':'.eyebrow')].filter(element=>element.textContent===original);
         if(matches.length===1)bindInlineTarget(matches[0],block,field,locale,original,original,null,slideIndex);
       }
     }
 }
 let richEditor=null;
 function simpleRichContent(container){
   const allowed=new Set(['P','BR','STRONG','B','EM','I','U','S','DEL','STRIKE','A']);
   return ![...container.querySelectorAll('*')].some(el=>!allowed.has(el.tagName)||[...el.attributes].some(attr=>!['href','target','rel'].includes(attr.name)&&!(attr.name==='style'&&/^text-align:\s*(left|center|right|justify);?$/i.test(attr.value))));
 }
 function richFragment(original,part){
   const source=richTextParts(original),markup=typeof part==='object'&&part!==null;
   const paragraph=markup?source.template.content.querySelectorAll(markupSelector)[part.index]:source.parts[part]?.parentElement?.closest('p');
   if(markup&&!paragraph)return null;
   const container=paragraph||source.template.content;
   if(!simpleRichContent(container))return null;
   if(markup){
     const editable=document.createElement('p');editable.innerHTML=paragraph.innerHTML;editable.style.textAlign=paragraph.style.textAlign;
     return {source,paragraph,html:editable.outerHTML};
   }
   return {source,paragraph,html:paragraph?paragraph.outerHTML:original};
 }
 function prepareRichEditor(fragment,heading){
   const wrapper=inlineDialog.querySelector('[data-inline-rich]');
   if(!richEditor){
     richEditor=new window.Quill(wrapper.querySelector('[data-rich-host]'),{theme:'snow',formats:['bold','italic','underline','strike','align','link'],modules:{toolbar:[['bold','italic','underline','strike'],[{align:[] }],['clean']]}});
     const labels={bold:'Bold',italic:'Italic',underline:'Underline',strike:'Strikethrough',clean:'Clear formatting'};
     Object.entries(labels).forEach(([cmd,label])=>{const control=wrapper.querySelector('.ql-'+cmd);control.title=label;control.setAttribute('aria-label',label)});
     wrapper.querySelectorAll('.ql-picker-item').forEach(item=>{const label='Align '+(item.dataset.value||'left');item.title=label;item.setAttribute('aria-label',label)});
     wrapper.querySelector('.ql-picker-label')?.setAttribute('aria-label',window.SenseCMSInterfaceText('Paragraph alignment'));
   }
   richEditor.enable(false);richEditor.root.setAttribute('aria-label',heading);richEditor.root.setAttribute('role','textbox');
   richEditor.setContents(richEditor.clipboard.convert({html:fragment.html.replace(/<(\/?)del\b/gi,'<$1s')}),'silent');
   richEditor.history.clear();
   return JSON.stringify(richEditor.getContents());
 }
 function richResult(fragment){
   const output=document.createElement('template');output.innerHTML=richEditor.root.innerHTML;
   output.content.querySelectorAll('[class]').forEach(el=>{
     const align=['center','right','justify'].find(value=>el.classList.contains('ql-align-'+value));
     if(align)el.style.textAlign=align;
     el.removeAttribute('class');
   });
   const original=document.createElement('template');original.innerHTML=fragment.html;
   const anchors=[...original.content.querySelectorAll('a')];
   output.content.querySelectorAll('a').forEach(link=>{
     const index=anchors.findIndex(item=>item.getAttribute('href')===link.getAttribute('href'));
     const previous=index>=0?anchors.splice(index,1)[0]:null;
     if(previous)for(const attr of ['rel','target']){if(previous.hasAttribute(attr))link.setAttribute(attr,previous.getAttribute(attr));else link.removeAttribute(attr)}
   });
   if(!fragment.paragraph)return output.innerHTML;
   let first=output.content.firstElementChild;
   if(first&&fragment.paragraph.tagName!=='P'){
     const heading=document.createElement(fragment.paragraph.localName);
     heading.style.textAlign=first.style.textAlign;heading.append(...first.childNodes);first.replaceWith(heading);first=heading;
   }
   if(first)for(const attr of fragment.paragraph.attributes){if(attr.name!=='style')first.setAttribute(attr.name,attr.value)}
   fragment.paragraph.replaceWith(output.content);
   return fragment.source.template.innerHTML;
 }
 function bindInlineTarget(target,block,field,locale,original,value,part,slideIndex=null){
   target.dataset.senseInline=field.key;target.tabIndex=0;target.title='Edit '+field.label;
   const activate=event=>{
     if(event.type==='keydown'&&!['Enter',' '].includes(event.key))return;
     event.preventDefault();event.stopPropagation();
     openInlineEditor(target,block,field,locale,original,value,part,slideIndex);
   };
   target.addEventListener('click',activate);target.addEventListener('keydown',activate);
   target.addEventListener('dblclick',event=>{event.preventDefault();event.stopPropagation()});
 }
 async function openInlineEditor(target,block,field,locale,original,value,part,slideIndex=null){
   if(inlineDialog.open||!canEdit||activeLocale!==locale)return;
   if(lockedByOther(block.uid)){toast('warning',window.SenseCMSInterfaceText('Another editor is working on this section.'));return}
   const current=()=>blocks.find(item=>item.uid===block.uid);
   const data=()=>{const values=current()?.localized[locale];return slideIndex===null?values:values?.slides?.[slideIndex]};
   const unchanged=()=>activeLocale===locale&&data()?.[field.key]===original;
   if(!unchanged()){toast('warning',window.SenseCMSInterfaceText('The preview is updating. Try editing again in a moment.'));return}
   const sequence=++inlineSequence,input=inlineDialog.querySelector('textarea'),apply=inlineDialog.querySelector('[data-inline-apply]'),status=inlineDialog.querySelector('[role=status]');
   const heading=inlineDialog.querySelector('label');
   heading.textContent=field.label+' · '+(languages.find(item=>item.locale===locale)?.native_name||locale);
   const fragment=part!==null&&window.Quill?richFragment(original,part):null;
   if((part==='whole'||part!==null&&typeof part==='object')&&!fragment){toast('warning',window.SenseCMSInterfaceText('Use the source field to edit this markup.'));return}
   const wrapper=inlineDialog.querySelector('[data-inline-rich]');
   wrapper.hidden=!fragment;input.hidden=!!fragment;
   const initial=fragment?prepareRichEditor(fragment,heading.textContent):null;
   input.value=value;input.required=!!field.required;
   input.maxLength=Number(field.max)||4000;input.rows=part===null?2:5;
   apply.disabled=true;input.disabled=true;status.textContent=window.SenseCMSInterfaceText('Opening editor…');
   const frame=target.ownerDocument===document?{left:0,top:0}:liveFrame.getBoundingClientRect(),box=target.getBoundingClientRect();
   inlineDialog.style.top=Math.max(12,Math.min(innerHeight-280,frame.top+box.top))+'px';
   inlineDialog.style.left=Math.max(12,Math.min(innerWidth-Math.min(540,innerWidth-24)-12,frame.left+box.left))+'px';
   inlineDialog.showModal();
   inlineDialog.style.top=Math.max(12,Math.min(innerHeight-inlineDialog.offsetHeight-12,frame.top+box.top))+'px';
   inlineDialog.querySelector('[data-inline-cancel]').onclick=()=>inlineDialog.close();
   input.onkeydown=event=>{if(event.key==='Enter'&&(part===null||event.ctrlKey||event.metaKey)&&!event.isComposing){event.preventDefault();apply.click()}};
   apply.onclick=()=>{
     if(apply.disabled||(!fragment&&!input.reportValidity()))return;
     if(fragment?JSON.stringify(richEditor.getContents())===initial:input.value===value){inlineDialog.close();return}
     if(!unchanged()||lockedByOther(block.uid)){status.textContent=window.SenseCMSInterfaceText('This section changed. Close the editor and try again.');apply.disabled=true;return}
     let next=fragment?richResult(fragment):input.value;
     if(!fragment&&part!==null){
       const source=richTextParts(original);
       source.parts[part].textContent=next;next=source.template.innerHTML;
     }
     if(next.length>(Number(field.max)||4000)){status.textContent=window.SenseCMSInterfaceText('The complete field exceeds its character limit.');return}
     inlineDialog.close();
     if(next===original)return;
     data()[field.key]=next;
     inspectorUid=block.uid;setDirty();render();switchLibraryTab('inspector');
     setLiveStatus('Updating private preview…','is-updating');
   };
   inlineDialog.onclose=()=>{if(target.isConnected)target.focus({preventScroll:true});else liveFrame.focus()};
   const acquired=await reserveSection(block.uid);
   if(!inlineDialog.open||sequence!==inlineSequence)return;
   if(!acquired||!unchanged()){status.textContent=window.SenseCMSInterfaceText('This section is unavailable. Close the editor and try again.');return}
   input.disabled=false;apply.disabled=false;status.textContent=window.SenseCMSInterfaceText('Apply to draft');if(fragment){richEditor.enable();richEditor.focus()}else{input.focus();input.select()}
 }

 function gapButton(block){
   const control=document.createElement('button');
   control.type='button';control.className='sensecms-builder-gap';control.disabled=blocks.length>=60;
   control.append(icon('circle-plus'),document.createTextNode(window.SenseCMSInterfaceText('Add section')));
   control.setAttribute('aria-label','Add section before '+blockTitle(block));
   control.onclick=()=>showInserter(block.uid);
   return control;
 }
 function showInserter(beforeUid=''){
   if(!canEdit||!inserter||inserter.open||blocks.length>=60)return;
   const next=beforeUid&&blockUnits().find(unit=>unit[0].uid===beforeUid)?.[0];
   if(beforeUid&&!next)return;
   insertionAnchor=beforeUid;insertKind='sections';returnFocus=document.activeElement;
   inserter.querySelector('[data-insert-position]').textContent=next?'Before '+blockTitle(next):blocks.length?window.SenseCMSInterfaceText('At the end of the page'):window.SenseCMSInterfaceText('First section');
   const search=inserter.querySelector('input');search.value='';
   renderInserter();inserter.showModal();search.focus();
 }
 function renderInserter(){
   const target=inserter.querySelector('[data-insert-results]'),query=inserter.querySelector('input').value.trim().toLocaleLowerCase();
   for(const button of inserter.querySelectorAll('[data-insert-kind]')){
     const active=button.dataset.insertKind===insertKind;
     button.classList.toggle('is-active',active);button.setAttribute('aria-pressed',String(active));
   }
   const choose=insertKind==='sections'?addBlock:insertPattern;
   const renderChoices=insertKind==='sections'?renderLibrary:renderPatterns;
   renderChoices(target,query,item=>{inserter.close();choose(item,insertionAnchor)});
   if(!target.childElementCount)target.textContent=window.SenseCMSInterfaceText('No matching sections. Try a different search.');
 }
 if(inserter){
   appendButton.onclick=()=>showInserter();
   inserter.querySelector('[data-insert-close]').onclick=()=>inserter.close();
   inserter.querySelector('input').oninput=renderInserter;
   inserter.querySelectorAll('[data-insert-kind]').forEach(control=>control.onclick=()=>{insertKind=control.dataset.insertKind;renderInserter()});
   inserter.addEventListener('cancel',()=>returnFocus?.focus());
 }
 function insertSections(added,beforeUid){
   if(!canEdit||!added.length||blocks.length+added.length>60)return false;
   const index=beforeUid?blocks.findIndex(block=>block.uid===beforeUid):blocks.length;
   if(index<0||(beforeUid&&unitAt(index).start!==index)){toast('warning',window.SenseCMSInterfaceText('The insertion point changed. Choose a position again.'));return false}
   blocks.splice(index,0,...added);
   selected.clear();inspectorUid=added[0].uid;
   setDirty();render();switchLibraryTab('inspector');enterSectionEditor();
   if(!backButton.offsetWidth){const heading=inspector.querySelector('header');if(heading){heading.tabIndex=-1;heading.focus({preventScroll:true})}}
   return true;
 }
 // Receive the drag above the iframe, mapping the pointer to the preview's section boundaries.
 let cancelCanvasDrag=()=>{};
 function enableCanvasDrag(control,type=null) {
   control.draggable=liveMode;
   control.addEventListener('dragstart',event=>{
     if(!canEdit||!liveMode||schedule||!liveFrame.contentDocument?.body?.hasAttribute('data-sensecms-builder-preview')){
       event.preventDefault();return;
     }
     cancelCanvasDrag();
     const moving=type===null, source=moving?inspectorUid:type, effect=moving?'move':'copy';
     const format=moving?'application/x-sensecms-move':'application/x-sensecms-section';
     if(!source||control.disabled){event.preventDefault();return}
     const frame=liveFrame, stage=frame.parentElement;
     const surface=document.createElement('div'), line=document.createElement('span');
     surface.className='sensecms-builder-drop-surface';line.className='sensecms-builder-drop-line';
     line.hidden=true;surface.append(line);
     event.dataTransfer.effectAllowed=effect;
     event.dataTransfer.setData(format,source);
     stage.append(surface);
     function position(event) {
       if(frame!==liveFrame||schedule||(!moving&&blocks.length>=60))return null;
       const rect=frame.getBoundingClientRect(), y=event.clientY-rect.top;
       if(event.clientX<rect.left||event.clientX>rect.right||y<0||y>rect.height)return null;
       const sections=new Map(Array.from(frame.contentDocument.querySelectorAll('[data-sensecms-builder-uid]'),node=>[node.dataset.sensecmsBuilderUid,node]));
       let nearest=null,distance=Infinity;
       for(const items of blockUnits()){
         const boxes=items.map(item=>sections.get(item.uid)?.getBoundingClientRect()).filter(box=>box?.height);
         if(!boxes.length)continue;
         const top=Math.min(...boxes.map(box=>box.top)), bottom=Math.max(...boxes.map(box=>box.bottom));
         if(Math.abs(y-top)<distance){nearest={uid:items[0].uid,target:items[0].uid,side:'before',y:top};distance=Math.abs(y-top)}
         if(Math.abs(y-bottom)<distance){
           const last=items[items.length-1];
           nearest={uid:blocks[blocks.indexOf(last)+1]?.uid||'',target:last.uid,side:'after',y:bottom};distance=Math.abs(y-bottom);
         }
       }
       if(moving){
         if(!nearest)return null;
         const plan=plannedMove(source,nearest.target,nearest.side);
         return plan.order?{...nearest,order:plan.order}:null;
       }
       return nearest||{uid:'',y:36};
     }
     surface.ondragover=event=>{
       const dest=position(event);
       const box=frame.getBoundingClientRect(),overlay=surface.getBoundingClientRect();
       if(event.clientY<box.top+45)frame.contentWindow.scrollBy(0,-32);
       else if(event.clientY>box.bottom-45)frame.contentWindow.scrollBy(0,32);
       line.hidden=!dest;
       if(!dest)return;
       event.preventDefault();event.dataTransfer.dropEffect=effect;
       const y=Math.max(4,Math.min(box.height-28,dest.y));
       line.style.top=(box.top-overlay.top+y)+'px';
       line.style.left=(box.left-overlay.left)+'px';line.style.width=box.width+'px';
       const target=blocks.find(block=>block.uid===dest.uid);
       line.textContent=(moving?'Move':'Insert')+(target?' before '+blockTitle(target):' at end of page');
     };
     surface.ondragleave=()=>{line.hidden=true};
     surface.ondrop=event=>{
       event.preventDefault();
       const dest=position(event);
       cleanup();
       if(!dest||event.dataTransfer.getData(format)!==source)return;
       if(moving){
         blocks=dest.order;inspectorUid=source;
         setDirty();render();toast('success',window.SenseCMSInterfaceText('Section moved. Undo restores its previous position.'));
       }else addBlock(type,dest.uid);
     };
     function cleanup(){
       cancelCanvasDrag=()=>{};
       surface.remove();
       control.removeEventListener('dragend',cleanup);
       lifecycle.signal.removeEventListener('abort',cleanup);
     }
     cancelCanvasDrag=cleanup;
     control.addEventListener('dragend',cleanup);
     lifecycle.signal.addEventListener('abort',cleanup,{once:true});
   });
 }
 function addBlock(type,beforeUid=''){const definition=catalog[type];if(definition.singleton&&blocks.some(block=>block.type===type)){toast('warning',definition.label+' is already on this page.');return}insertSections([freshBlock(type)],beforeUid)}
 function addGlobal(global){if(!catalog[global.type]){toast('warning',window.SenseCMSInterfaceText('This shared section type is unavailable.'));return}if(blocks.some(block=>block.global_section_id===Number(global.id))){toast('warning',window.SenseCMSInterfaceText('This shared section is already on the page.'));return}if(catalog[global.type].singleton&&blocks.some(block=>block.type===global.type)){toast('warning',catalog[global.type].label+' can only be added once.');return}const block=normalize({uid:uuid(),type:global.type,source_theme:global.source_theme,global_section_id:global.id,global_name:global.name,visible:true,shared:global.shared,localized:global.localized});blocks.push(block);inspectorUid=block.uid;setDirty();render()}
 function renderLibrary(target=root.querySelector('[data-builder-library]'),query=root.querySelector('[data-builder-search]').value.toLocaleLowerCase(),choose=addBlock){const groups={};Object.entries(catalog).forEach(([type,item])=>{if(query&&!`${item.label} ${item.description} ${item.group}`.toLocaleLowerCase().includes(query))return;(groups[item.group]??=[]).push([type,item])});target.replaceChildren(...Object.entries(groups).map(([group,items])=>{const section=document.createElement('section');section.className='sensecms-builder-library-group';const title=document.createElement('strong');title.textContent=group;section.append(title);items.forEach(([type,item])=>{const add=document.createElement('button');add.type='button';add.className='sensecms-builder-library-item';add.disabled=!canEdit||blocks.length>=60||(item.singleton&&blocks.some(block=>block.type===type));const tile=document.createElement('i');tile.append(icon(item.icon));if(target.hasAttribute('data-insert-results')){tile.className='sensecms-insert-sketch';tile.dataset.shape=/gallery|values|services|collection|highlight|steps/i.test(type)?'cards':/hero|banner|image|slider/i.test(type)?'cover':'text';tile.setAttribute('aria-hidden','true')}const copy=document.createElement('span'),name=document.createElement('strong'),description=document.createElement('span'),source=document.createElement('small');name.textContent=item.label;description.textContent=item.singleton&&blocks.some(block=>block.type===type)?window.SenseCMSInterfaceText('Already added'):item.description;source.textContent=item.source_label||window.SenseCMSInterfaceText('Module');copy.append(name,description,source);add.append(tile,copy,icon(add.disabled?'check':'plus'));add.onclick=()=>choose(type);if(target.hasAttribute('data-builder-library')&&!add.disabled)enableCanvasDrag(add,type);section.append(add)});return section}));window.lucide?.createIcons()}
 function compatiblePattern(pattern){const reserved=new Set(blocks.filter(block=>catalog[block.type]?.singleton).map(block=>block.type)),room=Math.max(0,60-blocks.length),types=[];let skipped=0;for(const type of pattern.sections||[]){const definition=catalog[type];if(!definition||types.length>=room||(definition.singleton&&reserved.has(type))){skipped++;continue}types.push(type);if(definition.singleton)reserved.add(type)}return{types,skipped}}
 function insertPattern(pattern,beforeUid=''){const plan=compatiblePattern(pattern);if(!plan.types.length){toast('warning',blocks.length>=60?window.SenseCMSInterfaceText('A page can contain up to 60 sections.'):window.SenseCMSInterfaceText('Every compatible section from this pattern is already on the page.'));return}const apply=()=>{const added=plan.types.map(freshBlock);if(!insertSections(added,beforeUid))return;toast('success',pattern.label+' added · '+added.length+' '+(added.length===1?'section':'sections')+(plan.skipped?' · '+plan.skipped+' conflict'+(plan.skipped===1?'':'s')+' skipped':'')+'.')};const message='This will insert '+plan.types.length+' independent, fully editable '+(plan.types.length===1?'section':'sections')+' at the selected position.'+(plan.skipped?' '+plan.skipped+' conflicting or unavailable '+(plan.skipped===1?'section will':'sections will')+' be skipped.':'');if(window.SenseCMSUI?.modal)window.SenseCMSUI.modal('Add '+pattern.label+' pattern',message,apply);else apply()}
 function renderPatterns(target=root.querySelector('[data-builder-patterns]'),query=root.querySelector('[data-builder-pattern-search]').value.toLocaleLowerCase(),choose=insertPattern){if(!target)return;const groups={};patterns.forEach(pattern=>{if(query&&!`${pattern.label} ${pattern.description} ${pattern.group} ${(pattern.sections||[]).map(type=>catalog[type]?.label||type).join(' ')}`.toLocaleLowerCase().includes(query))return;(groups[pattern.group||'Page layouts']??=[]).push(pattern)});if(!Object.keys(groups).length){target.innerHTML=("<div class=\"sensecms-builder-global-empty\"><i data-lucide=\"search-x\"></i><strong>"+window.SenseCMSInterfaceHtml("No matching patterns")+"</strong><span>"+window.SenseCMSInterfaceHtml("Try a page purpose, section name or category.")+"</span></div>");window.lucide?.createIcons();return}target.replaceChildren(...Object.entries(groups).map(([group,items])=>{const section=document.createElement('section');section.className='sensecms-builder-library-group';const title=document.createElement('strong');title.textContent=group;section.append(title);items.forEach(pattern=>{const plan=compatiblePattern(pattern),card=document.createElement('article');card.className='sensecms-builder-pattern';const head=document.createElement('header'),tile=document.createElement('i'),copy=document.createElement('div'),name=document.createElement('strong'),description=document.createElement('span');tile.append(icon(pattern.icon||'layout-template'));name.textContent=pattern.label;description.textContent=pattern.description;copy.append(name,description);head.append(tile,copy);const sequence=document.createElement('div');sequence.className='sensecms-builder-pattern-sequence';(pattern.sections||[]).forEach(type=>{if(!catalog[type])return;const item=document.createElement('span');item.append(icon(catalog[type].icon),document.createTextNode(catalog[type].label));sequence.append(item)});const footer=document.createElement('footer'),meta=document.createElement('small'),add=document.createElement('button');meta.textContent=(pattern.sections||[]).length+' section'+((pattern.sections||[]).length===1?'':'s')+(plan.skipped?' · '+plan.skipped+' skipped':'');add.type='button';add.disabled=!plan.types.length;add.append(icon(plan.types.length?'plus':'check'),document.createTextNode(plan.types.length?window.SenseCMSInterfaceText('Insert pattern'):window.SenseCMSInterfaceText('Already included')));add.onclick=()=>choose(pattern);footer.append(meta,add);card.append(head,sequence,footer);section.append(card)});return section}));window.lucide?.createIcons()}
 const usageDialog=root.querySelector('[data-shared-usage]');
 let usageRequest=0;
 function detachSection(block){
   if(!canEdit||lockedByOther(block.uid))return;
   window.SenseCMSUI?.modal('Create a local copy','After saving, edits to this section will apply only to this page.',()=>{
     if(!blocks.includes(block)||lockedByOther(block.uid))return;
     block.global_section_id=null;block.global_name='';block.global_version=0;
     setDirty();render();
   });
 }
 function usageButton(id,name){
   const button=document.createElement('button');
   button.type='button';button.className='btn bg-default-150';button.textContent=window.SenseCMSInterfaceText('View pages');
   button.onclick=()=>showSharedPages(id,name);
   return button;
 }
 function sharedActions(block,editable){
   const actions=document.createElement('div');
   actions.className='sensecms-builder-style-tools sensecms-builder-linked-actions';
   actions.append(usageButton(block.global_section_id,block.global_name||blockTitle(block)));
   if(editable){
     const local=document.createElement('button');
     local.type='button';local.className='btn bg-default-150';local.textContent=window.SenseCMSInterfaceText('Create a local copy');
     local.onclick=()=>detachSection(block);
     actions.append(local);
   }
   return actions;
 }
 async function showSharedPages(id,name){
   const generation=++usageRequest, status=usageDialog.querySelector('[data-usage-status]'),list=usageDialog.querySelector('[data-usage-pages]');
   usageDialog.querySelector('[data-usage-name]').textContent=name;
   list.replaceChildren();status.textContent=window.SenseCMSInterfaceText('Loading saved uses…');usageDialog.showModal();
   try {
     const response=await request('/content/builder/'+payload.page.id+'/shared/'+id+'/usage');
     if(!usageDialog.open||generation!==usageRequest)return;
     const pages=response.data.pages;
     status.textContent=pages.length?'Used on '+pages.length+' saved page'+(pages.length===1?'':'s')+' you can access.':window.SenseCMSInterfaceText('No saved pages you can access use this section.');
     for(const page of pages){
       const row=document.createElement('li'),link=document.createElement('a'),facility=document.createElement('small');
       link.textContent=page.title+(Number(page.id)===Number(payload.page.id)?' (current page)':'');
       link.href='/content/builder?document='+encodeURIComponent(page.id);
       link.target='_blank';link.rel='noopener';link.title=window.SenseCMSInterfaceText('Open in a new tab');
       facility.textContent=page.facility;row.append(link,facility);list.append(row);
     }
   }catch(error){if(usageDialog.open&&generation===usageRequest)status.textContent=error.message||window.SenseCMSInterfaceText('Unable to load saved uses.')}
 }
 function renderGlobals(){const target=root.querySelector('[data-builder-global-library]'),query=root.querySelector('[data-builder-global-search]').value.toLocaleLowerCase();root.querySelector('[data-builder-global-count]').textContent=String(globals.length);const items=globals.filter(item=>!query||`${item.name} ${catalog[item.type]?.label||item.type}`.toLocaleLowerCase().includes(query));if(!items.length){target.innerHTML='<div class="sensecms-builder-global-empty"><i data-lucide="combine"></i><strong>'+(globals.length?'No matching shared sections':'No shared sections yet')+("</strong><span>"+window.SenseCMSInterfaceHtml("Save a configured page section to reuse it across the website.")+"</span></div>");window.lucide?.createIcons();return}target.replaceChildren(...items.map(item=>{const row=document.createElement('div');row.className='sensecms-builder-library-item';const tile=document.createElement('i');tile.append(icon(catalog[item.type]?.icon||'panel-top'));const copy=document.createElement('span'),name=document.createElement('strong'),description=document.createElement('span'),source=document.createElement('small'),actions=document.createElement('div'),add=document.createElement('button'),rename=document.createElement('button'),archive=document.createElement('button');name.textContent=item.name;description.textContent=(catalog[item.type]?.label||item.type);source.textContent=window.SenseCMSInterfaceText('Shared');copy.append(name,description,source,usageButton(item.id,item.name));actions.className='sensecms-builder-global-actions';add.type=rename.type=archive.type='button';add.title=window.SenseCMSInterfaceText('Add shared section');add.append(icon(blocks.some(block=>block.global_section_id===Number(item.id))?'check':'plus'));add.disabled=blocks.some(block=>block.global_section_id===Number(item.id));add.onclick=()=>addGlobal(item);rename.title=window.SenseCMSInterfaceText('Rename shared section');rename.append(icon('pencil'));rename.onclick=()=>openRename(item);archive.title=window.SenseCMSInterfaceText('Archive shared section');archive.className='is-danger';archive.append(icon('trash-2'));archive.onclick=()=>archiveGlobal(item);actions.append(add,rename,archive);row.append(tile,copy,actions);return row}));window.lucide?.createIcons()}
 function localeNavigation(){updateState();window.SenseCMSContentLanguage?.remember(activeLocale);const nav=root.querySelector('[data-builder-locales]');nav.replaceChildren(...languages.map(language=>{const tab=document.createElement('button');tab.type='button';tab.className=language.locale===activeLocale?'is-active':'';tab.setAttribute('aria-pressed',String(language.locale===activeLocale));if(language.flag){const flag=document.createElement('img');flag.src=language.flag;flag.alt='';tab.append(flag)}const label=document.createElement('b');label.textContent=language.native_name||language.name||language.locale;const code=document.createElement('span');code.textContent=language.locale.toUpperCase();tab.append(label,code);tab.onclick=()=>{activeLocale=language.locale;localeNavigation();render();if(liveMode)refreshLivePreview()};return tab}));}
 function openDialog(dialog){if(!dialog)return;if(dialog instanceof HTMLDialogElement){dialog.showModal();return}dialog.hidden=false;document.body.style.overflow='hidden';dialog.querySelector('input,button')?.focus();window.lucide?.createIcons()}
 function closeDialog(dialog){if(!dialog)return;if(dialog instanceof HTMLDialogElement){dialog.close();return}dialog.hidden=true;if(!root.querySelector('.sensecms-builder-modal:not([hidden])'))document.body.style.overflow=''}
 async function openDraftMerge(draft=null){
   if(mergeOpen||draftSaving||saving)return;
   mergeOpen=true;
   const dialog=root.querySelector('[data-merge-dialog]'),list=dialog.querySelector('[data-merge-fields]');
   const back=dialog.querySelector('[data-merge-back]'),apply=dialog.querySelector('[data-merge-apply]');
   const error=dialog.querySelector('[data-merge-error]'),summary=dialog.querySelector('[data-merge-summary]');
   const source=draft?{source:'saved'}:{source:'tab',from:version,base:JSON.parse(savedFingerprint).blocks,blocks:blockPayload(),themeEdits:clone(themeEdits)};
   const api=(extra={})=>request('/content/builder/'+payload.page.id+'/draft/merge',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...source,...extra,csrf:payload.csrf})});
   let plan,choices={},posting=false;
   const labels=new Map([...blocks,...(draft?.blocks||[])].map(b=>[b.uid,b.localized?.[activeLocale]?.title||catalog[b.type]?.label||b.type]));
   const mediaLabels={
     title:message('Title','Tytuł'),alt:message('Image description','Opis obrazu'),text:message('Text','Treść'),eyebrow:message('Overline','Nadtytuł'),
     image:message('Image','Obraz'),mobile_image:message('Mobile image','Obraz mobilny'),video:message('Video','Film'),poster:message('Video cover','Okładka filmu'),
     focus_x:message('Horizontal focus','Kadr w poziomie'),focus_y:message('Vertical focus','Kadr w pionie'),media_type:message('Media type','Typ pliku'),
     primary_label:message('Main button','Przycisk główny'),primary_url:message('Main button URL','Adres przycisku głównego'),
     secondary_label:message('Second button','Drugi przycisk'),secondary_url:message('Second button URL','Adres drugiego przycisku')
   };
   function display(value,media=null){
     if(value===null)return message('Removed','Usunięto');
     if(Array.isArray(value))return value.map(v=>display(v,media)).join('\n');
     if(typeof value==='object')return Object.entries(value).filter(([k,v])=>k!=='_item_id'&&(!media||v!=='')).map(([k,v])=>(media?(mediaLabels[k]||k):k)+' · '+display(v,media)).join('\n');
     return String(media?.names?.[value]??labels.get(value)??(value===''?message('(empty)','(puste)'):value));
   }
   function update(){apply.disabled=posting||!plan||plan.conflicts.some(c=>!(c.key in choices))}
   function field(conflict){
     const group=document.createElement('fieldset'),legend=document.createElement('legend'),path=conflict.path;
     const sectionTitle=labels.get(path[1])||message('Section','Sekcja');
     const names={title:message('Headline','Nagłówek'),text:message('Content','Treść'),image:message('Image','Obraz'),cta_label:message('Button label','Tekst przycisku'),cta_url:message('Button link','Adres przycisku')};
     const fields=path.slice(2).filter(k=>k!=='localized').map(k=>languages.some(l=>l.locale===k)?k.toUpperCase():(names[k]||k));
     legend.textContent=path[0]==='order'?message('Section order','Kolejność sekcji'):path[0]==='theme'?[path[2]==='banner'?'AI Chatbot':message('Theme element','Element motywu'),path[1].toUpperCase(),...path.slice(3)].join(' · '):[sectionTitle,...fields].join(' · ');
     if(conflict.media){
       const m=conflict.media,kind=m.kind==='slide'?message('Slide','Slajd'):message('Gallery image','Zdjęcie galerii');
       const reason={order:message('Order','Kolejność'),identity:message('Older list: items cannot be matched safely','Starsza lista: nie można jednoznacznie dopasować elementów'),limit:message('Combined list exceeds 10 items','Połączona lista przekracza 10 elementów')};
       const detail=m.reason?reason[m.reason]:[kind+' '+m.number,m.title,path.length>7?(mediaLabels[path[7]]||names[path[7]]||path[7]):''].filter(Boolean).join(' · ');
       legend.textContent=[sectionTitle,path[3].toUpperCase(),detail].join(' · ');
     }
     group.append(legend);
     function option(mode,title,value){
       const label=document.createElement('label'),radio=document.createElement('input'),copy=document.createElement('strong'),body=document.createElement('span');
       radio.type='radio';radio.name=conflict.key;copy.textContent=title;body.textContent=display(value,conflict.media);
       label.append(radio,copy,body);group.append(label);
       radio.onchange=()=>{choices[conflict.key]=mode;update()};
     }
     option('live',message('On the page','Na stronie'),conflict.live);
     option('mine',message('In my draft','W moim szkicu'),conflict.mine);
     if(typeof conflict.live==='string'&&typeof conflict.mine==='string'){
       const own=document.createElement('label'),radio=document.createElement('input'),input=document.createElement('textarea');
       radio.type='radio';radio.name=conflict.key;const title=message('Use my own text','Wpisz własną treść');
       own.append(radio,document.createTextNode(title));group.append(own,input);
       input.setAttribute('aria-label',title);input.value=conflict.mine;input.hidden=true;
       group.addEventListener('change',()=>{input.hidden=!radio.checked});
       radio.onchange=()=>{choices[conflict.key]={text:input.value};input.hidden=false;input.focus();update()};
       input.oninput=()=>{choices[conflict.key]={text:input.value};update()};
     }
     return group;
   }
   dialog.querySelector('h3').textContent=mergeLabel();
   back.textContent=message('Back','Wróć');apply.textContent=message('Save merged draft','Zapisz połączony szkic');
   back.onclick=()=>dialog.close();dialog.oncancel=e=>{if(posting)e.preventDefault()};
   dialog.onclose=()=>{mergeOpen=false;if(!draft&&dirty&&!mergeNeeded)scheduleDraft()};
   async function compare(){
     plan=null;choices={};list.replaceChildren();update();error.hidden=true;
     summary.textContent=message('Comparing versions…','Porównuję wersje…');
     try{
       const result=await api();if(!dialog.open)return;
       plan=result.data;
       summary.textContent=message('Draft v','Szkic v')+plan.from+' → v'+plan.version+'. '+(plan.conflicts.length?message('Independent edits are combined. Choose the content for each difference below.','Niezależne zmiany zostaną połączone. Wybierz treść dla każdej z poniższych różnic.'):message('The edits can be combined automatically.','Zmiany można połączyć automatycznie.'));
       if(!plan.known)summary.textContent+=' '+message('Original version unavailable. Review differing values.','Brakuje wersji bazowej. Sprawdź różniące się wartości.');
       list.replaceChildren(...plan.conflicts.map(field));update();
     }catch(err){if(dialog.open){summary.textContent='';error.textContent=err.message;error.hidden=false}}
   }
   apply.onclick=async()=>{
     if(apply.disabled)return;
     posting=true;back.disabled=true;update();
     try{
       const {data}=await api({token:plan.token,version:plan.version,choices});
       version=data.version;mergeNeeded=false;draftError=false;saveError=false;publicationUnconfirmed=false;
       blocks=data.live.map(normalize);themeEdits=[];savedFingerprint=fingerprint();
       blocks=data.blocks.map(normalize);themeEdits=data.themeEdits.map(({id,locale,revision,values,reset,baseValues})=>({id,locale,revision,values,reset,baseValues}));
       draftFingerprint=fingerprint();draftSavedAt=data.updated_at;history=[savedFingerprint];historyIndex=0;
       if(draftFingerprint!==savedFingerprint){history.push(draftFingerprint);historyIndex++}
       inspectorUid=blocks.find(b=>b.uid===inspectorUid)?.uid||blocks[0]?.uid||'';
       dialog.close();if(draft)closeDialog(root.querySelector('[data-builder-recovery-dialog]'));
       render();setDirty(true,{remember:false,recover:false});updateHistoryControls();if(liveMode)refreshLivePreview();
       toast('success',message('Merged draft saved privately.','Połączony szkic zapisano prywatnie.'));
     }catch(err){
       if(err.status===409){await compare();error.textContent=message('A newer version arrived. Review the comparison again.','Pojawiła się nowsza wersja. Sprawdź ponownie porównanie.')}
       else error.textContent=err.message;
       error.hidden=false;
     }finally{posting=false;back.disabled=false;update()}
   };
   apply.disabled=true;openDialog(dialog);await compare();
 }
 function offerRecovery(){
   const draft=payload.draft;
   if(!draft||!Array.isArray(draft.blocks))return;
   const recovered=draft.blocks.filter(block=>catalog[block.type]).map(normalize);
   const edits=(draft.themeEdits||[]).map(({id,locale,revision,values,reset,baseValues})=>({id,locale,revision,values,reset,baseValues}));
   const recoveredFingerprint=JSON.stringify({blocks:recovered.map(({uid,type,source,global_section_id,global_version,visible,visible_from,visible_until,layout,appearance,shared,localized})=>({uid,type,source,global_section_id,global_version,visible,visible_from,visible_until,layout,appearance,shared,localized})),themeEdits:edits});
   if(recoveredFingerprint===savedFingerprint){discardDraft(true);return}
   const dialog=root.querySelector('[data-builder-recovery-dialog]'),restore=dialog.querySelector('[data-builder-recovery-restore]'),discard=dialog.querySelector('[data-builder-recovery-discard]'),error=dialog.querySelector('[data-builder-recovery-error]'),warning=dialog.querySelector('[data-builder-recovery-warning]');
   const stale=Number(draft.base_version)!==version||recovered.some(block=>block.global_section_id && blocks.some(live=>live.uid===block.uid && live.global_version!==block.global_version));
   let confirming=false,busy=false;
   function show(confirm){
     confirming=confirm;error.hidden=true;
     dialog.querySelector('h3').textContent=confirm?message('Discard this private draft?','Odrzucić ten prywatny szkic?'):message('Your saved draft','Twój zapisany szkic');
     dialog.querySelector('#builder-recovery-copy').textContent=confirm?message('Your draft edits will be removed. The saved page will stay as it is.','Zmiany ze szkicu zostaną usunięte. Zapisana wersja strony pozostanie bez zmian.'):message('Continue editing where you left off. These changes have not been published.','Możesz kontynuować edycję od miejsca, w którym została przerwana. Zmiany ze szkicu nie zostały opublikowane.');
     restore.textContent=confirm?message('Keep draft','Zachowaj szkic'):stale?mergeLabel():message('Continue editing','Kontynuuj edycję');
     discard.textContent=confirm?message('Yes, discard draft','Tak, odrzuć szkic'):message('Discard draft…','Odrzuć szkic…');
     if(dialog.open)restore.focus();
   }
   dialog.querySelector('[data-builder-recovery-meta]').textContent=message('Saved: ','Zapisano: ')+draft.updated_at;
   warning.hidden=!stale;
   warning.textContent=message('A newer version is available. Merge your edits before publishing.','Dostępna jest nowsza wersja. Połącz z nią swój szkic przed publikacją.');
   dialog.oncancel=event=>{event.preventDefault();if(confirming&&!busy)show(false)};
   restore.onclick=()=>{
     if(busy)return;
     if(confirming){show(false);return}
     if(stale){openDraftMerge(draft);return}
     blocks=recovered;themeEdits=edits;draftFingerprint=recoveredFingerprint;draftSavedAt=draft.updated_at||'';
     history=history.slice(0,historyIndex+1);history.push(recoveredFingerprint);historyIndex=history.length-1;
     inspectorUid=blocks[0]?.uid||'';closeDialog(dialog);render();setDirty(true,{remember:false,recover:false});updateHistoryControls();
     toast('success',message('Private draft restored.','Przywrócono prywatny szkic.'));
   };
   discard.onclick=async()=>{
     if(busy)return;
     if(!confirming){show(true);return}
     busy=true;restore.disabled=discard.disabled=true;error.hidden=true;
     const removed=await discardDraft(true);
     busy=false;restore.disabled=discard.disabled=false;
     if(!removed){error.textContent=message('Deletion was not confirmed. Retry or go back to your draft.','Nie potwierdzono usunięcia. Spróbuj ponownie lub wróć do szkicu.');error.hidden=false;restore.focus();return}
     closeDialog(dialog);toast('success',message('Private draft discarded.','Prywatny szkic został odrzucony.'));
   };
   show(false);openDialog(dialog);
 }
 function layoutMembers(block){return block.layout.group?blocks.filter(item=>item.layout.group===block.layout.group):[block]}
 function openLayout(block){layoutTarget=block;const dialog=root.querySelector('[data-builder-layout-dialog]'),form=dialog.querySelector('[data-builder-layout-form]'),layout=block.layout,members=layoutMembers(block);dialog.querySelector('#builder-layout-title').textContent=catalog[block.type].label+' layout';form.elements.container.value=layout.container;form.elements.gap.value=layout.gap;form.elements.align.value=layout.align;form.elements.wrap.checked=layout.wrap;for(const point of ['desktop','tablet','mobile']){form.elements[point+'_span'].value=String(layout[point].span);form.elements[point+'_order'].value=String(layout[point].order);form.elements[point+'_hidden'].checked=layout[point].hidden}const ungroup=dialog.querySelector('[data-builder-layout-ungroup]');ungroup.hidden=!layout.group;ungroup.innerHTML='<i data-lucide="ungroup"></i>Remove '+members.length+'-section group';openDialog(dialog)}
 function openShare(block){shareTarget=block;renameTarget=null;const dialog=root.querySelector('[data-builder-share-dialog]'),input=dialog.querySelector('[name=name]');dialog.querySelector('#builder-share-title').textContent=window.SenseCMSInterfaceText('Create shared section');dialog.querySelector('[type=submit]').lastChild.textContent=' Create shared section';input.value=blockTitle(block);openDialog(dialog);input.select()}
 function openRename(global){shareTarget=null;renameTarget=global;const dialog=root.querySelector('[data-builder-share-dialog]'),input=dialog.querySelector('[name=name]');dialog.querySelector('#builder-share-title').textContent=window.SenseCMSInterfaceText('Rename shared section');dialog.querySelector('[type=submit]').lastChild.textContent=' Save name';input.value=global.name;openDialog(dialog);input.select()}
 async function archiveGlobal(global){window.SenseCMSUI?.modal('Archive shared section','The section can only be archived when it is not linked to a page.',async()=>{try{await request('/content/builder/globals/'+global.id+'/archive',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf})});globals=globals.filter(item=>Number(item.id)!==Number(global.id));renderGlobals();toast('success',window.SenseCMSInterfaceText('Shared section archived.'))}catch(error){toast('error',error.message)}})}
 const revisionDialog=root.querySelector('[data-builder-history-dialog]');
 const restoreConfirm=root.querySelector('[data-restore-confirm]');
 restoreConfirm.querySelectorAll('button').forEach(button=>button.onclick=()=>restoreConfirm.close(button.value));
 revisionDialog.addEventListener('keydown',event=>{
   if(event.key!=='Tab')return;
   const stops=[...revisionDialog.querySelectorAll('button:enabled,input:enabled,a[href]')].filter(el=>el.getClientRects().length);
   const first=stops[0],last=stops.at(-1);
   if(first && (event.shiftKey?document.activeElement===first:document.activeElement===last)){
     event.preventDefault();
     (event.shiftKey?last:first).focus();
   }
 });
 const sectionDialog=root.querySelector('[data-revision-section-dialog]');
 let revisionBlocks=[],sectionRequest=0;
 function draftSectionPlan(raw) {
   if(!raw||!Object.hasOwn(catalog,raw.type))return {reason:'This section type is unavailable.'};
   const currentIndex=blocks.findIndex(block=>block.uid===raw.uid), current=blocks[currentIndex];
   if(lockedByOther(raw.uid))return {reason:'Another editor has locked this section.'};
   if(!current&&blocks.length>=60)return {reason:'The page already has 60 sections.'};
   if(catalog[raw.type].singleton&&blocks.some(block=>block.type===raw.type&&block.uid!==raw.uid))
     return {reason:'This section type is already on the page.'};
   const restored=normalize({...raw,global_section_id:null,global_version:0,version:0,global_name:''});
   // A single-section restore must not change the layout of neighbouring sections.
   if(current?.layout.group){
     for(const key of ['group','mode','container','gap','align','wrap'])
       restored.layout[key]=current.layout[key];
   }
   else if(restored.layout.group){restored.layout.group=null;restored.layout.mode='stack'}
   if(current&&JSON.stringify(restored)===JSON.stringify(current))return {reason:'This draft already matches the selected section.'};
   let index=currentIndex;
   if(!current){
     const oldIndex=revisionBlocks.indexOf(raw);
     const next=revisionBlocks.slice(oldIndex+1).find(item=>blocks.some(block=>block.uid===item.uid));
     const previous=revisionBlocks.slice(0,oldIndex).reverse().find(item=>blocks.some(block=>block.uid===item.uid));
     if(next)index=unitAt(blocks.findIndex(block=>block.uid===next.uid)).start;
     else if(previous)index=unitAt(blocks.findIndex(block=>block.uid===previous.uid)).end+1;
     else index=blocks.length;
   }
   const note=current?'Replace this section in the draft.':'Add this section at position '+(index+1)+' in the draft.';
   const shared=(current?.global_section_id||raw.global_section_id)?' Restored as a page-local section.':'';
   return {index,current,restored,note:note+shared+' You can undo this change.'};
 }
 function updateSectionChoice() {
   const selectedUid=sectionDialog.querySelector('[data-revision-section-choice]').value;
   const plan=draftSectionPlan(revisionBlocks.find(block=>block.uid===selectedUid));
   sectionDialog.querySelector('[data-revision-section-note]').textContent=plan.reason||plan.note;
   sectionDialog.querySelector('[data-revision-section-apply]').disabled=!!plan.reason||saving;
   return plan;
 }
 async function chooseRevisionSection(item) {
   if(!canEdit||!sectionDialog)return;
   const generation=++sectionRequest,choice=sectionDialog.querySelector('[data-revision-section-choice]');
   const note=sectionDialog.querySelector('[data-revision-section-note]');
   revisionBlocks=[];
   choice.replaceChildren();choice.disabled=true;
   sectionDialog.querySelector('[data-revision-section-version]').textContent='From version '+item.version;
   sectionDialog.querySelector('[data-revision-section-apply]').disabled=true;
   note.textContent=window.SenseCMSInterfaceText('Loading sections…');
   sectionDialog.showModal();
   try{
     const response=await request('/content/builder/'+payload.page.id+'/revisions/'+item.id+'/sections');
     if(generation!==sectionRequest||!sectionDialog.open)return;
     revisionBlocks=response.data.blocks.filter(block=>block&&block.uid);
     for(const raw of revisionBlocks) {
       const label=Object.hasOwn(catalog,raw.type)?blockTitle(normalize(raw)):String(raw.type);
       choice.add(new Option((choice.length+1)+'. '+label,raw.uid));
     }
     if(!choice.length){note.textContent=window.SenseCMSInterfaceText('This revision contains no sections.');return}
     choice.disabled=false;
     updateSectionChoice();
     choice.focus();
   }catch(error){if(generation===sectionRequest&&sectionDialog.open)note.textContent=error.message}
 }
 if(sectionDialog) {
   sectionDialog.addEventListener('change',updateSectionChoice);
   sectionDialog.querySelector('[data-revision-section-apply]').onclick=()=>{
     const plan=updateSectionChoice();
     if(!canEdit||saving||plan.reason)return;
     blocks.splice(plan.index,plan.current?1:0,plan.restored);
     inspectorUid=plan.restored.uid;
     setDirty();
     sectionDialog.close();
     revisionDialog.close();
     render();
     switchLibraryTab('inspector');
     enterSectionEditor();
     revealTool(inspector);
     toast('success',window.SenseCMSInterfaceText('Section restored to the draft. Review it before saving.'));
   };
 }
 function renderRevisions(){const target=root.querySelector('[data-builder-revisions]'),compare=root.querySelector('[data-builder-compare]'),hint=root.querySelector('[data-builder-compare-hint]');revisionSelection=new Set([...revisionSelection].filter(id=>revisions.some(item=>Number(item.id)===id)));if(!revisions.length){target.innerHTML=("<div class=\"sensecms-builder-revisions-empty\">"+window.SenseCMSInterfaceHtml("The first revision will be created when you save this page.")+"</div>");compare.disabled=true;return}target.replaceChildren(...revisions.map(item=>{const row=document.createElement('article');row.className='sensecms-builder-revision'+(revisionSelection.has(Number(item.id))?' is-selected':'');const choice=document.createElement('input');choice.type='checkbox';choice.dataset.revisionId=item.id;choice.checked=revisionSelection.has(Number(item.id));choice.setAttribute('aria-label','Select version '+item.version+' for comparison');choice.onchange=()=>{const id=Number(item.id);if(choice.checked){if(revisionSelection.size>=2){choice.checked=false;toast('warning',window.SenseCMSInterfaceText('Choose exactly two revisions to compare.'));return}revisionSelection.add(id)}else revisionSelection.delete(id);renderRevisions();target.querySelector('[data-revision-id="'+item.id+'"]')?.focus()};const tile=document.createElement('i');tile.append(icon('history'));const copy=document.createElement('div'),name=document.createElement('strong'),meta=document.createElement('span'),restore=document.createElement('button');name.textContent='Version '+item.version+(Number(item.version)===version?' · current':'');meta.textContent=(item.author||'System')+' · '+new Date(String(item.created_at).replace(' ','T')).toLocaleString();copy.append(name,meta);restore.type='button';restore.textContent=Number(item.version)===version?window.SenseCMSInterfaceText('Current version'):window.SenseCMSInterfaceText('Restore full page');restore.disabled=!canEdit||dirty||Number(item.version)===version;restore.onclick=()=>restoreRevision(item);const actions=document.createElement('div');actions.className='sensecms-revision-actions';if(canEdit){const section=document.createElement('button');section.type='button';section.textContent=window.SenseCMSInterfaceText('Restore one section');section.onclick=()=>chooseRevisionSection(item);actions.append(section)}actions.append(restore);row.append(choice,tile,copy,actions);return row}));compare.disabled=revisionSelection.size!==2;hint.textContent=dirty&&canEdit?window.SenseCMSInterfaceText('One section can be restored to your draft. Save or discard edits before restoring the full page.'):revisionSelection.size===2?window.SenseCMSInterfaceText('Ready to compare selected versions'):revisionSelection.size===1?window.SenseCMSInterfaceText('Select one more revision'):window.SenseCMSInterfaceText('Select two revisions to compare');window.lucide?.createIcons()}
 function renderRevisionComparison(comparison){const target=root.querySelector('[data-builder-revision-comparison]'),diff=comparison.diff||{},summary=diff.summary||{},head=document.createElement('header');target.hidden=false;head.innerHTML=("<div><small>"+window.SenseCMSInterfaceHtml("VERSION COMPARISON")+"</small><strong>Version ")+comparison.from.version+' → '+comparison.to.version+'</strong><span>'+comparison.from.author+' to '+comparison.to.author+("</span></div><button type=\"button\" aria-label=\""+window.SenseCMSInterfaceHtml("Close comparison")+"\"><i data-lucide=\"x\"></i></button>");head.querySelector('button').onclick=()=>{target.hidden=true};const stats=document.createElement('div');stats.className='sensecms-builder-diff-stats';[['added','Added'],['removed','Removed'],['changed','Changed'],['moved','Moved']].forEach(([key,label])=>{const item=document.createElement('span');item.innerHTML='<b>'+Number(summary[key]||0)+'</b><small>'+label+'</small>';stats.append(item)});const changes=document.createElement('div');changes.className='sensecms-builder-diff-list';if(diff.empty)changes.innerHTML=("<div class=\"sensecms-builder-revisions-empty\">"+window.SenseCMSInterfaceHtml("These revisions contain the same section document.")+"</div>");else(diff.changes||[]).forEach(change=>{const row=document.createElement('article');row.className='is-'+change.kind;const tile=document.createElement('i');tile.append(icon(change.kind==='added'?'plus':change.kind==='removed'?'minus':change.kind==='moved'?'move-vertical':'pencil-line'));const copy=document.createElement('div'),name=document.createElement('strong'),meta=document.createElement('span');name.textContent=change.label;meta.textContent=(change.details||[]).join(' · ');copy.append(name,meta);row.append(tile,copy);changes.append(row)});target.replaceChildren(head,stats,changes);window.lucide?.createIcons()}
 async function compareRevisions(){if(revisionSelection.size!==2)return;const ids=[...revisionSelection];try{const result=await request('/content/builder/'+payload.page.id+'/revisions/compare',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf,from:ids[0],to:ids[1]})});renderRevisionComparison(result.data)}catch(error){toast('error',error.message||window.SenseCMSInterfaceText('The revisions could not be compared.'))}}
 async function restoreRevision(item){if(!canEdit)return;if(dirty){toast('warning',window.SenseCMSInterfaceText('Save or discard current changes before restoring a revision.'));return}const confirm=root.querySelector('[data-restore-confirm]');
   confirm.querySelector('h3').textContent='Restore version '+item.version+'?';
   confirm.returnValue='';
   const closed=new Promise(resolve=>confirm.addEventListener('close',resolve,{once:true}));
   confirm.showModal();
   await closed;
   if(confirm.returnValue!=='restore')return;
   try{const result=await request('/content/builder/'+payload.page.id+'/revisions/'+item.id+'/restore',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf,version})});version=Number(result.data.version);revisions=result.data.revisions||[];blocks=(result.data.document?.blocks||[]).filter(block=>catalog[block.type]).map(normalize);themeEdits=[];savedFingerprint=fingerprint();draftFingerprint='';dirty=false;history=[savedFingerprint];historyIndex=0;inspectorUid=blocks[0]?.uid||'';render();renderRevisions();updateHistoryControls();updateState();closeDialog(root.querySelector('[data-builder-history-dialog]'));toast('success',result.message)}catch(error){toast('error',error.message)}}
 function clearDropMarks() {
   root.querySelectorAll('[data-drop-side]').forEach(row=>delete row.dataset.dropSide);
 }
 function enableDrag(handle,uid,row) {
   handle.draggable=!handle.disabled;
   handle.addEventListener('dragstart',event=>{
     if (!canEdit||handle.disabled||lockedByOther(uid)) {event.preventDefault();return;}
     dragUid=uid;
     row.classList.add('is-dragging');
     event.dataTransfer.effectAllowed='move';
     event.dataTransfer.setData('text/plain',uid);
   });
   handle.addEventListener('dragend',()=>{
     dragUid='';
     row.classList.remove('is-dragging');
     clearDropMarks();
   });
 }
 for (const surface of [list,root.querySelector('[data-builder-outline]')]) {
   function dropAt(event) {
     const row=event.target.closest('article[data-uid]');
     if (!dragUid||!canEdit||!row||!surface.contains(row)) return {};
     const bounds=row.getBoundingClientRect();
     const side=event.clientY<bounds.top+bounds.height/2?'before':'after';
     return {...plannedMove(dragUid,row.dataset.uid,side),side};
   }
   surface.addEventListener('dragover',event=>{
     if (!dragUid) return;
     clearDropMarks();
     const plan=dropAt(event);
     if (!plan.order) return;
     event.preventDefault();
     event.dataTransfer.dropEffect='move';
     surface.querySelector('[data-uid="'+CSS.escape(plan.edge)+'"]').dataset.dropSide=plan.side;
   });
   surface.addEventListener('dragleave',event=>{
     if (!surface.contains(event.relatedTarget)) clearDropMarks();
   });
   surface.addEventListener('drop',event=>{
     if (!dragUid) return;
     event.preventDefault();
     const plan=dropAt(event);
     dragUid='';
     clearDropMarks();
     if (!plan.order) return;
     blocks=plan.order;
     setDirty();
     render();
   });
 }
 root.querySelector('[data-builder-copy-selected]').onclick=()=>copyBlocks(blocks.filter(block=>selected.has(block.uid)));root.querySelector('[data-builder-paste]').onclick=pasteClipboard;root.querySelector('[data-builder-search]').addEventListener('input',()=>renderLibrary());root.querySelector('[data-builder-pattern-search]').addEventListener('input',()=>renderPatterns());root.querySelector('[data-builder-global-search]').addEventListener('input',renderGlobals);root.querySelectorAll('[data-library-tab]').forEach(tab=>tab.onclick=()=>switchLibraryTab(tab.dataset.libraryTab));const aiForm=root.querySelector('[data-builder-ai-form]');if(aiForm){aiForm.addEventListener('submit',event=>{event.preventDefault();runAiProposal(aiForm)});aiForm.elements.action.addEventListener('change',updateAiScopes);aiForm.elements.scope.addEventListener('change',updateAiTarget);aiForm.elements.locale.addEventListener('change',event=>{activeLocale=String(event.target.value);localeNavigation();render()})}
 const pageSelect=root.querySelector('[data-builder-page]');
 pageSelect.onchange=()=>{
   const id=pageSelect.value;
   pageSelect.selectedIndex=[...pageSelect.options].findIndex(option=>option.defaultSelected);
   const go=()=>location.assign('/content/builder?document='+encodeURIComponent(id)+'&lang='+encodeURIComponent(activeLocale));
   if(!needsLeaveWarning())go();else if(window.SenseCMSUnsavedGuard?.confirmLeave)window.SenseCMSUnsavedGuard.confirmLeave(go);
   else window.SenseCMSUI?.modal('Leave unsaved changes?','Your recent changes have not been saved.',go);
 };
 undoButton.onclick=()=>applyHistory(historyIndex-1);redoButton.onclick=()=>applyHistory(historyIndex+1);root.querySelector('[data-builder-preview]').onclick=()=>{
   delete root.dataset.sectionEditing;
   switchBuilderView('live');
   revealTool(root.querySelector('.sensecms-builder-canvas-head'));
 };root.querySelector('[data-builder-quality-open]').onclick=()=>{
   switchLibraryTab('quality');
   revealTool(root.querySelector('[data-library-panel="quality"]'));
 };root.querySelectorAll('[data-builder-view]').forEach(control=>control.onclick=()=>switchBuilderView(control.dataset.builderView));root.querySelectorAll('[data-live-device]').forEach(control=>control.onclick=()=>{root.querySelectorAll('[data-live-device]').forEach(item=>item.classList.toggle('is-active',item===control));root.querySelector('[data-live-stage]').dataset.liveStage=control.dataset.liveDevice});root.querySelectorAll('[data-preview-device]').forEach(control=>control.onclick=()=>{root.querySelectorAll('[data-preview-device]').forEach(item=>item.classList.toggle('is-active',item===control));root.querySelector('[data-preview-stage]').dataset.previewStage=control.dataset.previewDevice});addEventListener('message',event=>{if(event.origin!==location.origin||event.source!==liveFrame.contentWindow)return;const data=event.data||{};if(data.type==='sensecms-builder-insert')showInserter(String(data.uid||''));else if(data.type==='sensecms-builder-select')selectCanvasBlock(String(data.uid||''));else if(data.type==='sensecms-builder-action')runLiveAction(String(data.uid||''),String(data.action||''));else if(data.type==='sensecms-builder-ready'){setLiveStatus('Live Canvas ready');highlightLive()}},{signal:lifecycle.signal});root.querySelector('[data-builder-history]').onclick=()=>{renderRevisions();openDialog(root.querySelector('[data-builder-history-dialog]'))};root.querySelector('[data-builder-compare]').onclick=compareRevisions;root.querySelectorAll('[data-dialog-close]').forEach(control=>control.onclick=()=>closeDialog(control.closest('.sensecms-builder-modal,dialog')));root.querySelectorAll('.sensecms-builder-modal:not([data-builder-recovery-dialog])').forEach(dialog=>dialog.addEventListener('mousedown',event=>{if(event.target===dialog)closeDialog(dialog)}));
 root.querySelector('[data-builder-share-form]').addEventListener('submit',async event=>{event.preventDefault();if(!shareTarget&&!renameTarget)return;const form=event.currentTarget,submit=form.querySelector('[type=submit]'),name=new FormData(form).get('name');submit.disabled=true;try{if(renameTarget){const result=await request('/content/builder/globals/'+renameTarget.id+'/rename',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf,name})});globals=result.data.globals||globals;blocks.filter(block=>block.global_section_id===Number(renameTarget.id)).forEach(block=>block.global_name=String(name));closeDialog(root.querySelector('[data-builder-share-dialog]'));render();toast('success',result.message)}else{const result=await request('/content/builder/globals',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf,name,block:shareTarget})});globals.unshift(result.data);shareTarget.global_section_id=Number(result.data.id);shareTarget.global_name=result.data.name;shareTarget.global_version=Number(result.data.version||0);closeDialog(root.querySelector('[data-builder-share-dialog]'));setDirty();render();toast('success',result.message)}}catch(error){toast('error',error.message)}finally{submit.disabled=false}});
 const layoutForm=root.querySelector('[data-builder-layout-form]');layoutForm.addEventListener('submit',event=>{event.preventDefault();if(!layoutTarget)return;const data=new FormData(layoutForm),members=layoutMembers(layoutTarget);for(const block of members){block.layout.container=String(data.get('container'));block.layout.gap=String(data.get('gap'));block.layout.align=String(data.get('align'));block.layout.wrap=data.has('wrap')}for(const point of ['desktop','tablet','mobile'])layoutTarget.layout[point]={span:Math.max(1,Math.min(12,Number(data.get(point+'_span'))||12)),order:Math.max(-20,Math.min(20,Number(data.get(point+'_order'))||0)),hidden:data.has(point+'_hidden')};closeDialog(root.querySelector('[data-builder-layout-dialog]'));setDirty();render();toast('success',window.SenseCMSInterfaceText('Responsive layout updated.'))});root.querySelector('[data-builder-layout-ungroup]').onclick=()=>{if(!layoutTarget?.layout.group)return;const members=layoutMembers(layoutTarget);members.forEach(block=>block.layout=defaultLayout());closeDialog(root.querySelector('[data-builder-layout-dialog]'));setDirty();render();toast('success',window.SenseCMSInterfaceText('Layout group removed. Sections are independent again.'))};
 let reviewing=false;
 function confirmPublication(pages){
   const dialog=root.querySelector('[data-publication-dialog]'),cancel=dialog.querySelector('[data-publication-cancel]'),confirm=dialog.querySelector('[data-publication-confirm]');
   dialog.querySelector('h3').textContent=message('Review changes','Sprawdź zmiany');
   dialog.querySelector('[data-publication-copy]').textContent=message('These pages and languages will change. Private and scheduled pages keep their visibility.','Zmiany obejmą poniższe strony i języki. Strony prywatne i zaplanowane zachowają swoją widoczność.');
   const rows=pages.map(p=>{
     const row=document.createElement('li'),title=document.createElement('strong'),note=document.createElement('span');
     title.textContent=p.title+' · '+p.languages.map(l=>l.toUpperCase()).join(', ');
     const reasons=[];
     if(p.reasons.includes('banner'))reasons.push(message('Shared AI Chatbot banner','Wspólny baner AI Chatbot'));
     if(p.reasons.includes('shared'))reasons.push(message('Shared section','Sekcja współdzielona'));
     if(p.visibility!=='public'||!['published','scheduled'].includes(p.status))reasons.push(message('Not public','Strona niepubliczna'));
     if(p.status==='scheduled')reasons.push(message('Scheduled page','Publikacja zaplanowana'));
     note.textContent=[p.path,p.facility,...reasons].filter(Boolean).join(' · ');row.append(title,note);return row;
   });
   if(!rows.length){const row=document.createElement('li');row.textContent=message('No visible content differences.','Brak różnic w treści.');rows.push(row)}
   dialog.querySelector('[data-publication-pages]').replaceChildren(...rows);
   cancel.textContent=message('Back to draft','Wróć do szkicu');confirm.textContent=pages.some(p=>p.visibility==='public'&&['published','scheduled'].includes(p.status))?message('Publish changes','Opublikuj zmiany'):message('Save changes','Zapisz zmiany');
   return new Promise(resolve=>{dialog.returnValue='';cancel.onclick=()=>dialog.close();confirm.onclick=()=>dialog.close('confirm');dialog.addEventListener('close',()=>resolve(dialog.returnValue==='confirm'),{once:true});dialog.showModal();cancel.focus()});
 }
 saveButton.addEventListener('click', async () => {
   if (saving || reviewing || !dirty) return;
   if(mergeNeeded){openDraftMerge();return}
   const stamp=fingerprint(),submitted=JSON.parse(stamp),endpoint='/content/builder/'+payload.page.id;
   let reviewToken;
   reviewing=true;
   try {
     await flushDraft({quiet:true});
     const review=await request(endpoint+'/publication-review',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:payload.csrf,version,...submitted})});
     if(!await confirmPublication(review.data.pages))return;
     if(stamp!==fingerprint())throw Error(message('The draft changed. Review it again.','Szkic się zmienił. Sprawdź go ponownie.'));
     reviewToken=review.data.token;
   } catch(error){if(error.status===409){mergeNeeded=true;updateState();openDraftMerge()}else toast('error',error.message);return} finally{reviewing=false}
   clearTimeout(draftTimer);clearTimeout(draftMaxTimer);clearTimeout(liveTimer);draftMaxTimer=0;lastTypingField=null;
   saving = true;
   saveError = false;
   updateState();
   try {
     const result = await request('/content/builder/' + payload.page.id, {
       method: 'POST', headers: {'Content-Type':'application/json'},
       body: JSON.stringify({csrf:payload.csrf, version, ...submitted,reviewToken})
     });
     version = Number(result.data.version);
     if(result.data.publication?.status){
       Object.assign(payload.page,result.data.publication);
       openedAt=performance.now();clearTimeout(visibilityTimer);visibilityTimer=0;
     }
     revisions = result.data.revisions || revisions;
     globals = result.data.globals || globals;
     // Only advance versions for shared sections included in this revision.
     const linked = new Set(submitted.blocks.map(block => block.global_section_id).filter(Boolean));
     const versions = new Map(globals.filter(item => linked.has(Number(item.id))).map(item => [Number(item.id), Number(item.version)]));
     const syncVersions = items => {
       for (const block of items) if (versions.has(block.global_section_id)) block.global_version = versions.get(block.global_section_id);
       return items;
     };
     themeEdits=[];
     savedFingerprint = JSON.stringify({blocks:syncVersions(submitted.blocks),themeEdits:[]});
     syncVersions(blocks);
     if(submitted.themeEdits.length){history=[savedFingerprint];historyIndex=0;if(fingerprint()!==savedFingerprint){history.push(fingerprint());historyIndex++}updateHistoryControls()}
     else if(versions.size)history=history.map(entry=>{const doc=JSON.parse(entry);syncVersions(doc.blocks);return JSON.stringify(doc)});
     dirty = fingerprint() !== savedFingerprint;
     draftFingerprint = '';
     draftSavedAt = '';
     draftError = false;
     if (!dirty && liveMode) refreshLivePreview();
     publicationUnconfirmed=false;
     const published=publicationState()==='live';
     toast('success',published?(dirty?message('Changes published. Newer edits are still in your draft.','Zmiany opublikowane. Nowsze zmiany pozostały w szkicu.'):message('Changes published.','Zmiany opublikowane.')):dirty?message('Page saved. Newer edits are still in your draft.','Strona zapisana. Nowsze zmiany pozostały w szkicu.'):result.message);
   } catch (error) {
     saveError = true;
     if(error.status===409)mergeNeeded=true;
     if(!error.status||error.status===409||error.status>=500)publicationUnconfirmed=true;
     toast('error', error.message || window.SenseCMSInterfaceText('The page could not be saved.'));
   } finally {
     saving = false;
     if (dirty) scheduleDraft();
     updateState();
   }
 });
 document.addEventListener('keydown',event=>{if(!canEdit||!(event.ctrlKey||event.metaKey)||event.altKey)return;const target=event.target;if(target instanceof HTMLElement&&(target.matches('input,textarea,select')||target.isContentEditable))return;const key=event.key.toLowerCase();if(key==='z'){event.preventDefault();applyHistory(event.shiftKey?historyIndex+1:historyIndex-1)}else if(key==='y'){event.preventDefault();applyHistory(historyIndex+1)}},{signal:lifecycle.signal});addEventListener('pagehide',()=>{if(dirty&&fingerprint()!==draftFingerprint)flushDraft({keepalive:true,quiet:true});if(lockedUid)fetch('/content/builder/'+payload.page.id+'/sections/'+encodeURIComponent(lockedUid)+'/unlock',{method:'POST',keepalive:true,headers:{'Content-Type':'application/json','X-SenseCMS-Request':'1',Accept:'application/json'},body:JSON.stringify({csrf:payload.csrf})}).catch(()=>{})},{signal:lifecycle.signal});addEventListener('beforeunload',event=>{if(!needsLeaveWarning()||window.SenseCMSUnsavedGuard?.isBypassing())return;event.preventDefault();event.returnValue=''},{signal:lifecycle.signal});savedFingerprint=fingerprint();qualityFingerprint=savedFingerprint;history=[savedFingerprint];historyIndex=0;localeNavigation();setDirty(false,{remember:false,recover:false});updateHistoryControls();render();switchBuilderView('live');offerRecovery();syncCollaboration(true);
};
document.readyState==='loading'?document.addEventListener('DOMContentLoaded',boot,{once:true}):boot();document.addEventListener('sensecms:navigated',boot);
})();
