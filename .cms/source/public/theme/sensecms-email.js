window.SenseCMSInterfaceText ??= source => source;
(() => {
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('[data-email-test-form]')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const button = form.querySelector('[data-email-test]');
        if (button.disabled) return;
        button.disabled = true;
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form), credentials: 'same-origin',
                headers: {Accept: 'application/json', 'X-SenseCMS-Request': '1'}
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || window.SenseCMSInterfaceText('The test e-mail could not be delivered.'));
            window.SenseCMSUI?.toast('success', result.message);
        } catch (error) {
            window.SenseCMSUI?.toast('error', error.message || window.SenseCMSInterfaceText('The test e-mail could not be delivered.'));
        } finally {
            button.disabled = false;
        }
    }, true);
})();
