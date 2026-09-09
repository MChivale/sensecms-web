(() => {
    'use strict';
    const html = document.documentElement;
    const key = '__SENSECMS_SHELL__';
    const read = () => {
        try { return Object.assign({theme:'light',menuHidden:false,textScale:100}, JSON.parse(localStorage.getItem(key) || '{}')); }
        catch { return {theme:'light',menuHidden:false,textScale:100}; }
    };
    let state = read();
    let mobile = matchMedia('(max-width: 1140px)').matches;
    const store = () => localStorage.setItem(key, JSON.stringify(state));
    const resolvedTheme = () => state.theme === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : state.theme;
    const syncTheme = () => {
        html.dataset.theme = resolvedTheme();
        html.dataset.sidenavColor = html.dataset.theme;
        html.classList.toggle('dark', html.dataset.theme === 'dark');
        document.querySelectorAll('[name="sensecms-theme"]').forEach(input => input.checked = input.value === state.theme);
        const button = document.querySelector('#light-dark-mode');
        if (button) button.setAttribute('aria-label', html.dataset.theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
    };
    const syncMenu = () => {
        if (mobile) html.dataset.sidenavSize = 'offcanvas';
        else html.dataset.sidenavSize = state.menuHidden ? 'hidden' : 'default';
        document.querySelectorAll('[name="sensecms-menu"]').forEach(input => input.checked = input.value === (state.menuHidden ? 'hidden' : 'default'));
        const open = html.classList.contains('sidenav-enable');
        document.querySelector('#button-toggle-menu')?.setAttribute('aria-expanded', String(mobile ? open : !state.menuHidden));
    };
    const syncTextScale = () => {
        state.textScale = Math.min(200, Math.max(100, Math.round((Number(state.textScale) || 100) / 10) * 10));
        html.style.setProperty('--sensecms-text-zoom', String(state.textScale / 100));
        html.dataset.textScale = String(state.textScale);
        const range = document.querySelector('[data-text-range]');
        const output = document.querySelector('output[data-text-scale]');
        const decrease = document.querySelector('[data-text-decrease]');
        const increase = document.querySelector('[data-text-increase]');
        if (range) range.value = String(state.textScale);
        if (output) { output.value = state.textScale + '%'; output.textContent = state.textScale + '%'; }
        if (decrease) decrease.disabled = state.textScale <= 100;
        if (increase) increase.disabled = state.textScale >= 200;
    };
    const setMenuOpen = open => {
        if (mobile) {
            html.classList.toggle('sidenav-enable', open);
            document.querySelector('[data-menu-backdrop]')?.toggleAttribute('hidden', !open);
        } else {
            state.menuHidden = !open;
            store();
        }
        syncMenu();
    };
    const positionActiveMenu = () => {
        const scroller = document.querySelector('[data-menu-scroll]');
        const active = scroller?.querySelector('.menu-link.active');
        if (!scroller || !active) return;
        let section = active.closest('.menu-item')?.previousElementSibling;
        while (section && !section.classList.contains('menu-title')) section = section.previousElementSibling;
        const target = section || active;
        requestAnimationFrame(() => {
            const offset = target.getBoundingClientRect().top - scroller.getBoundingClientRect().top;
            scroller.scrollTop = Math.max(0, scroller.scrollTop + offset - 10);
        });
    };
    const closeMobileMenu = () => setMenuOpen(false);

    const initializeSearch = () => {
        const root = document.querySelector('[data-console-search]');
        const input = root?.querySelector('input');
        const panel = root?.querySelector('[data-console-search-results]');
        const list = root?.querySelector('[data-console-search-list]');
        const status = root?.querySelector('[data-console-search-status]');
        if (!root || !input || !panel || !list || !status) return;
        let timer = 0, controller, active = -1;
        const icons = scope => window.SenseCMSIcons?.render(scope);
        const close = () => { panel.hidden = true; root.classList.remove('is-open'); active = -1; };
        const open = () => { root.classList.add('is-open'); input.focus(); if (input.value.trim()) search(); };
        const select = index => {
            const rows = [...list.querySelectorAll('a')];
            if (!rows.length) return;
            active = Math.max(0, Math.min(rows.length - 1, index));
            rows.forEach((row, i) => row.classList.toggle('is-active', i === active));
            rows[active].scrollIntoView({block:'nearest'});
        };
        const render = items => {
            list.replaceChildren();
            active = -1;
            if (!items.length) {
                const empty = document.createElement('div');
                empty.className = 'sensecms-console-search-empty';
                empty.innerHTML = '<div><i data-lucide="search-x"></i><strong>No matching administration functions</strong><p>Try a page name, action, module or system area.</p></div>';
                list.append(empty); icons(empty); return;
            }
            items.forEach(item => {
                const link = document.createElement('a');
                link.className = 'sensecms-console-search-item';
                link.href = item.url;
                const icon = document.createElement('i'); icon.dataset.lucide = item.icon || 'arrow-right';
                const copy = document.createElement('span');
                const title = document.createElement('strong'); title.textContent = item.title;
                const description = document.createElement('small'); description.textContent = item.description;
                const section = document.createElement('b'); section.textContent = item.section;
                copy.append(title, description); link.append(icon, copy, section); list.append(link);
            });
            icons(list);
        };
        const search = async () => {
            const query = input.value.trim();
            if (!query) { panel.hidden = true; render([]); return; }
            controller?.abort(); controller = new AbortController();
            panel.hidden = false; status.textContent = 'Searching…';
            try {
                const response = await fetch('/api/console-search?q=' + encodeURIComponent(query), {headers:{Accept:'application/json','X-SenseCMS-Request':'1'}, signal:controller.signal});
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Search is unavailable.');
                const items = payload.data?.items || [];
                status.textContent = items.length + (items.length === 1 ? ' result' : ' results');
                render(items);
            } catch (error) {
                if (error.name === 'AbortError') return;
                status.textContent = 'Search unavailable'; render([]);
            }
        };
        input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 140); });
        input.addEventListener('keydown', event => {
            const rows = list.querySelectorAll('a');
            if (event.key === 'ArrowDown') { event.preventDefault(); select(active + 1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); select(active < 0 ? rows.length - 1 : active - 1); }
            else if (event.key === 'Enter' && active >= 0 && rows[active]) { event.preventDefault(); rows[active].click(); }
            else if (event.key === 'Escape') { event.preventDefault(); close(); }
        });
        document.querySelectorAll('[data-console-search-open]').forEach(button => button.addEventListener('click', open));
        root.querySelector('[data-console-search-close]')?.addEventListener('click', event => { event.preventDefault(); close(); });
        document.addEventListener('keydown', event => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); open(); }
            else if (event.key === 'Escape' && root.classList.contains('is-open')) close();
        });
        document.addEventListener('click', event => { if (!root.contains(event.target) && !event.target.closest('[data-console-search-open]')) close(); });
        root.querySelector('kbd').textContent = navigator.platform.toLowerCase().includes('mac') ? '⌘ K' : 'Ctrl K';
    };

    const initializeSettings = () => {
        const panel = document.querySelector('[data-settings-panel]');
        const backdrop = document.querySelector('[data-settings-backdrop]');
        if (!panel || !backdrop) return;
        let previousFocus = null;
        const focusable = () => [...panel.querySelectorAll('button,a[href],input')].filter(element => !element.disabled && !element.hidden);
        const open = value => {
            panel.classList.toggle('is-open', value); backdrop.hidden = !value;
            panel.setAttribute('aria-hidden', String(!value));
            document.querySelector('[data-settings-toggle]')?.setAttribute('aria-expanded', String(value));
            const workspace = document.querySelector('.wrapper');
            if (workspace) workspace.inert = value;
            if (value) { previousFocus = document.activeElement; requestAnimationFrame(() => document.querySelector('[data-settings-close]')?.focus()); }
            else if (previousFocus) { previousFocus.focus(); previousFocus = null; }
        };
        document.querySelector('[data-settings-toggle]')?.addEventListener('click', () => open(true));
        document.querySelector('[data-settings-close]')?.addEventListener('click', () => open(false));
        backdrop.addEventListener('click', () => open(false));
        document.querySelectorAll('[name="sensecms-theme"]').forEach(input => input.addEventListener('change', () => { state.theme = input.value; store(); syncTheme(); }));
        document.querySelectorAll('[name="sensecms-menu"]').forEach(input => input.addEventListener('change', () => { state.menuHidden = input.value === 'hidden'; store(); syncMenu(); }));
        document.querySelector('[data-text-range]')?.addEventListener('input', event => { state.textScale = Number(event.target.value); store(); syncTextScale(); });
        document.querySelector('[data-text-decrease]')?.addEventListener('click', () => { state.textScale -= 10; store(); syncTextScale(); });
        document.querySelector('[data-text-increase]')?.addEventListener('click', () => { state.textScale += 10; store(); syncTextScale(); });
        document.querySelector('[data-text-reset]')?.addEventListener('click', () => { state.textScale = 100; store(); syncTextScale(); });
        document.querySelector('[data-settings-reset]')?.addEventListener('click', () => { state = {theme:'light',menuHidden:false,textScale:100};store();syncTheme();syncMenu();syncTextScale(); });
        document.addEventListener('keydown', event => { if (!panel.classList.contains('is-open')) return; if (event.key === 'Escape') open(false); else if (event.key === 'Tab') { const elements = focusable(), first = elements[0], last = elements.at(-1); if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } } });
    };

    document.addEventListener('DOMContentLoaded', () => {
        syncTheme(); syncMenu(); syncTextScale(); positionActiveMenu(); initializeSearch(); initializeSettings();
        document.querySelector('#button-toggle-menu')?.addEventListener('click', () => setMenuOpen(mobile ? !html.classList.contains('sidenav-enable') : state.menuHidden));
        document.querySelector('#button-hover-toggle')?.addEventListener('click', () => setMenuOpen(false));
        document.querySelector('[data-menu-backdrop]')?.addEventListener('click', closeMobileMenu);
        document.querySelectorAll('.app-menu a').forEach(link => link.addEventListener('click', () => { if (mobile) closeMobileMenu(); }));
        document.querySelector('#light-dark-mode')?.addEventListener('click', () => { state.theme = html.dataset.theme === 'dark' ? 'light' : 'dark'; store(); syncTheme(); });
        matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (state.theme === 'system') syncTheme(); });
        addEventListener('resize', () => { const next = matchMedia('(max-width: 1140px)').matches; if (next === mobile) return; mobile = next; html.classList.remove('sidenav-enable'); document.querySelector('[data-menu-backdrop]')?.setAttribute('hidden',''); syncMenu(); positionActiveMenu(); });
    });
})();
