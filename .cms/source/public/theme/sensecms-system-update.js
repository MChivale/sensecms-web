(() => {
    'use strict';
    const init = () => {
        const root = document.querySelector('[data-system-update]');
        if (!root || root.dataset.updateReady === '1') return;
        root.dataset.updateReady = '1';
        const button = root.querySelector('[data-update-check]');
        const message = root.querySelector('[data-update-message]');
        root.querySelector('[data-update-install]').disabled = true;
        button.addEventListener('click', async () => {
            button.disabled = true; message.textContent = 'Verifying the signed Stable release catalogue…';
            try {
                const response = await fetch('/system/update', {method: 'POST', credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({csrf: root.dataset.csrf, action: 'check'})});
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'Release check failed.');
                const state = result.data;
                if (!state.verified) throw new Error(state.error || 'The release catalogue could not be verified.');
                root.querySelector('[data-update-heading]').textContent = state.available ? 'A Stable Core update is available' :
                    state.latest.version ? 'No newer Stable Core release is listed' : 'No Stable Core release has been published';
                root.querySelector('[data-update-release]').textContent = state.latest.version ? 'Latest Stable: ' + state.latest.version : 'Development builds are not Stable releases.';
                root.querySelector('[data-update-checked]').textContent = 'Last successful check: ' + new Date(state.checked_at * 1000).toLocaleString();
                message.textContent = 'Signature verified. No installation was performed.' + (state.compatible ? '' : ' PHP compatibility requires review.');
            } catch (error) { message.textContent = error.message || 'Release check failed. Please retry.'; }
            finally { button.disabled = false; }
        });
    };
    document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('sensecms:content-ready', init);
    init();
})();
