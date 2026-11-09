(()=>{'use strict';const ui=s=>window.SenseCMSInterfaceText?.(s)??s;let bypass=false,timer=0,lastFocus=null,action=null;const dirty=()=>!!document.querySelector('[data-content-form][data-content-state="dirty"],[data-content-form][data-content-state="saving"],[data-builder-unsaved],[data-survey-save-state].is-dirty'),isBypassing=()=>bypass,allow=()=>{bypass=true;clearTimeout(timer);timer=setTimeout(()=>bypass=false,15e3)},create=()=>{let layer=document.querySelector('[data-unsaved-modal]');if(layer)return layer;layer=document.createElement('dialog');layer.setAttribute('role','alertdialog');layer.setAttribute('aria-labelledby','sensecms-unsaved-title');layer.setAttribute('aria-describedby','sensecms-unsaved-copy');layer.className='sensecms-unsaved-modal';layer.dataset.unsavedModal='1';layer.innerHTML='<section><button type="button" class="sensecms-unsaved-close" data-unsaved-cancel aria-label="Close"><i data-lucide="x"></i></button><div class="sensecms-unsaved-icon"><i data-lucide="triangle-alert"></i></div><div class="sensecms-unsaved-copy"><small>UNSAVED CHANGES</small><h2 id="sensecms-unsaved-title">Leave this page?</h2><p id="sensecms-unsaved-copy">Your recent changes have not been saved. Leave now or stay here to finish editing.</p><div><i data-lucide="shield-alert"></i><span>Stay on this page if you want to review or save your work.</span></div></div><footer><button type="button" class="btn bg-default-150" data-unsaved-cancel><i data-lucide="arrow-left"></i>Stay here</button><button type="button" class="btn bg-danger text-white" data-unsaved-confirm>Leave without saving<i data-lucide="log-out"></i></button></footer></section>';for(const el of layer.querySelectorAll('small,h2,p,span,button')){for(const node of el.childNodes)if(node.nodeType===Node.TEXT_NODE&&node.textContent.trim())node.textContent=ui(node.textContent);if(el.hasAttribute('aria-label'))el.setAttribute('aria-label',ui(el.getAttribute('aria-label')))}document.body.append(layer);const close=()=>{layer.close();document.documentElement.classList.remove('sensecms-unsaved-open');action=null;lastFocus?.focus?.()};layer.querySelectorAll('[data-unsaved-cancel]').forEach(button=>button.addEventListener('click',close));layer.querySelector('[data-unsaved-confirm]').addEventListener('click',()=>{const next=action;layer.close();document.documentElement.classList.remove('sensecms-unsaved-open');action=null;if(next){allow();next()}});layer.addEventListener('click',event=>{if(event.target===layer)close()});layer.addEventListener('cancel', event => { event.preventDefault(); close(); });
layer.addEventListener('keydown', event => {
    if (event.key !== 'Tab') return;
    const first = layer.querySelector('[data-unsaved-cancel]'), last = layer.querySelector('[data-unsaved-confirm]');
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
});return layer},open=(next,signOut=false)=>{const layer=create(),confirm=layer.querySelector('[data-unsaved-confirm]');lastFocus=document.activeElement;action=next;confirm.replaceChildren(document.createTextNode(ui(signOut?'Sign out anyway':'Leave without saving')));const icon=document.createElement('i');icon.dataset.lucide=signOut?'log-out':'arrow-right';confirm.append(icon);layer.querySelector('#sensecms-unsaved-title').textContent=ui('Leave this page?');layer.querySelector('#sensecms-unsaved-copy').textContent=ui('Your recent changes have not been saved. Leave now or stay here to finish editing.');if(!layer.open)layer.showModal();document.documentElement.classList.add('sensecms-unsaved-open');window.lucide?.createIcons();layer.querySelector('footer [data-unsaved-cancel]').focus()};document.addEventListener('click',event=>{if(bypass||!dirty()||event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;const link=event.target.closest?.('a[href]');if(!link||(link.target&&link.target.toLowerCase()!=='_self')||link.hasAttribute('download'))return;const href=link.getAttribute('href')||'';if(!href||href.startsWith('#')||/^(?:javascript|mailto|tel):/i.test(href))return;const url=new URL(link.href,location.href);event.preventDefault();event.stopImmediatePropagation();open(()=>location.assign(url.href))},true);document.addEventListener('submit',event=>{if(bypass||!dirty())return;const form=event.target;if(!(form instanceof HTMLFormElement)||new URL(form.action||location.href,location.href).pathname!=='/logout')return;const submitter=event.submitter;event.preventDefault();event.stopImmediatePropagation();open(()=>form.requestSubmit(submitter instanceof HTMLElement?submitter:undefined),true)},true);addEventListener('beforeunload',event=>{if(!bypass&&dirty()){event.preventDefault();event.returnValue=''}});// A successful section save reloads the workspace and would discard sibling drafts.
window.addEventListener('submit', event => {
    const form = event.target;
    if (bypass) return;
    if (form.matches('[data-discard-draft-action]') && dirty()) {
        event.preventDefault();
        event.stopImmediatePropagation();
        open(() => { try { form.requestSubmit(event.submitter || undefined); } finally { bypass = false; } });
        return;
    }
    if (!form.matches('form[data-content-form]')) return;
    const sibling = [...document.querySelectorAll('form[data-content-form][data-content-state="dirty"]')].some(item => item !== form);
    if (!sibling) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const submitter = event.submitter;
    open(() => {
        try { form.requestSubmit(submitter || undefined); }
        finally { bypass = false; }
    });
    const dialog = document.querySelector('[data-unsaved-modal]');
    dialog.querySelector('#sensecms-unsaved-title').textContent = ui('Save only this section?');
    dialog.querySelector('#sensecms-unsaved-copy').textContent = ui('Other sections have unsaved edits. Saving this section will discard those edits.');
    dialog.querySelector('[data-unsaved-confirm]').textContent = ui('Save this section');
}, true);
window.SenseCMSUnsavedGuard={isBypassing,dirty,confirmLeave:open}})();
