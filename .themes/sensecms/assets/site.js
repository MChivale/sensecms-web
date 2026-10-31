'use strict';
const siteText = (en, pl) => document.documentElement.lang === 'pl' ? pl : en;
const textScaleKey = 'sensecms:text-scale';
const normaliseTextScale = value => {
    const number = Number(value);
    return Number.isFinite(number) ? Math.min(200, Math.max(100, Math.round(number / 10) * 10)) : 100;
};
let textScale = 100;
try { textScale = normaliseTextScale(localStorage.getItem(textScaleKey)); } catch {}
const applyTextScale = value => {
    textScale = normaliseTextScale(value);
    document.documentElement.style.fontSize = textScale === 100 ? '' : textScale + '%';
    document.documentElement.toggleAttribute('data-text-scale-large', textScale >= 130);
    const range = document.querySelector('[data-text-range]');
    const output = document.querySelector('[data-text-scale]');
    const decrease = document.querySelector('[data-text-decrease]');
    const increase = document.querySelector('[data-text-increase]');
    if (range) range.value = String(textScale);
    if (output) output.textContent = textScale + '%';
    if (decrease) decrease.disabled = textScale <= 100;
    if (increase) increase.disabled = textScale >= 200;
};
const saveTextScale = value => {
    applyTextScale(value);
    try { if (textScale === 100) localStorage.removeItem(textScaleKey); else localStorage.setItem(textScaleKey, String(textScale)); } catch {}
};
applyTextScale(textScale);
const siteHeader = document.querySelector('.header');
if (siteHeader) {
    let headerFrame = 0;
    const updateHeader = () => { headerFrame = 0; siteHeader.classList.toggle('is-compact', window.scrollY > 36); };
    window.addEventListener('scroll', () => { if (!headerFrame) headerFrame = requestAnimationFrame(updateHeader); }, {passive: true});
    updateHeader();
}
document.querySelectorAll('[data-marketplace]').forEach(market => {
    const form = market.querySelector('[data-market-filters]');
    const cards = [...market.querySelectorAll('[data-market-item]')];
    const empty = market.querySelector('[data-market-empty]');
    const chips = [...market.querySelectorAll('[data-market-price]')];
    const categories = [...market.querySelectorAll('[data-market-category]')];
    const text = cards.map(card => card.textContent.toLocaleLowerCase());
    const update = () => {
        const query = form.elements.search.value.trim().toLocaleLowerCase();
        const category = form.elements.category.value;
        const pricing = form.elements.pricing.value;
        let count = 0;
        cards.forEach((card, i) => {
            const visible = text[i].includes(query) && (category === 'all' || card.dataset.category === category) && (pricing === 'all' || card.dataset.pricing === pricing);
            card.hidden = !visible;
            if (visible) count++;
        });
        market.querySelector('[data-market-count]').textContent = count + siteText(count === 1 ? ' result' : ' results', count === 1 ? ' wynik' : ' wyników');
        empty.hidden = count !== 0;
        chips.forEach(chip => chip.setAttribute('aria-pressed', String(chip.dataset.marketPrice === pricing)));
        categories.forEach(chip => chip.setAttribute('aria-pressed', String(chip.dataset.marketCategory === category)));
    };
    form.addEventListener('submit', event => event.preventDefault());
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    form.addEventListener('reset', event => {
        event.preventDefault();
        form.elements.search.value = '';
        form.elements.category.value = 'all';
        form.elements.pricing.value = 'all';
        update();
    });
    chips.forEach(chip => chip.addEventListener('click', () => { form.elements.pricing.value = chip.dataset.marketPrice; update(); }));
    categories.forEach(chip => chip.addEventListener('click', () => { form.elements.category.value = chip.dataset.marketCategory; update(); }));
    form.hidden = false;
    update();
    const dialog = market.querySelector('[data-market-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const downloadForm = dialog.querySelector('[data-download-form]');
    const downloadResult = dialog.querySelector('[data-download-result]');
    let offers = {}, csrf = '', pending, selected = '';
    const identity = card => card.dataset.product;
    const refreshOffers = async () => {
        const response = await fetch('/packages/download', {headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'});
        if (!response.ok) return;
        const data = await response.json();
        if (!data.ok || typeof data.csrf !== 'string' || !data.products) return;
        offers = data.products; csrf = data.csrf;
        cards.forEach(card => {
            const offer = offers[identity(card)];
            if (!offer) return;
            const button = card.querySelector('[data-market-status]');
            button.textContent = siteText('Download package', 'Pobierz pakiet');
            button.setAttribute('aria-label', siteText('Download package: ', 'Pobierz pakiet: ') + card.querySelector('h2').textContent);
            card.querySelector('.market-availability').textContent = siteText('Signed ', 'Podpisany pakiet ') + offer.channel + ' · v' + offer.version;
        });
        const note = market.querySelector('.market-note p');
        if (note && Object.keys(offers).length) note.textContent = siteText('Only releases marked as available can be downloaded. Free packages require a CMS licence; paid packages require their own licence. Development packages are not Stable releases.', 'Pobrać można wyłącznie wydania oznaczone jako dostępne. Bezpłatne pakiety wymagają licencji CMS, a płatne — własnej licencji. Pakiety rozwojowe nie są wydaniami stabilnymi.');
    };
    refreshOffers().catch(() => {});
    let opener;
    const openDetails = (card, control, availability = false) => {
        if (dialog.open) return;
        opener = control;
        selected = identity(card);
        dialog.querySelector('[data-core-scope]').hidden = selected !== 'system:sensecms';
        dialog.querySelector('[data-core-enquiry]').hidden = selected !== 'system:sensecms';
        downloadForm.reset(); downloadResult.textContent = '';
        const offer = offers[selected];
        downloadForm.hidden = !offer;
        downloadForm.elements.csrf.value = csrf;
        downloadForm.elements.product.value = selected;
        downloadForm.elements.preview.required = offer?.channel !== 'stable';
        downloadForm.querySelector('.market-preview-consent').hidden = offer?.channel === 'stable';
        dialog.querySelector('.market-download-unavailable').hidden = Boolean(offer);
        dialog.querySelector('.market-dialog-availability p:last-child').textContent = offer ? siteText('Signed ', 'Podpisane wydanie ') + offer.channel + ' v' + offer.version + siteText('. Verify the licence below to download this package.', '. Zweryfikuj licencję poniżej, aby pobrać pakiet.') : siteText('This package is not available yet. No licence key is needed to browse this catalogue.', 'Ten pakiet nie jest jeszcze dostępny. Przeglądanie katalogu nie wymaga klucza licencyjnego.');
        const copy = (target, source) => { dialog.querySelector(target).textContent = card.querySelector(source).textContent; };
        copy('#market-dialog-title', 'h2');
        copy('#market-dialog-description', '.market-description');
        copy('[data-dialog-type]', '.market-type');
        copy('[data-dialog-category]', '.market-type');
        copy('[data-dialog-price]', '.market-price');
        copy('[data-dialog-license]', '.market-license');
        copy('[data-dialog-availability]', '.market-availability');
        dialog.querySelector('[data-dialog-icon]').replaceChildren(card.querySelector('.market-icon').cloneNode(true));
        dialog.querySelector('[data-dialog-entitlement]').textContent = card.dataset.pricing === 'free'
            ? siteText('This free package requires a valid Sense CMS system licence.', 'Ten bezpłatny pakiet wymaga ważnej licencji systemowej Sense CMS.')
            : card.dataset.category === 'system' ? siteText('Core installation requires a valid Sense CMS system licence.', 'Instalacja Core wymaga ważnej licencji systemowej Sense CMS.') : siteText('This paid package requires its own product licence. A CMS licence alone does not authorise the download.', 'Ten płatny pakiet wymaga własnej licencji. Sama licencja CMS nie uprawnia do jego pobrania.');
        dialog.showModal();
        document.documentElement.classList.add('market-dialog-open');
        if (availability) dialog.querySelector('.market-dialog-availability').focus();
    };
    cards.forEach(card => {
        card.classList.add('is-interactive');
        card.querySelectorAll('[data-market-open]').forEach(link => {
            link.setAttribute('role', 'button');
            link.setAttribute('aria-haspopup', 'dialog');
            link.setAttribute('aria-controls', 'market-dialog');
            link.addEventListener('keydown', event => {
                if (event.key === ' ') { event.preventDefault(); openDetails(card, link); }
            });
        });
        const status = card.querySelector('[data-market-status]');
        status.hidden = false;
        status.setAttribute('aria-haspopup', 'dialog');
        card.addEventListener('click', event => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || String(window.getSelection())) return;
            const control = event.target.closest('a,button');
            if (control && !control.matches('[data-market-open],[data-market-status]')) return;
            event.preventDefault();
            openDetails(card, control || card.querySelector('[data-market-open]'), Boolean(control?.matches('[data-market-status]')));
        });
    });
    dialog.id = 'market-dialog';
    dialog.querySelectorAll('[data-market-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => {
        const box = dialog.getBoundingClientRect();
        if (event.target === dialog && (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom)) dialog.close();
    });
    dialog.addEventListener('close', () => {
        pending?.abort(); pending = undefined; selected = '';
        downloadForm.reset(); downloadResult.textContent = '';
        document.documentElement.classList.remove('market-dialog-open');
        opener?.focus({preventScroll:true});
    });
    downloadForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (pending || !downloadForm.reportValidity() || !offers[selected]) return;
        const product = selected, offer = offers[product], controller = new AbortController();
        const submit = downloadForm.querySelector('[type=submit]');
        pending = controller; submit.disabled = true; downloadForm.setAttribute('aria-busy','true');
        downloadResult.textContent = siteText('Verifying your licence…', 'Weryfikowanie licencji…');
        const body = new FormData(downloadForm);
        downloadForm.elements.license_key.value = '';
        try {
            const response = await fetch('/packages/download',{method:'POST',body,credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/zip, application/json'}});
            body.delete('license_key');
            if (!response.ok) {
                const error = await response.json();
                if (response.status === 419) { await refreshOffers(); downloadForm.elements.csrf.value = csrf; }
                throw new Error(error.message || siteText('The download could not be verified.', 'Nie udało się zweryfikować pobrania.'));
            }
            if (!response.headers.get('Content-Type')?.startsWith('application/zip')) throw new Error(siteText('The server did not return a package.', 'Serwer nie zwrócił pakietu.'));
            const bytes = await response.arrayBuffer();
            const checksum = [...new Uint8Array(await crypto.subtle.digest('SHA-256',bytes))].map(value=>value.toString(16).padStart(2,'0')).join('');
            if (bytes.byteLength !== offer.bytes || checksum !== offer.sha256) throw new Error(siteText('The downloaded package failed its integrity check.', 'Pobrany pakiet nie przeszedł kontroli integralności.'));
            if (controller.signal.aborted || !dialog.open || selected !== product) return;
            const url = URL.createObjectURL(new Blob([bytes],{type:'application/zip'}));
            const link = document.createElement('a'); link.href = url; link.download = offer.type + '-' + offer.slug + '-' + offer.version + '.zip';
            document.body.append(link); link.click(); link.remove(); setTimeout(()=>URL.revokeObjectURL(url),60000);
            downloadResult.textContent = siteText('Licence verified. Package integrity checked; download handed to your browser.', 'Licencja zweryfikowana. Integralność pakietu sprawdzona; pobieranie przekazano do przeglądarki.');
        } catch (error) {
            if (!controller.signal.aborted && dialog.open && selected === product) downloadResult.textContent = error.message || siteText('Unable to verify the download. Please try again.', 'Nie można zweryfikować pobrania. Spróbuj ponownie.');
        } finally {
            body.delete('license_key');
            if (pending === controller) pending = undefined;
            submit.disabled = false; downloadForm.removeAttribute('aria-busy');
        }
    });
});
document.querySelectorAll('[data-contact-form]').forEach(form => {
    const result = form.querySelector('.form-result');
    const image = form.querySelector('[data-captcha-image]');
    const refresh = () => { if (image) { image.src = image.src.split('?')[0] + '?t=' + Date.now(); form.elements.captcha.value = ''; } };
    form.querySelector('[data-captcha-refresh]')?.addEventListener('click', refresh);
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (form.dataset.sending === 'true' || !form.reportValidity()) return;
        const button = form.querySelector('[type="submit"]');
        form.dataset.sending = 'true'; button.disabled = true; form.setAttribute('aria-busy', 'true');
        result.hidden = false; result.textContent = siteText('Sending your message…', 'Wysyłanie wiadomości…'); result.classList.remove('is-error');
        try {
            const response = await fetch(form.action, {method:'POST', body:new FormData(form), headers:{Accept:'application/json'}, credentials:'same-origin'});
            const data = await response.json();
            result.textContent = data.message || siteText('Unable to send your message. Please try again later.', 'Nie można wysłać wiadomości. Spróbuj ponownie później.');
            result.classList.toggle('is-error', !data.ok);
            if (response.ok && data.ok) form.reset();
            refresh();
        } catch {
            result.textContent = siteText('We could not confirm the result. Your message may have been received. Please contact us by email before sending it again.', 'Nie udało się potwierdzić wyniku. Wiadomość mogła zostać dostarczona. Przed ponowną wysyłką skontaktuj się z nami e-mailem.');
            result.classList.add('is-error'); refresh();
        } finally {
            form.dataset.sending = 'false'; button.disabled = false; form.removeAttribute('aria-busy'); result.focus();
        }
    });
});
document.querySelectorAll('.app-presentation').forEach(section => {
    const list = section.querySelector('.app-screen-tabs');
    if (!list) return;
    const tabs = [...list.querySelectorAll('[data-app-tab]')];
    const panels = [...section.querySelectorAll('[data-app-panel]')];
    const mobile = matchMedia('(max-width: 700px)');
    let selected = 0;
    const select = (index, focus = false) => {
        selected = (index + tabs.length) % tabs.length;
        list.hidden = !mobile.matches;
        if (mobile.matches) list.setAttribute('role', 'tablist'); else list.removeAttribute('role');
        tabs.forEach((tab, i) => {
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-selected', String(i === selected));
            tab.tabIndex = i === selected ? 0 : -1;
        });
        panels.forEach((panel, i) => {
            panel.hidden = mobile.matches && i !== selected;
            if (mobile.matches) { panel.setAttribute('role', 'tabpanel'); panel.setAttribute('aria-labelledby', tabs[i].id); }
            else { panel.removeAttribute('role'); panel.removeAttribute('aria-labelledby'); }
        });
        if (focus) tabs[selected].focus();
    };
    tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => select(i));
        tab.addEventListener('keydown', event => {
            const next = {ArrowRight:i+1, ArrowLeft:i-1, Home:0, End:tabs.length-1}[event.key];
            if (next === undefined) return;
            event.preventDefault(); select(next, true);
        });
    });
    mobile.addEventListener('change', () => select(selected));
    select(0);
});
const gallery = document.querySelector('[data-screenshot-gallery]');
if (gallery && typeof gallery.showModal === 'function') {
    const links = [...document.querySelectorAll('.app-phone, .app-desktop figure a, .workspace-tour-grid figure a')];
    const image = gallery.querySelector('[data-gallery-image]');
    const stage = gallery.querySelector('.gallery-image-stage');
    const title = gallery.querySelector('#gallery-title');
    const caption = gallery.querySelector('[data-gallery-caption]');
    const position = gallery.querySelector('[data-gallery-position]');
    const zoom = gallery.querySelector('[data-gallery-zoom]');
    const error = gallery.querySelector('[data-gallery-error]');
    let index = 0, opener;
    const fit = () => {
        stage.classList.remove('is-zoomed'); zoom.setAttribute('aria-pressed', 'false');
        zoom.textContent = siteText('Actual size', 'Rzeczywisty rozmiar'); stage.scrollTop = 0; stage.scrollLeft = 0;
    };
    const show = next => {
        index = (next + links.length) % links.length;
        const link = links[index], source = link.querySelector('img');
        fit(); error.hidden = true; image.hidden = false; stage.setAttribute('aria-busy', 'true');
        title.textContent = link.closest('figure').querySelector('h3')?.textContent || siteText('APP Sense CMS — desktop', 'APP Sense CMS — komputer');
        caption.textContent = source.alt;
        position.textContent = (index + 1) + ' / ' + links.length;
        image.alt = source.alt; image.src = link.href;
    };
    image.addEventListener('load', () => stage.removeAttribute('aria-busy'));
    image.addEventListener('error', () => { stage.removeAttribute('aria-busy'); image.hidden = true; error.hidden = false; });
    links.forEach((link, i) => link.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        event.preventDefault(); opener = link; show(i); gallery.showModal();
    }));
    gallery.querySelector('[data-gallery-close]').addEventListener('click', () => gallery.close());
    gallery.querySelector('[data-gallery-prev]').addEventListener('click', () => show(index - 1));
    gallery.querySelector('[data-gallery-next]').addEventListener('click', () => show(index + 1));
    zoom.addEventListener('click', () => {
        const enlarged = stage.classList.toggle('is-zoomed');
        zoom.setAttribute('aria-pressed', String(enlarged));
        zoom.textContent = enlarged ? siteText('Fit to screen', 'Dopasuj do ekranu') : siteText('Actual size', 'Rzeczywisty rozmiar');
    });
    gallery.addEventListener('keydown', event => {
        if (event.key === 'Tab') {
            const controls = [...gallery.querySelectorAll('button:not(:disabled), [tabindex="0"]')];
            const first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            return;
        }
        if (event.key === 'Escape') { event.stopPropagation(); return; }
        const next = {ArrowRight:index+1, ArrowLeft:index-1, Home:0, End:links.length-1}[event.key];
        if (next === undefined || stage.classList.contains('is-zoomed')) return;
        event.preventDefault(); show(next);
    });
    gallery.addEventListener('click', event => {
        if (event.target !== gallery) return;
        const rect = gallery.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) gallery.close();
    });
    gallery.addEventListener('close', () => {
        fit();
        const panel = opener?.closest('[data-app-panel]');
        if (panel?.hidden) document.querySelector('#app-tab-' + panel.dataset.appPanel)?.click();
        opener?.focus({preventScroll:true});
    });
}
const menu = document.querySelector('.menu-button');
const platformShortcuts = document.querySelector('[data-platform-shortcuts]');
if (platformShortcuts) {
    const links = [...platformShortcuts.querySelectorAll('a')];
    const sections = links.map(link => document.querySelector(link.getAttribute('href')));
    let scheduled = false;
    const update = () => {
        scheduled = false;
        const offset = platformShortcuts.getBoundingClientRect().bottom + 40;
        let current = -1;
        sections.forEach((section, i) => { if (section && section.getBoundingClientRect().top <= Math.max(160, offset)) current = i; });
        links.forEach((link, i) => { if (i === current) link.setAttribute('aria-current', 'location'); else link.removeAttribute('aria-current'); });
    };
    window.addEventListener('scroll', () => { if (!scheduled) { scheduled = true; requestAnimationFrame(update); } }, {passive:true});
    window.addEventListener('resize', update);
    update();
}
const nav = document.querySelector('#navigation');
const languageMenus = [...document.querySelectorAll('.language-menu')];
const accessibilityMenu = document.querySelector('.accessibility-menu');
const accessibilityToggle = document.querySelector('[data-accessibility-toggle]');
const accessibilityPanel = document.querySelector('[data-accessibility-panel]');
const closeAccessibility = focus => {
    if (!accessibilityPanel || accessibilityPanel.hidden) return;
    accessibilityPanel.hidden = true; accessibilityToggle?.setAttribute('aria-expanded', 'false');
    if (focus) accessibilityToggle?.focus();
};
accessibilityToggle?.addEventListener('click', () => {
    const open = accessibilityPanel?.hidden ?? false;
    if (!accessibilityPanel) return;
    accessibilityPanel.hidden = !open; accessibilityToggle.setAttribute('aria-expanded', String(open));
    if (open) { languageMenus.forEach(details => details.open = false); accessibilityPanel.querySelector('[data-accessibility-close]')?.focus(); }
});
accessibilityPanel?.querySelector('[data-accessibility-close]')?.addEventListener('click', () => closeAccessibility(true));
accessibilityPanel?.querySelector('[data-text-decrease]')?.addEventListener('click', () => saveTextScale(textScale - 10));
accessibilityPanel?.querySelector('[data-text-increase]')?.addEventListener('click', () => saveTextScale(textScale + 10));
accessibilityPanel?.querySelector('[data-text-reset]')?.addEventListener('click', () => saveTextScale(100));
accessibilityPanel?.querySelector('[data-text-range]')?.addEventListener('input', event => saveTextScale(event.target.value));
applyTextScale(textScale);
const closeMenu = () => { menu?.setAttribute('aria-expanded', 'false'); nav?.classList.remove('open'); };
menu?.addEventListener('click', () => {
    const open = menu.getAttribute('aria-expanded') !== 'true';
    menu.setAttribute('aria-expanded', String(open)); nav?.classList.toggle('open', open);
});
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (nav?.classList.contains('open')) { closeMenu(); menu?.focus(); }
    languageMenus.forEach(details => { if (details.open) { details.open = false; details.querySelector('summary')?.focus(); } });
    closeAccessibility(true);
});
document.addEventListener('click', event => {
    languageMenus.forEach(details => { if (!details.contains(event.target)) details.open = false; });
    if (accessibilityMenu && !accessibilityMenu.contains(event.target)) closeAccessibility(false);
});
nav?.addEventListener('click', event => { if (event.target.closest('a')) closeMenu(); });
matchMedia('(min-width: 901px)').addEventListener('change', closeMenu);
const topButton = document.querySelector('[data-back-to-top]');
if (topButton) {
    const updateTopButton = () => {
        const visible = window.scrollY > 480;
        topButton.classList.toggle('is-visible', visible);
        topButton.tabIndex = visible ? 0 : -1;
        topButton.setAttribute('aria-hidden', String(!visible));
    };
    window.addEventListener('scroll', updateTopButton, {passive: true});
    updateTopButton();
    topButton.addEventListener('click', () => {
        document.querySelector('#main').focus({preventScroll: true});
        window.scrollTo({top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'});
    });
}
document.addEventListener('click',event=>{const trigger=event.target.closest('[data-post-player]'),dialog=document.querySelector('[data-post-player-dialog]');if(trigger&&dialog){const stage=dialog.querySelector('[data-post-player-stage]'),kind=trigger.dataset.postPlayer,media=document.createElement(kind);media.controls=true;media.preload='metadata';media.src=trigger.dataset.src||'';if(kind==='video'){media.playsInline=true;if(trigger.dataset.poster)media.poster=trigger.dataset.poster}dialog.classList.toggle('is-audio',kind==='audio');stage.replaceChildren(media);dialog.showModal();media.play().catch(()=>{});return}if(event.target.closest('[data-post-player-close]')){const dialog=event.target.closest('dialog');dialog?.querySelector('audio,video')?.pause();dialog?.close()}if(event.target===dialog){dialog.querySelector('audio,video')?.pause();dialog.close()}});
document.querySelectorAll('[data-core-slider]').forEach(slider=>{const slides=[...slider.querySelectorAll('[data-slide]')],dots=[...slider.querySelectorAll('[data-slider-dot]')];if(slides.length<2)return;let index=0,timer=0,paused=false;const reduced=matchMedia('(prefers-reduced-motion: reduce)').matches,loop=slider.dataset.loop==='1',interval=Math.max(3,Math.min(15,Number(slider.dataset.interval)||6))*1000;const show=next=>{if(next<0)next=loop?slides.length-1:0;if(next>=slides.length)next=loop?0:slides.length-1;index=next;slides.forEach((slide,i)=>{const active=i===index;slide.classList.toggle('is-active',active);slide.setAttribute('aria-hidden',String(!active));const video=slide.querySelector('video');if(video){if(active&&slider.dataset.autoplay==='1'&&!reduced)video.play().catch(()=>{});else video.pause()}});dots.forEach((dot,i)=>{dot.classList.toggle('is-active',i===index);if(i===index)dot.setAttribute('aria-current','true');else dot.removeAttribute('aria-current')})};const stop=()=>{clearInterval(timer);timer=0},start=()=>{stop();if(slider.dataset.autoplay==='1'&&!reduced&&!paused)timer=setInterval(()=>show(index+1),interval)};slider.querySelector('[data-slider-prev]')?.addEventListener('click',()=>{show(index-1);start()});slider.querySelector('[data-slider-next]')?.addEventListener('click',()=>{show(index+1);start()});dots.forEach(dot=>dot.addEventListener('click',()=>{show(Number(dot.dataset.sliderDot));start()}));if(slider.dataset.pauseHover==='1'){slider.addEventListener('mouseenter',()=>{paused=true;stop()});slider.addEventListener('mouseleave',()=>{paused=false;start()});slider.addEventListener('focusin',()=>{paused=true;stop()});slider.addEventListener('focusout',event=>{if(!slider.contains(event.relatedTarget)){paused=false;start()}})}document.addEventListener('visibilitychange',()=>document.hidden?stop():start());show(0);start()});
