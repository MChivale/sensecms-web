(() => {
    'use strict';

    const root = document.querySelector('[data-marketplace-root]');
    if (!root) return;

    const one = (selector, scope = root) => scope.querySelector(selector);
    const all = (selector, scope = root) => [...scope.querySelectorAll(selector)];
    const escape = value => String(value ?? '').replace(/[&<>'"]/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'}[character]));
    const safePath = value => String(value || '').startsWith('/') && !String(value).startsWith('//') ? String(value) : '';
    const typeLabel = type => ({theme: 'Theme', addon: 'Add-on', plugin: 'Plugin'}[type] || type);
    const renderIcons = (scope = root) => window.SenseCMSIcons ? window.SenseCMSIcons.render(scope) : window.lucide?.createIcons({root: scope});
    const toast = (type, message) => window.SenseCMSUI?.toast?.(type, message);
    const csrf = root.dataset.csrf;
    const licenseDialog = one('[data-marketplace-license]');
    const licenseForm = one('[data-marketplace-license-form]');
    const licenseInput = one('#marketplace-license-key');
    const licenseStatus = one('[data-marketplace-license-status]');
    let state = JSON.parse(one('[data-marketplace-bootstrap]').textContent || '{}');
    let activeItem = null;
    let timer = 0;
    let busy = false;

    const badge = item => item.active
        ? '<span class="is-active"><i data-lucide="circle-check"></i>Active</span>'
        : item.update_available
            ? '<span class="is-update"><i data-lucide="arrow-up-circle"></i>Update</span>'
            : item.installed
                ? '<span class="is-installed"><i data-lucide="check"></i>Installed</span>'
                : '<span class="is-available"><i data-lucide="sparkles"></i>Available</span>';

    function actions(item, detail = false) {
        const output = [];
        const update = item.installed && item.update_available;
        if ((!item.installed || update) && (item.official_id || item.catalog_entry_id)) {
            output.push(`<button class="sensecms-marketplace-action is-install" type="button" data-marketplace-install="${escape(item.identity)}" ${item.compatible ? '' : 'disabled'}><i data-lucide="${update ? 'arrow-up-circle' : 'package-check'}"></i>${update ? 'Update' : 'Install'}</button>`);
        }
        if (item.installed && item.uninstallable) {
            output.push(`<button class="sensecms-marketplace-action is-uninstall" type="button" data-marketplace-uninstall="${escape(item.identity)}"><i data-lucide="trash-2"></i>Uninstall</button>`);
        }
        if (item.installed && item.configurable && safePath(item.config_url)) {
            output.push(`<a class="sensecms-marketplace-action is-configure" href="${escape(item.config_url)}"><i data-lucide="settings-2"></i>${item.managed_releases ? 'Manage releases' : 'Configure'}</a>`);
        }
        if (!output.length && item.installed) output.push('<span class="sensecms-marketplace-managed"><i data-lucide="shield-check"></i>Managed by SenseCMS</span>');
        return `<div class="sensecms-marketplace-actions${detail ? ' is-detail' : ''}">${output.join('')}</div>`;
    }

    function card(item) {
        const image = item.type === 'theme' && safePath(item.screenshot)
            ? `<img src="${escape(item.screenshot)}" alt="${escape(item.name)} theme preview" loading="lazy">`
            : `<i data-lucide="${escape(item.icon || 'package')}"></i>`;
        return `<article class="sensecms-marketplace-card ${item.featured ? 'is-featured' : ''}" data-marketplace-item="${escape(item.identity)}">
            <button class="sensecms-marketplace-cover is-${escape(item.type)}" type="button" data-marketplace-open="${escape(item.identity)}" aria-label="View ${escape(item.name)} details">${image}<span>${escape(typeLabel(item.type))} · ${escape(item.release_channel)}</span>${item.featured ? '<b><i data-lucide="award"></i>Featured</b>' : ''}</button>
            <div class="sensecms-marketplace-card-body"><header><div class="sensecms-marketplace-state">${badge(item)}<span class="is-trust"><i data-lucide="${item.trust === 'verified' ? 'badge-check' : item.trust === 'unverified' ? 'triangle-alert' : 'shield-check'}"></i>${item.trust === 'verified' ? 'Signed' : item.trust === 'unverified' ? 'Unverified' : 'Distribution'}</span></div><button type="button" data-marketplace-open="${escape(item.identity)}" aria-label="View ${escape(item.name)} details"><i data-lucide="arrow-up-right"></i></button></header>
            <div class="sensecms-marketplace-title"><div><h3>${escape(item.name)}</h3><small>by ${escape(item.publisher)}</small></div><strong class="is-${escape(item.price_model)}">${escape(item.price_label)}</strong></div>
            <p>${escape(item.description)}</p><div class="sensecms-marketplace-tags">${(item.tags || []).slice(0, 3).map(tag => `<span>${escape(tag)}</span>`).join('')}</div>
            <footer><span>v${escape(item.version)} · ${escape(item.release_channel)}${item.pending_version ? `<br>v${escape(item.pending_version)} installed · awaiting activation` : ''}${item.update_available ? `<br>v${escape(item.available_version)} available to download` : ''}</span><button type="button" data-marketplace-open="${escape(item.identity)}">Details</button></footer>${actions(item)}</div></article>`;
    }

    function renderSummary() {
        const summary = state.summary || {};
        const values = [summary.total, summary.installed, summary.updates, summary.verified];
        all('[data-marketplace-summary] strong').forEach((node, index) => node.textContent = Number(values[index] || 0));
    }

    function syncChips() {
        all('[data-marketplace-chip]').forEach(button => {
            const [key, value] = button.dataset.marketplaceChip.split(':');
            const active = one(`[data-marketplace-filter="${CSS.escape(key)}"]`)?.value === value;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    function render() {
        const grid = one('[data-marketplace-grid]');
        grid.innerHTML = state.items.length ? state.items.map(card).join('') : '<div class="sensecms-marketplace-empty"><i data-lucide="search-x"></i><h3>No matching components</h3><p>Adjust the filters or search for another capability.</p><button type="button" data-marketplace-reset>Reset filters</button></div>';
        one('[data-marketplace-result-count]').textContent = `${state.pagination.total} matching component${state.pagination.total === 1 ? '' : 's'}`;
        const pagination = state.pagination;
        const navigation = one('[data-marketplace-pagination]');
        navigation.hidden = pagination.pages <= 1;
        navigation.innerHTML = `<button type="button" data-marketplace-page="${pagination.page - 1}" ${pagination.page <= 1 ? 'disabled' : ''}><i data-lucide="arrow-left"></i>Previous</button><span>Page <b>${pagination.page}</b> of ${pagination.pages}</span><button type="button" data-marketplace-page="${pagination.page + 1}" ${pagination.page >= pagination.pages ? 'disabled' : ''}>Next<i data-lucide="arrow-right"></i></button>`;
        renderSummary();
        syncChips();
        renderIcons(grid);
        renderIcons(navigation);
    }

    function detail(item) {
        const drawer = one('[data-marketplace-drawer]');
        const target = one('[data-marketplace-detail]');
        const image = item.type === 'theme' && safePath(item.screenshot) ? `<img src="${escape(item.screenshot)}" alt="${escape(item.name)} preview">` : `<i data-lucide="${escape(item.icon || 'package')}"></i>`;
        const features = (item.features || []).map(value => `<li><i data-lucide="check"></i>${escape(value)}</li>`).join('');
        const dependencies = (item.dependencies || []).length ? `<section><span>DEPENDENCIES</span><div class="sensecms-marketplace-module-list">${item.dependencies.map(value => `<em>${escape(typeof value === 'string' ? value : value.slug || value.name || 'SenseCMS component')}</em>`).join('')}</div></section>` : '';
        const version = `${escape(item.version)}${item.pending_version ? `<br>${escape(item.pending_version)} installed · awaiting activation` : ''}${item.update_available ? `<br>${escape(item.available_version)} available to download` : ''}`;
        target.innerHTML = `<div class="sensecms-marketplace-detail-cover is-${escape(item.type)}">${image}<div><span>${escape(typeLabel(item.type))} · ${escape(item.group)}</span><h3>${escape(item.name)}</h3><p>by ${escape(item.publisher)}</p></div></div>
            <div class="sensecms-marketplace-detail-body"><div class="sensecms-marketplace-state">${badge(item)}<span class="is-trust"><i data-lucide="${item.trust === 'verified' ? 'badge-check' : item.trust === 'unverified' ? 'triangle-alert' : 'shield-check'}"></i>${item.trust === 'verified' ? 'Verified publisher signature' : item.trust === 'unverified' ? 'Archive or publisher trust needs review' : 'Distribution'}</span></div>
            <div class="sensecms-marketplace-detail-price"><strong>${escape(item.price_label)}</strong><small>${item.price_model === 'paid' ? 'Licensed product' : 'Free product · license key still required'}</small></div>
            <p class="sensecms-marketplace-description">${escape(item.description)}</p><dl><div><dt>Version</dt><dd>${version}</dd></div><div><dt>Compatibility</dt><dd class="${item.compatible ? 'is-ok' : 'is-error'}">${item.compatible ? 'Compatible' : 'Not compatible'} · ${escape(item.engine)}</dd></div><div><dt>Release</dt><dd>${escape(item.release_channel)}</dd></div><div><dt>Integrity</dt><dd>${item.trust === 'verified' ? 'Ed25519 + SHA-256' : item.trust === 'unverified' ? 'Verification required' : 'SenseCMS distribution'}</dd></div></dl>
            <section><span>CAPABILITIES</span><ul>${features}</ul></section>${dependencies}${actions(item, true)}</div>`;
        drawer.hidden = false;
        drawer.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('sensecms-marketplace-open');
        renderIcons(drawer);
    }

    function closeDetail() {
        const drawer = one('[data-marketplace-drawer]');
        drawer.hidden = true;
        drawer.setAttribute('aria-hidden', 'true');
        document.documentElement.classList.remove('sensecms-marketplace-open');
    }

    async function post(url, payload) {
        const response = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-SenseCMS-Request': '1'}, body: JSON.stringify({csrf, ...payload})});
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.ok) throw new Error(result.message || 'The Marketplace operation could not be completed.');
        return result;
    }

    function confirm(title, message, action) {
        if (window.SenseCMSUI?.modal) return window.SenseCMSUI.modal(title, message, action);
        if (window.confirm(message)) action();
    }

    async function completeInstallation(staged) {
        const result = await post('/system/extensions/packages/install', {token: staged.data.token});
        toast('success', result.message);
        closeDetail();
        await load(state.pagination.page);
    }

    async function stageInstall(item, license = '') {
        if (busy) return;
        busy = true;
        root.classList.add('is-loading');
        try {
            const staged = item.official_id
                ? await post(`/marketplace/official/${encodeURIComponent(item.official_id)}/stage`, {license})
                : await post(`/marketplace/catalog/${Number(item.catalog_entry_id)}/stage`, {});
            closeLicense();
            confirm(item.installed ? 'Install verified update' : 'Install verified component', `${staged.data.name} ${staged.data.version} passed license, publisher signature, checksum, compatibility and dependency verification. Continue with the protected installation?`, () => completeInstallation(staged).catch(error => toast('error', error.message)));
        } catch (error) {
            if (licenseDialog.open) {
                licenseStatus.hidden = false;
                licenseStatus.textContent = error.message;
                licenseInput.focus();
            } else toast('error', error.message);
        } finally {
            busy = false;
            root.classList.remove('is-loading');
        }
    }

    function openLicense(item) {
        activeItem = item;
        one('[data-marketplace-license-name]').textContent = `${item.name} · ${item.price_label}`;
        licenseStatus.hidden = true;
        licenseStatus.textContent = '';
        licenseInput.value = '';
        licenseDialog.showModal();
        requestAnimationFrame(() => licenseInput.focus());
        renderIcons(licenseDialog);
    }

    function closeLicense() {
        if (licenseDialog.open) licenseDialog.close();
        licenseInput.value = '';
        licenseStatus.hidden = true;
        activeItem = null;
    }

    function install(item) {
        if (!item.compatible) return toast('error', 'This component is not compatible with the installed SenseCMS engine.');
        if (item.official_id) openLicense(item);
        else stageInstall(item);
    }

    function uninstall(item) {
        confirm('Uninstall component', `Uninstall ${item.name}? SenseCMS will create a private recovery package before removing files and runtime data.`, async () => {
            try {
                const result = await post(`/marketplace/packages/${encodeURIComponent(item.type)}/${encodeURIComponent(item.slug)}/uninstall`, {});
                toast('success', result.message);
                closeDetail();
                await load(state.pagination.page);
            } catch (error) { toast('error', error.message); }
        });
    }

    async function load(page = 1) {
        if (busy) return;
        busy = true;
        root.classList.add('is-loading');
        const params = new URLSearchParams({page});
        all('[data-marketplace-filter]').forEach(control => { if (control.value && control.value !== 'all') params.set(control.dataset.marketplaceFilter, control.value); });
        try {
            const response = await fetch(`/api/marketplace?${params}`, {headers: {Accept: 'application/json', 'X-SenseCMS-Request': '1'}, cache: 'no-store'});
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || 'Marketplace could not be loaded.');
            state = payload.data;
            history.replaceState({}, '', location.pathname + (params.size ? `?${params}` : ''));
            render();
        } catch (error) { toast('error', error.message); }
        finally { busy = false; root.classList.remove('is-loading'); }
    }

    root.addEventListener('click', event => {
        const open = event.target.closest('[data-marketplace-open]');
        if (open) {
            const item = state.items.find(value => value.identity === open.dataset.marketplaceOpen);
            if (item) detail(item);
            return;
        }
        if (event.target.closest('[data-marketplace-close]')) return closeDetail();
        const page = event.target.closest('[data-marketplace-page]');
        if (page && !page.disabled) return load(Number(page.dataset.marketplacePage));
        const chip = event.target.closest('[data-marketplace-chip]');
        if (chip) {
            const [key, value] = chip.dataset.marketplaceChip.split(':');
            const control = one(`[data-marketplace-filter="${CSS.escape(key)}"]`);
            if (control) control.value = value;
            return load(1);
        }
        if (event.target.closest('[data-marketplace-reset]')) {
            all('[data-marketplace-filter]').forEach(control => control.value = control.dataset.marketplaceFilter === 'q' ? '' : 'all');
            return load(1);
        }
        const installButton = event.target.closest('[data-marketplace-install]');
        if (installButton) {
            const item = state.items.find(value => value.identity === installButton.dataset.marketplaceInstall);
            if (item) install(item);
            return;
        }
        const uninstallButton = event.target.closest('[data-marketplace-uninstall]');
        if (uninstallButton) {
            const item = state.items.find(value => value.identity === uninstallButton.dataset.marketplaceUninstall);
            if (item) uninstall(item);
        }
    });

    one('[data-marketplace-drawer]').addEventListener('click', event => { if (event.target === event.currentTarget) closeDetail(); });
    licenseForm.addEventListener('submit', event => {
        event.preventDefault();
        if (!activeItem) return;
        const license = licenseInput.value.trim();
        if (!license || license.length > 128) {
            licenseStatus.hidden = false;
            licenseStatus.textContent = 'Enter a valid license key containing no more than 128 characters.';
            return;
        }
        stageInstall(activeItem, license);
    });
    all('[data-marketplace-license-close]').forEach(button => button.addEventListener('click', closeLicense));
    all('[data-marketplace-filter]').forEach(control => control.addEventListener(control.type === 'search' ? 'input' : 'change', () => {
        clearTimeout(timer);
        timer = setTimeout(() => load(1), control.type === 'search' ? 220 : 0);
    }));
    addEventListener('keydown', event => {
        if (event.key === 'Escape' && !licenseDialog.open) closeDetail();
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            one('[data-marketplace-filter="q"]').focus();
        }
    });

    Object.entries(state.filters || {}).forEach(([key, value]) => {
        const control = one(`[data-marketplace-filter="${CSS.escape(key)}"]`);
        if (control && value !== '') control.value = value;
    });
    render();
})();
