window.SenseCMSInterfaceText ??= source => source;
(()=>{const enhance=input=>{if(input.dataset.sensecmsPicker||input.matches('[hidden],[data-avatar-input],.ql-image'))return;input.dataset.sensecmsPicker='1';const zone=input.closest('.sensecms-media-drop,.sensecms-knowledge-upload');
if(zone){const label=zone.querySelector('strong,b'),initial=label.textContent;
 const update=()=>{label.textContent=input.files.length>1?input.files.length+' files selected':input.files[0]?.name||initial};
 input.addEventListener('change',update);input.form?.addEventListener('reset',()=>queueMicrotask(update));return}
input.classList.add('sensecms-native-file');const picker=document.createElement('div');picker.className='sensecms-file-picker'+(input.disabled?' is-disabled':'');const button=document.createElement('button'),name=document.createElement('span');button.type='button';if(input.getAttribute('aria-label'))button.setAttribute('aria-label',input.getAttribute('aria-label'));button.textContent=input.disabled?window.SenseCMSInterfaceText('Unavailable'):window.SenseCMSInterfaceText('Select file');name.textContent=input.disabled?window.SenseCMSInterfaceText('This system file is locked.'):(input.dataset.sensecmsHint||window.SenseCMSInterfaceText('No file selected'));button.disabled=input.disabled;button.addEventListener('click',()=>input.click());input.addEventListener('change',()=>{name.textContent=input.files?.[0]?.name||input.dataset.sensecmsHint||window.SenseCMSInterfaceText('No file selected')});picker.append(button,name);input.insertAdjacentElement('afterend',picker)};const scan=root=>root.querySelectorAll?.('input[type="file"]').forEach(enhance);const boot=()=>{scan(document);new MutationObserver(records=>records.forEach(record=>record.addedNodes.forEach(node=>{if(node.nodeType===1){if(node.matches?.('input[type="file"]'))enhance(node);scan(node)}}))).observe(document.body,{childList:true,subtree:true})};document.readyState==='loading'?document.addEventListener('DOMContentLoaded',boot,{once:true}):boot()})();

/* Keep the native select as the form control; share one searchable list across console views. */
(()=>{
  if(!document.body.classList.contains('sensecms-console')||!HTMLElement.prototype.showPopover)return;
  const field=target=>{
    const select=target.closest?.('select');
    return select&&!select.multiple&&select.size<=1&&!select.matches(':disabled')&&!select.closest('.ql-toolbar,[contenteditable=true]')?select:null;
  };
  const popup=document.createElement('div');
  popup.className='sensecms-select-popup';popup.popover='manual';
  popup.innerHTML='<input type="search" role="combobox" aria-expanded="true" aria-autocomplete="list" aria-controls="sensecms-select-options" autocomplete="off" spellcheck="false"><div id="sensecms-select-options" role="listbox"></div><div role="status" hidden></div>';
  const [input,list,empty]=popup.children,fold=text=>text.toLowerCase().normalize('NFD').replace(/\p{M}/gu,'').replace(/ł/g,'l');
  let select=null,rows=[],active=null,choices=[];
  function close(restore=false){
    if(!select)return;
    const origin=select;select=null;popup.hidePopover();popup.remove();
    rows=[];choices=[];active=null;
    if(restore&&origin.isConnected)origin.focus({preventScroll:true});
  }
  function place(){
    if(!select)return;
    if(!select.isConnected||!select.getClientRects().length){close();return}
    const rect=select.getBoundingClientRect(),view=window.visualViewport,left=view?.offsetLeft||0,top=view?.offsetTop||0,w=view?.width||innerWidth,h=view?.height||innerHeight;
    const below=top+h-rect.bottom-8,above=rect.top-top-8,up=below<160&&above>below;
    popup.style.width=Math.min(Math.max(rect.width,260),w-16)+'px';
    popup.style.maxHeight=Math.min(340,Math.max(74,up?above:below))+'px';
    popup.style.left=Math.max(left+8,Math.min(rect.left,left+w-popup.offsetWidth-8))+'px';
    popup.style.top=Math.max(top+4,up?rect.top-popup.offsetHeight-4:rect.bottom+4)+'px';
  }
  function highlight(row){
    active?.node.classList.remove('is-active');active=row;
    if(!row){input.removeAttribute('aria-activedescendant');return}
    row.node.classList.add('is-active');input.setAttribute('aria-activedescendant',row.node.id);
    const r=row.node.getBoundingClientRect(),l=list.getBoundingClientRect();
    if(r.top<l.top)list.scrollTop+=r.top-l.top;else if(r.bottom>l.bottom)list.scrollTop+=r.bottom-l.bottom;
  }
  function filter(){
    const words=fold(input.value).trim().split(/\s+/);let count=0;
    choices=rows.filter(row=>{
      row.node.hidden=!words.every(w=>row.name.includes(w));
      if(!row.node.hidden)count++;
      return !row.node.hidden&&!row.disabled;
    });
    for(const group of list.querySelectorAll('[role=group]'))group.hidden=!group.querySelector('[role=option]:not([hidden])');
    empty.hidden=count>0;place();highlight((!input.value&&choices.find(row=>row.option.selected))||choices[0]);
  }
  function open(origin,query=''){
    close();select=origin;
    const pl=document.documentElement.lang.toLowerCase().startsWith('pl'),hint=pl?'Szukaj…':'Search…';
    const label=origin.getAttribute('aria-label')||origin.labels?.[0]?.innerText.split('\n')[0]?.trim()||hint;
    input.placeholder=hint;input.setAttribute('aria-label',pl?'Szukaj: '+label:'Search: '+label);
    list.setAttribute('aria-label',label);empty.textContent=pl?'Brak wyników':window.SenseCMSInterfaceText('No matches');
    list.replaceChildren();const groups=new Map();
    for(const option of origin.options){
      const group=option.closest('optgroup');
      if(option.hidden||group?.hidden||getComputedStyle(option).display==='none')continue;
      let parent=list;
      if(group){
        if(!groups.has(group)){
          const box=document.createElement('div'),heading=document.createElement('div');
          box.setAttribute('role','group');box.setAttribute('aria-label',group.label);
          heading.className='sensecms-select-group';heading.textContent=group.label;box.append(heading);list.append(box);groups.set(group,box);
        }
        parent=groups.get(group);
      }
      const node=document.createElement('div'),disabled=option.disabled||!!group?.disabled;
      node.id='sensecms-select-option-'+option.index;node.setAttribute('role','option');node.setAttribute('aria-selected',String(option.selected));
      if(disabled)node.setAttribute('aria-disabled','true');
      node.textContent=option.label;node.dataset.row=rows.length;parent.append(node);
      rows.push({option,node,disabled,name:fold((group?.label||'')+' '+option.label)});
    }
    (origin.closest('dialog[open]')||document.body).append(popup);popup.showPopover();
    input.value=query;filter();input.focus({preventScroll:true});
  }
  function choose(row){
    if(!row||row.disabled||row.node.hidden||!select)return;
    const origin=select,option=row.option,changed=!option.selected;
    close(true);
    if(!changed||!origin.isConnected||origin.matches(':disabled')||option.closest('select')!==origin||option.matches(':disabled'))return;
    origin.selectedIndex=option.index;
    for(const name of ['input','change'])origin.dispatchEvent(new Event(name,{bubbles:true}));
  }
  document.addEventListener('pointerdown',event=>{
    const origin=field(event.target);
    if(origin&&event.button===0){event.preventDefault();return}
    if(!popup.contains(event.target))close();
  },true);
  document.addEventListener('mousedown',event=>{if(field(event.target)&&event.button===0)event.preventDefault()},true);
  document.addEventListener('click',event=>{
    const origin=field(event.target);if(!origin)return;
    event.preventDefault();if(origin===select)close(true);else open(origin);
  },true);
  document.addEventListener('keydown',event=>{
    const origin=field(event.target);if(!origin||event.isComposing||event.ctrlKey||event.metaKey)return;
    const key=event.key;
    if(['Enter',' ','ArrowDown','ArrowUp','F4'].includes(key)||(!event.altKey&&key.length===1)){
      event.preventDefault();open(origin,key.length===1&&key!==' '?key:'');
    }
  },true);
  input.oninput=event=>{event.stopPropagation();filter()};
  input.onchange=event=>event.stopPropagation();
  input.onkeydown=event=>{
    if(event.isComposing)return;
    const key=event.key;
    if(key==='Tab'){close(true);return}
    if(!['ArrowDown','ArrowUp','Enter','Escape'].includes(key))return;
    event.preventDefault();event.stopPropagation();
    if(key==='Escape')close(true);else if(key==='Enter')choose(active);
    else highlight(choices[Math.max(0,Math.min(choices.length-1,choices.indexOf(active)+(key==='ArrowDown'?1:-1)))]);
  };
  list.onmousedown=event=>event.preventDefault();
  list.onclick=event=>{const node=event.target.closest('[role=option]');if(node)choose(rows[Number(node.dataset.row)])};
  document.addEventListener('focusin',event=>{if(select&&!popup.contains(event.target)&&event.target!==select)close()},true);
  document.addEventListener('scroll',event=>{if(select&&!popup.contains(event.target))place()},{capture:true,passive:true});
  window.addEventListener('resize',place);window.visualViewport?.addEventListener('resize',place);
  document.addEventListener('sensecms:navigated',()=>close());document.addEventListener('close',event=>{if(select&&event.target.contains(select))close()},true);
})();
