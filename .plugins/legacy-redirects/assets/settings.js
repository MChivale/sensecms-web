'use strict';

document.querySelectorAll('[data-redirect-settings]').forEach(form => {
    const ui = window.SenseCMSInterfaceText ?? (text => text);
    const list = form.querySelector('[data-redirect-rules]');
    const template = document.querySelector('[data-redirect-template]');
    const empty = form.querySelector('[data-redirect-empty]');
    const result = form.querySelector('[data-redirect-result]');
    let pending = false;
    let nextIndex = 0;
    list.querySelectorAll('[data-redirect-rule]').forEach(rule => {
        const name = rule.querySelector('[name^="rules["]')?.name || '';
        const index = Number(name.match(/^rules\[(\d+)\]/)?.[1]);
        if (Number.isInteger(index)) nextIndex = Math.max(nextIndex, index + 1);
    });

    const syncEmpty = () => { empty.hidden = list.querySelectorAll('[data-redirect-rule]').length > 0; };
    form.addEventListener('click', event => {
        const add = event.target.closest('[data-redirect-add]');
        if (add) {
            const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
            list.insertAdjacentHTML('beforeend', html);
            syncEmpty();
            return;
        }
        const remove = event.target.closest('[data-redirect-remove]');
        if (remove) {
            remove.closest('[data-redirect-rule]').remove();
            syncEmpty();
        }
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        event.stopImmediatePropagation();
        if (pending || !form.reportValidity()) return;
        const button = form.querySelector('[type=submit]');
        pending = true; button.disabled = true; form.setAttribute('aria-busy','true'); result.textContent = ui('Saving…');
        try {
            const response = await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'}});
            const data = await response.json();
            result.textContent = data.message || ui('Unable to save redirect rules.');
        } catch { result.textContent = ui('Save could not be confirmed. Refresh to check the current rules.'); }
        finally { pending = false; button.disabled = false; form.removeAttribute('aria-busy'); }
    }, true);
    syncEmpty();
});
