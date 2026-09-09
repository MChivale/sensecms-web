(function () {
    const all = (selector, root = document) => [...root.querySelectorAll(selector)];

    function workspace(form) {
        return all('[data-content-workspace]').find((item) => item.dataset.contentWorkspace === form.id);
    }

    function state(form, next) {
        const root = workspace(form);
        if (!root) return;

        const status = root.querySelector('[data-content-status]');
        const detail = root.querySelector('[data-content-save-state]');
        const values = {
            saved: ['circle-check', 'Saved', 'All changes saved'],
            dirty: ['pencil-line', 'Unsaved', 'Unsaved changes'],
            saving: ['loader-circle', 'Saving', 'Saving changes…'],
        };
        const [icon, label, message] = values[next] || values.saved;

        if (status) {
            status.className = 'sensecms-content-status is-' + next;
            status.innerHTML = '<i data-lucide="' + icon + '"></i>' + label;
        }
        if (detail) {
            detail.className = 'sensecms-content-save-state is-' + next;
            const text = detail.querySelector('b');
            if (text) text.textContent = message;
        }
        form.dataset.contentState = next;
        window.lucide?.createIcons();
    }

    function markDirty(target) {
        const form = target.closest('[data-content-form]');
        if (form && form.dataset.contentState !== 'saving') state(form, 'dirty');
    }

    document.addEventListener('input', (event) => markDirty(event.target));
    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-seo-page], [data-popup-page]')) return;
        markDirty(event.target);
    });
    document.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-content-form]');
        if (form) state(form, 'saving');
    }, true);
})();
