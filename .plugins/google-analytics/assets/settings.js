'use strict';
document.querySelectorAll('[data-ga-settings]').forEach(form => {
    let pending = false;
    form.addEventListener('submit', async event => {
        event.preventDefault();
        event.stopImmediatePropagation();
        if (pending || !form.reportValidity()) return;
        const button = form.querySelector('[type=submit]'), result = form.querySelector('[data-ga-result]');
        pending = true; button.disabled = true; form.setAttribute('aria-busy','true'); result.textContent = 'Saving…';
        try {
            const response = await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'}});
            const data = await response.json();
            result.textContent = data.message || 'Unable to save configuration.';
            if (response.ok && data.ok) form.elements.measurement_id.value = data.measurement_id;
        } catch { result.textContent = 'Save could not be confirmed. Refresh to check the current setting.'; }
        finally { pending = false; button.disabled = false; form.removeAttribute('aria-busy'); }
    }, true);
});
