window.SenseCMSInterfaceText ??= source => source;
window.SenseCMSInterfaceHtml ??= source => source;
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
    const store = () => { try { localStorage.setItem(key, JSON.stringify(state)); } catch {} };
    const resolvedTheme = () => state.theme === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : state.theme;
    const syncTheme = () => {
        html.dataset.theme = resolvedTheme();
        html.dataset.sidenavColor = html.dataset.theme;
        html.classList.toggle('dark', html.dataset.theme === 'dark');
        document.querySelectorAll('[name="sensecms-theme"]').forEach(input => input.checked = input.value === state.theme);
        const button = document.querySelector('#light-dark-mode');
        if (button) button.setAttribute('aria-label', window.SenseCMSInterfaceText(html.dataset.theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'));
    };
    const syncMenu = () => {
        if (mobile) html.dataset.sidenavSize = 'offcanvas';
        else html.dataset.sidenavSize = state.menuHidden ? 'hidden' : 'default';
        document.querySelectorAll('[name="sensecms-menu"]').forEach(input => input.checked = input.value === (state.menuHidden ? 'hidden' : 'default'));
        const drawerOpen = mobile && html.classList.contains('sidenav-enable');
        const visible = mobile ? drawerOpen : !state.menuHidden;
        const menu = document.querySelector('#app-menu');
        const toggle = document.querySelector('#button-toggle-menu');
        for (const background of document.querySelectorAll('.page-content, .sensecms-skip-link')) background.inert = drawerOpen;
        if (menu) {
            if (!visible && menu.contains(document.activeElement)) toggle?.focus();
            menu.inert = !visible;
            menu.setAttribute('aria-hidden', String(!visible));
        }
        toggle?.setAttribute('aria-expanded', String(visible));
    };
    const syncTextScale = () => {
        state.textScale = Math.min(200, Math.max(100, Math.round((Number(state.textScale) || 100) / 10) * 10));
        html.style.setProperty('--sensecms-text-zoom', String(state.textScale / 100));
        html.dataset.textScale = String(state.textScale);
        const width = document.documentElement.clientWidth / (state.textScale / 100);
        html.style.setProperty('--sensecms-viewport-width', width + 'px');
        html.style.setProperty('--sensecms-viewport-height', innerHeight / (state.textScale / 100) + 'px');
        html.dataset.compact = String(width <= 760);
        html.dataset.narrow = String(width <= 360);
        const nextMobile = width <= 1140;
        if (mobile !== nextMobile) {
            mobile = nextMobile;
            html.classList.remove('sidenav-enable');
            document.querySelector('[data-menu-backdrop]')?.setAttribute('hidden','');
        }
        syncMenu();
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
        if (!mobile) return;
        document.querySelector(open ? '#button-hover-toggle' : '#button-toggle-menu')?.focus();
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

    const initializeMenuGroups = () => {
        document.querySelectorAll('.side-nav > .menu-title').forEach((title, index) => {
            if (title.dataset.groupBound) return;
            const label = title.textContent.trim();
            const group = title.dataset.menuGroup || label;
            if (group === 'Workspace') return;
            const items = [];
            for (let item = title.nextElementSibling; item && !item.classList.contains('menu-title'); item = item.nextElementSibling) items.push(item);
            if (!items.length) { title.hidden = true; return; }
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'sensecms-menu-group-toggle';
            button.textContent = label;
            button.setAttribute('aria-controls', items.map((item, n) => item.id = 'menu-group-' + index + '-' + n).join(' '));
            title.dataset.groupBound = '1';
            title.replaceChildren(button);
            const setOpen = open => {
                button.setAttribute('aria-expanded', String(open));
                items.forEach(item => item.hidden = !open);
            };
            const active = () => items.some(item => item.querySelector('.menu-link.active'));
            setOpen(active() || (state.groups?.[group] ?? group === 'Content'));
            button.addEventListener('click', () => {
                const open = button.getAttribute('aria-expanded') !== 'true';
                setOpen(open);
                state.groups = {...state.groups, [group]:open};
                store();
            });

        });
    };

    const closeMobileMenu = () => setMenuOpen(false);

    let searchRoot, searchBindings;
    const initializeSearch = () => {
        const root = document.querySelector('[data-console-search]');
        if (root === searchRoot) return;
        searchBindings?.abort();
        searchRoot = root;
        const input = root?.querySelector('input');
        const panel = root?.querySelector('[data-console-search-results]');
        const list = root?.querySelector('[data-console-search-list]');
        const status = root?.querySelector('[data-console-search-status]');
        if (!root || !input || !panel || !list || !status) return;
        let timer = 0, controller, active = -1, opener;
        searchBindings = new AbortController();
        const {signal} = searchBindings;
        signal.addEventListener('abort', () => { clearTimeout(timer); controller?.abort(); }, {once:true});
        const icons = scope => window.SenseCMSIcons?.render(scope);
        const toggle = document.querySelector('[data-console-search-open]');
        const close = (restore = false) => {
            clearTimeout(timer); controller?.abort();
            panel.hidden = true; root.classList.remove('is-open'); active = -1;
            toggle?.setAttribute('aria-expanded','false');
            if (restore) {
                const target = opener?.isConnected && opener !== document.body && !root.contains(opener) ? opener : (toggle?.getClientRects().length ? toggle : input);
                target.focus();
            }
            opener = null;
        };
        const open = () => {
            if (!root.classList.contains('is-open')) opener = document.activeElement;
            toggle?.setAttribute('aria-expanded','true');
            root.classList.add('is-open'); input.focus(); if (input.value.trim()) search(); };
        const select = index => {
            const rows = [...list.querySelectorAll('a')];
            if (!rows.length) return;
            active = Math.max(0, Math.min(rows.length - 1, index));
            rows.forEach((row, i) => row.classList.toggle('is-active', i === active));
            rows[active].focus({preventScroll:true});
            rows[active].scrollIntoView({block:'nearest'});
        };
        const render = items => {
            list.replaceChildren();
            active = -1;
            if (!items.length) {
                const empty = document.createElement('div');
                empty.className = 'sensecms-console-search-empty';
                empty.innerHTML = ("<div><i data-lucide=\"search-x\"></i><strong>"+window.SenseCMSInterfaceHtml("No matching administration functions")+"</strong><p>"+window.SenseCMSInterfaceHtml("Try a page name, action, module or system area.")+"</p></div>");
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
            panel.hidden = false; status.textContent = window.SenseCMSInterfaceText('Searching…');
            try {
                const response = await fetch('/api/console-search?q=' + encodeURIComponent(query), {headers:{Accept:'application/json','X-SenseCMS-Request':'1'}, signal:controller.signal});
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || window.SenseCMSInterfaceText('Search is unavailable.'));
                const items = payload.data?.items || [];
                status.textContent = html.lang === 'pl'
                    ? window.SenseCMSInterfaceText('Results: {count}', {'{count}':items.length})
                    : items.length + (items.length === 1 ? ' result' : ' results');
                render(items);
            } catch (error) {
                if (error.name === 'AbortError') return;
                status.textContent = window.SenseCMSInterfaceText('Search unavailable'); render([]);
            }
        };
        input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 140); });
        root.addEventListener('keydown', event => {
            const rows = list.querySelectorAll('a');
            const current = Array.from(rows).indexOf(document.activeElement);
            if (current >= 0) active = current;
            if (event.key === 'ArrowDown' && !panel.hidden) { event.preventDefault(); select(active + 1); }
            else if (event.key === 'ArrowUp' && current === 0) { event.preventDefault(); active = -1; input.focus(); }
            else if (event.key === 'ArrowUp' && !panel.hidden) { event.preventDefault(); select(active < 0 ? rows.length - 1 : active - 1); }
            else if (event.key === 'Enter' && document.activeElement === input && active >= 0 && rows[active]) { event.preventDefault(); rows[active].click(); }
            else if (event.key === 'Escape') { event.preventDefault(); close(true); }
        });
        document.querySelectorAll('[data-console-search-open]').forEach(button => button.addEventListener('click', open));
        root.querySelector('[data-console-search-close]')?.addEventListener('click', event => { event.preventDefault(); close(true); });
        document.addEventListener('keydown', event => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); if (!root.closest('[inert]')) open(); }
            else if (event.key === 'Escape' && root.classList.contains('is-open')) close(true);
        }, {signal});
        document.addEventListener('click', event => { if (!root.contains(event.target) && !event.target.closest('[data-console-search-open]')) close(); }, {signal});
        root.querySelector('kbd').textContent = navigator.platform.toLowerCase().includes('mac') ? '⌘ K' : 'Ctrl K';
    };

    const initializeSettings = () => {
        const panel = document.querySelector('[data-settings-panel]');
        const backdrop = document.querySelector('[data-settings-backdrop]');
        if (!panel || !backdrop) return;
        let previousFocus = null;
        const focusable = () => [...panel.querySelectorAll('button,a[href],input')].filter(element => !element.disabled && !element.hidden);
        const open = value => {
            const workspace = document.querySelector('.wrapper');
            if (value) previousFocus = document.activeElement;
            panel.classList.toggle('is-open', value);
            backdrop.hidden = !value;
            document.querySelector('[data-settings-toggle]')?.setAttribute('aria-expanded', String(value));
            if (value) {
                panel.inert = false;
                panel.setAttribute('aria-hidden', 'false');
                panel.querySelector('[data-settings-close]')?.focus();
                if (workspace) workspace.inert = true;
            } else {
                if (workspace) workspace.inert = false;
                previousFocus?.focus();
                previousFocus = null;
                panel.inert = true;
                panel.setAttribute('aria-hidden', 'true');
            }
        };
        document.addEventListener('click', event => { if (event.target.closest('[data-settings-toggle]')) open(true); });
        document.querySelector('[data-settings-close]')?.addEventListener('click', () => open(false));
        backdrop.addEventListener('click', () => open(false));
        panel.addEventListener('change', event => {
            if (!event.target.matches('[name="sensecms-interface-language"]')) return;
            const language = event.target.value;
            if (!['en', 'pl'].includes(language) || language === html.lang) return;
            panel.querySelector('[name="sensecms-interface-language"][value="' + html.lang + '"]').checked = true;
            const changeLanguage = () => {
                document.cookie = 'sensecms_console_language=' + language + '; Path=/; Max-Age=31536000; SameSite=Lax' +
                    (location.protocol === 'https:' ? '; Secure' : '');
                const current = new URL(location.href);
                if (current.pathname === '/calendar' && current.searchParams.has('lang')) {
                    current.searchParams.delete('lang');
                    location.assign(current.href);
                } else location.reload();
            };
            const guard = window.SenseCMSUnsavedGuard;
            if (guard?.dirty()) guard.confirmLeave(changeLanguage);
            else changeLanguage();
        });
        document.querySelectorAll('[name="sensecms-theme"]').forEach(input => input.addEventListener('change', () => { state.theme = input.value; store(); syncTheme(); }));
        document.querySelectorAll('[name="sensecms-menu"]').forEach(input => input.addEventListener('change', () => { state.menuHidden = input.value === 'hidden'; store(); syncMenu(); }));
        document.querySelector('[data-text-range]')?.addEventListener('input', event => { state.textScale = Number(event.target.value); store(); syncTextScale(); });
        document.querySelector('[data-text-decrease]')?.addEventListener('click', () => { state.textScale -= 10; store(); syncTextScale(); });
        document.querySelector('[data-text-increase]')?.addEventListener('click', () => { state.textScale += 10; store(); syncTextScale(); });
        document.querySelector('[data-text-reset]')?.addEventListener('click', () => { state.textScale = 100; store(); syncTextScale(); });
        document.querySelector('[data-settings-reset]')?.addEventListener('click', () => { state = {theme:'light',menuHidden:false,textScale:100};store();syncTheme();syncMenu();syncTextScale(); });
        document.addEventListener('keydown', event => { if (!panel.classList.contains('is-open')) return; if (event.key === 'Escape') open(false); else if (event.key === 'Tab') { const elements = focusable(), first = elements[0], last = elements.at(-1); if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } } });
    };

    const refreshShell = () => {
        syncTheme(); syncTextScale(); initializeMenuGroups(); initializeSearch(); positionActiveMenu();
    };
    document.addEventListener('sensecms:content-ready', refreshShell);
    document.addEventListener('DOMContentLoaded', () => {
        refreshShell(); initializeSettings();
        document.addEventListener('click', event => {
            if (event.target.closest('#button-toggle-menu')) setMenuOpen(mobile ? !html.classList.contains('sidenav-enable') : state.menuHidden);
            else if (event.target.closest('#button-hover-toggle')) setMenuOpen(false);
            else if (mobile && event.target.closest('.app-menu a')) closeMobileMenu();
            else if (event.target.closest('#light-dark-mode')) { state.theme = html.dataset.theme === 'dark' ? 'light' : 'dark'; store(); syncTheme(); }
        });
        document.querySelector('[data-menu-backdrop]')?.addEventListener('click', closeMobileMenu);
        document.addEventListener('keydown', event => {
            const menu = event.target.closest?.('#app-menu');
            if (!menu || !mobile || !html.classList.contains('sidenav-enable') || event.defaultPrevented) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                closeMobileMenu();
            } else if (event.key === 'Tab') {
                const stops = [...menu.querySelectorAll('a[href],button,input,select,textarea,[tabindex]')]
                    .filter(el => el.tabIndex >= 0 && !el.matches(':disabled') && !el.closest('[inert]') && el.checkVisibility({visibilityProperty:true}));
                const first = stops[0], last = stops.at(-1);
                if (event.shiftKey ? document.activeElement === first : document.activeElement === last) {
                    event.preventDefault();
                    (event.shiftKey ? last : first)?.focus();
                }
            }
        });
        matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (state.theme === 'system') syncTheme(); });
        addEventListener('resize', () => { syncTextScale(); positionActiveMenu(); });
    });
})();

// A workspace switch is navigation and obeys the same unsaved-content guard.
document.addEventListener('submit',event=>{
    const form=event.target.closest?.('[data-workspace-switch]');
    if(!form)return;
    const guard=window.SenseCMSUnsavedGuard;
    if(guard?.dirty()&&!guard.isBypassing()){
        event.preventDefault();event.stopImmediatePropagation();
        const submitter=event.submitter;
        guard.confirmLeave(()=>form.requestSubmit(submitter));
    }
},true);
document.addEventListener('sensecms:content-ready',()=>{
    const state=document.querySelector('[data-workspace-id]'),picker=document.querySelector('[data-workspace-switch]');
    if(state&&picker){picker.querySelector('[name="facility_id"]').value=state.dataset.workspaceId;picker.querySelector('[name="return"]').value=location.pathname+location.search;}
});
