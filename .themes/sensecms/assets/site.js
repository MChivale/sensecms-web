'use strict';
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
        market.querySelector('[data-market-count]').textContent = count + (count === 1 ? ' result' : ' results');
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
    const identity = card => {
        const parts = new URL(card.querySelector('[data-market-open]').href).pathname.split('/');
        return parts[3] + ':' + parts[4];
    };
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
            button.textContent = 'Download package';
            button.setAttribute('aria-label', 'Download package: ' + card.querySelector('h2').textContent);
            card.querySelector('.market-availability').textContent = 'Signed ' + offer.channel + ' package · v' + offer.version;
        });
        const note = market.querySelector('.market-note p');
        if (note && Object.keys(offers).length) note.textContent = 'Only releases marked as available can be downloaded. Free packages require a CMS licence; paid packages require their own licence. Development packages are not Stable releases.';
    };
    refreshOffers().catch(() => {});
    let opener;
    const openDetails = (card, control, availability = false) => {
        if (dialog.open) return;
        opener = control;
        selected = identity(card);
        downloadForm.reset(); downloadResult.textContent = '';
        const offer = offers[selected];
        downloadForm.hidden = !offer;
        downloadForm.elements.csrf.value = csrf;
        downloadForm.elements.product.value = selected;
        downloadForm.elements.preview.required = offer?.channel !== 'stable';
        downloadForm.querySelector('.market-preview-consent').hidden = offer?.channel === 'stable';
        dialog.querySelector('.market-download-unavailable').hidden = Boolean(offer);
        dialog.querySelector('.market-dialog-availability p:last-child').textContent = offer ? 'Signed ' + offer.channel + ' release v' + offer.version + '. Verify the licence below to download this package.' : 'This package is not available yet. No licence key is needed to browse this catalogue.';
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
            ? 'This free package requires a valid Sense CMS system licence.'
            : card.dataset.category === 'system' ? 'Core installation requires a valid Sense CMS system licence.' : 'This paid package requires its own product licence. A CMS licence alone does not authorise the download.';
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
        downloadResult.textContent = 'Verifying your licence…';
        const body = new FormData(downloadForm);
        downloadForm.elements.license_key.value = '';
        try {
            const response = await fetch('/packages/download',{method:'POST',body,credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/zip, application/json'}});
            body.delete('license_key');
            if (!response.ok) {
                const error = await response.json();
                if (response.status === 419) { await refreshOffers(); downloadForm.elements.csrf.value = csrf; }
                throw new Error(error.message || 'The download could not be verified.');
            }
            if (!response.headers.get('Content-Type')?.startsWith('application/zip')) throw new Error('The server did not return a package.');
            const bytes = await response.arrayBuffer();
            const checksum = [...new Uint8Array(await crypto.subtle.digest('SHA-256',bytes))].map(value=>value.toString(16).padStart(2,'0')).join('');
            if (bytes.byteLength !== offer.bytes || checksum !== offer.sha256) throw new Error('The downloaded package failed its integrity check.');
            if (controller.signal.aborted || !dialog.open || selected !== product) return;
            const url = URL.createObjectURL(new Blob([bytes],{type:'application/zip'}));
            const link = document.createElement('a'); link.href = url; link.download = offer.type + '-' + offer.slug + '-' + offer.version + '.zip';
            document.body.append(link); link.click(); link.remove(); setTimeout(()=>URL.revokeObjectURL(url),60000);
            downloadResult.textContent = 'Licence verified. Package integrity checked; download handed to your browser.';
        } catch (error) {
            if (!controller.signal.aborted && dialog.open && selected === product) downloadResult.textContent = error.message || 'Unable to verify the download. Please try again.';
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
        result.hidden = false; result.textContent = 'Sending your message…'; result.classList.remove('is-error');
        try {
            const response = await fetch(form.action, {method:'POST', body:new FormData(form), headers:{Accept:'application/json'}, credentials:'same-origin'});
            const data = await response.json();
            result.textContent = data.message || 'Unable to send your message. Please try again later.';
            result.classList.toggle('is-error', !data.ok);
            if (response.ok && data.ok) form.reset();
            refresh();
        } catch {
            result.textContent = 'We could not confirm the result. Your message may have been received. Please contact us by email before sending it again.';
            result.classList.add('is-error'); refresh();
        } finally {
            form.dataset.sending = 'false'; button.disabled = false; form.removeAttribute('aria-busy'); result.focus();
        }
    });
});
const menu = document.querySelector('.menu-button');
const nav = document.querySelector('#navigation');
function closeMenu() { menu.setAttribute('aria-expanded', 'false'); nav.classList.remove('open'); }
menu.addEventListener('click', () => {
    const open = menu.getAttribute('aria-expanded') !== 'true';
    menu.setAttribute('aria-expanded', String(open)); nav.classList.toggle('open', open);
});
document.addEventListener('keydown', event => { if (event.key === 'Escape' && nav.classList.contains('open')) { closeMenu(); menu.focus(); } });
nav.addEventListener('click', event => { if (event.target.closest('a')) closeMenu(); });
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
