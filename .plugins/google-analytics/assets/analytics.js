'use strict';
(() => {
    const script = document.querySelector('[data-sense-ga]');
    const id = script?.dataset.measurementId;
    if (!/^G-[A-Z0-9]{6,20}$/.test(id || '') || document.querySelector('.sense-ga')) return;
    const key = 'sensecms:analytics:' + id, duration = 180 * 86400000;
    const pl = document.documentElement.lang.toLowerCase().startsWith('pl');
    const copy = pl ? ['Ustawienia analityki','Google Analytics pomoże nam zrozumieć sposób korzystania ze strony. Włączymy je wyłącznie za Twoją zgodą.','Odrzuć','Zgadzam się','Ustawienia analityki'] : ['Analytics preferences','Google Analytics helps us understand how this website is used. We load it only with your consent.','Decline','Accept analytics','Analytics settings'];
    let loaded = false;
    const read = () => {
        try { const value = JSON.parse(localStorage.getItem(key)); return value && ['granted','denied'].includes(value.choice) && value.until > Date.now() && value.until <= Date.now() + duration ? value.choice : ''; }
        catch { return ''; }
    };
    const load = () => {
        if (loaded) return;
        loaded = true; window['ga-disable-' + id] = false;
        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
        window.gtag('consent','default',{analytics_storage:'granted',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});
        window.gtag('js',new Date());
        const location = window.location.origin + window.location.pathname;
        window.gtag('config',id,{send_page_view:false,allow_google_signals:false,allow_ad_personalization_signals:false,page_location:location,cookie_prefix:'sensega'});
        window.gtag('event','page_view',{send_to:id,page_location:location,page_referrer:document.referrer.split(/[?#]/)[0]});
        const tag = document.createElement('script'); tag.async = true; tag.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
        document.head.append(tag);
    };
    const box = document.createElement('section'), toggle = document.createElement('button');
    box.className = 'sense-ga'; box.setAttribute('role','region'); box.setAttribute('aria-label',copy[0]);
    const title = document.createElement('strong'), description = document.createElement('p'), actions = document.createElement('div');
    title.textContent = copy[0]; description.textContent = copy[1]; actions.className = 'sense-ga-actions';
    const reject = document.createElement('button'), accept = document.createElement('button');
    reject.type = accept.type = 'button'; reject.textContent = copy[2]; accept.textContent = copy[3]; accept.setAttribute('data-ga-accept','');
    actions.append(reject,accept); box.append(title,description,actions);
    toggle.type = 'button'; toggle.className = 'sense-ga-toggle'; toggle.textContent = copy[4];
    const hide = () => { box.hidden = true; toggle.hidden = false; };
    const show = () => { box.hidden = false; toggle.hidden = true; };
    const clearCookies = () => {
        for (const cookie of document.cookie.split(';')) {
            const name = cookie.split('=')[0].trim();
            if (!/^sensega_ga(?:_|$)/.test(name)) continue;
            const parts = window.location.hostname.split('.');
            document.cookie = name + '=; Max-Age=0; path=/; SameSite=Lax';
            while (parts.length > 1) { document.cookie = name + '=; Max-Age=0; path=/; domain=' + parts.join('.') + '; SameSite=Lax'; parts.shift(); }
        }
    };
    const choose = choice => {
        try { localStorage.setItem(key,JSON.stringify({choice,until:Date.now()+duration})); } catch { /* Current-page consent only. */ }
        hide(); toggle.focus();
        if (choice === 'granted') load();
        else { window['ga-disable-' + id] = true; clearCookies(); if (loaded) window.location.reload(); }
    };
    reject.addEventListener('click',()=>choose('denied')); accept.addEventListener('click',()=>choose('granted'));
    toggle.addEventListener('click',()=>{show();reject.focus();});
    window.addEventListener('storage',event=>{if(event.key===key && loaded && read()!=='granted'){window['ga-disable-'+id]=true;window.location.reload();}});
    document.body.append(box,toggle);
    const choice = read();
    if (choice === 'granted') { load(); hide(); } else if (choice === 'denied') hide(); else show();
})();
