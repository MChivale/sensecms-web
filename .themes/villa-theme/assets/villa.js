(() => {
    const polish = document.documentElement.lang.toLowerCase().startsWith('pl');
    const copy = polish ? {
        sending: 'Wysyłamy zapytanie…',
        failed: 'Nie udało się wysłać zapytania. Spróbuj ponownie.',
        uncertain: 'Nie udało się potwierdzić wysłania. Skontaktuj się z nami telefonicznie przed ponowną wysyłką.',
        invalid: 'Sprawdź dane formularza i kod zabezpieczający, a następnie spróbuj ponownie.',
        session: 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.',
        rate: 'Wysłano zbyt wiele prób. Spróbuj ponownie później.',
    } : {
        sending: 'Sending your enquiry…',
        failed: 'We could not send your enquiry. Please try again.',
        uncertain: 'We could not confirm delivery. Please call us before sending again.',
    };
    const toggle = document.querySelector('[data-menu-toggle]');
    const navigation = document.querySelector('[data-navigation]');
    const closeMenu = (restoreFocus = false) => {
        navigation?.classList.remove('is-open');
        toggle?.setAttribute('aria-expanded', 'false');
        if (restoreFocus) toggle?.focus();
    };
    toggle?.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', String(open));
        navigation?.classList.toggle('is-open', open);
    });
    navigation?.querySelectorAll('a').forEach(link => link.addEventListener('click', () => closeMenu()));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && toggle?.getAttribute('aria-expanded') === 'true') closeMenu(true);
    });
    document.addEventListener('click', event => {
        if (!event.target.closest('.villa-header')) closeMenu();
    });
    window.matchMedia('(min-width: 1401px)').addEventListener('change', () => closeMenu());

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    document.querySelectorAll('[data-room-explorer]').forEach(explorer => {
        const options = [...explorer.querySelectorAll('[data-room-option]')];
        const panels = [...explorer.querySelectorAll('[data-room-panel]')];
        const entries = options.map(option => ({option, panel: panels.find(panel => '#' + panel.id === option.getAttribute('href'))}));
        if (!entries.length || entries.some(entry => !entry.panel)) return;
        let active = -1;
        let animation;
        const select = (index, interactive = false) => {
            if (index === active) return;
            animation?.cancel();
            const panel = entries[index].panel;
            if (interactive) panel.querySelectorAll('img[loading="lazy"]').forEach(image => { image.loading = 'eager'; });
            entries.forEach((entry, i) => {
                entry.panel.hidden = i !== index;
                if (i === index) entry.option.setAttribute('aria-current', 'true');
                else entry.option.removeAttribute('aria-current');
            });
            active = index;
            if (interactive && !reducedMotion.matches && typeof panel.animate === 'function') {
                animation = panel.animate([
                    {opacity: 0, transform: 'translateY(6px)'},
                    {opacity: 1, transform: 'translateY(0)'},
                ], {duration: 320, easing: 'cubic-bezier(.2,.65,.3,1)'});
            }
        };
        entries.forEach((entry, index) => {
            entry.option.setAttribute('aria-controls', entry.panel.id);
            entry.option.addEventListener('click', event => {
                if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                event.preventDefault();
                select(index, true);
            });
            entry.option.addEventListener('keydown', event => {
                const keys = ['ArrowDown', 'ArrowRight', 'ArrowUp', 'ArrowLeft', 'Home', 'End'];
                if (!keys.includes(event.key)) return;
                event.preventDefault();
                let next = index;
                if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = entries.length - 1;
                else next = (index + (['ArrowDown', 'ArrowRight'].includes(event.key) ? 1 : -1) + entries.length) % entries.length;
                select(next, true);
                entries[next].option.focus();
            });
        });
        const selected = entries.findIndex(entry => entry.option.hasAttribute('data-selected'));
        const hashSelected = entries.findIndex(entry => '#' + entry.panel.id === window.location.hash);
        select(hashSelected >= 0 ? hashSelected : selected >= 0 ? selected : entries.length - 1);
        explorer.classList.add('is-enhanced');
    });

    document.querySelectorAll('[data-location-slider]').forEach(slider => {
        const slides = [...slider.querySelectorAll('[data-location-slide]')];
        const controls = slider.querySelector('[data-slider-controls]');
        const count = slider.querySelector('[data-location-count]');
        if (slides.length < 2 || !controls || !count) return;
        let index = 0, startX = null, timer, interacting = false;
        const show = (next, animate = false, announce = false) => {
            index = (next + slides.length) % slides.length;
            slides.forEach((slide, i) => { slide.hidden = i !== index; });
            [index, (index + 1) % slides.length].forEach(i => { const image = slides[i].querySelector('img'); if (image) image.loading = 'eager'; });
            if (announce) count.textContent = (polish ? 'Zdjęcie ' : 'Photo ') + (index + 1) + ' / ' + slides.length;
            if (animate && !reducedMotion.matches && slides[index].animate) slides[index].animate([{opacity: .55}, {opacity: 1}], {duration: 350, easing: 'ease-out'});
        };
        const schedule = () => {
            clearInterval(timer);
            if (!interacting && !document.hidden && !reducedMotion.matches) timer = setInterval(() => show(index + 1, true), 3000);
        };
        const select = next => { show(next, true, true); schedule(); };
        slider.querySelector('[data-location-prev]').addEventListener('click', () => select(index - 1));
        slider.querySelector('[data-location-next]').addEventListener('click', () => select(index + 1));
        slider.addEventListener('mouseenter', () => { interacting = true; schedule(); });
        slider.addEventListener('mouseleave', () => { interacting = slider.contains(document.activeElement); schedule(); });
        slider.addEventListener('focusin', () => { interacting = true; schedule(); });
        slider.addEventListener('focusout', event => { interacting = slider.contains(event.relatedTarget) || slider.matches(':hover'); schedule(); });
        document.addEventListener('visibilitychange', schedule);
        reducedMotion.addEventListener('change', schedule);
        slider.addEventListener('pointerdown', event => { if (event.pointerType === 'touch') startX = event.clientX; });
        slider.addEventListener('pointerup', event => { if (event.pointerType === 'touch' && startX !== null && Math.abs(event.clientX - startX) > 50) select(index + (event.clientX < startX ? 1 : -1)); startX = null; });
        slider.addEventListener('pointercancel', () => { startX = null; });
        show(0); controls.hidden = false; schedule();
    });

    const galleryGroups = [];
    const roomPhotos = [...document.querySelectorAll('[data-room-photo]')];
    const galleryItems = [...document.querySelectorAll('[data-villa-gallery] .villa-gallery-link')];
    if (galleryItems.length) galleryGroups.push(galleryItems);
    document.querySelectorAll('.villa-room-detail').forEach(detail => {
        const container = detail.parentElement;
        const images = container?.querySelectorAll('.villa-room-main-image img, .villa-room-gallery img') || [];
        if (!images.length) return;
        images.forEach(image => {
            if (image.parentElement.tagName === 'A') return;
            const link = document.createElement('a');
            link.href = image.currentSrc || image.src;
            link.className = 'villa-gallery-link';
            link.setAttribute('aria-label', (polish ? 'Powiększ zdjęcie: ' : 'Enlarge photo: ') + image.alt);
            image.before(link); link.append(image);
        });
        galleryGroups.push([...container.querySelectorAll('.villa-gallery-link')]);
    });
    if ((galleryGroups.length || roomPhotos.length) && typeof HTMLDialogElement !== 'undefined' && HTMLDialogElement.prototype.showModal) {
        const dialog = document.createElement('dialog');
        dialog.className = 'villa-lightbox';
        dialog.setAttribute('aria-label', polish ? 'Podgląd zdjęć' : 'Photo viewer');
        dialog.innerHTML = '<div class="villa-lightbox-content"><button type="button" class="villa-lightbox-close" data-close></button><button type="button" class="villa-lightbox-prev" data-prev></button><figure><img alt=""><figcaption aria-live="polite"></figcaption></figure><button type="button" class="villa-lightbox-next" data-next></button></div>';
        const close = dialog.querySelector('[data-close]'), prev = dialog.querySelector('[data-prev]'), next = dialog.querySelector('[data-next]');
        const image = dialog.querySelector('img'), caption = dialog.querySelector('figcaption');
        close.textContent = '×'; prev.textContent = '‹'; next.textContent = '›';
        close.setAttribute('aria-label', polish ? 'Zamknij podgląd' : 'Close viewer');
        prev.setAttribute('aria-label', polish ? 'Poprzednie zdjęcie' : 'Previous photo');
        next.setAttribute('aria-label', polish ? 'Następne zdjęcie' : 'Next photo');
        document.body.append(dialog);
        let links = [], index = 0, trigger, startX = null, roomRequest = 0;
        const roomCache = new Map();
        const show = i => {
            index = (i + links.length) % links.length;
            const source = links[index].querySelector('img');
            image.src = links[index].href; image.alt = source?.alt || '';
            const title = links[index].querySelector('.villa-gallery-caption strong')?.textContent || source?.alt || '';
            caption.textContent = title + (title ? ' · ' : '') + (index + 1) + ' / ' + links.length;
            prev.hidden = next.hidden = links.length < 2;
        };
        galleryGroups.forEach(items => {
            items.forEach((link, i) => link.addEventListener('click', event => {
                if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                event.preventDefault(); links = items; trigger = link; show(i);
                dialog.showModal(); document.documentElement.classList.add('villa-lightbox-open'); close.focus();
            }));
        });
        roomPhotos.forEach(link => link.addEventListener('click', async event => {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const target = new URL(link.href, window.location.href);
            if (target.origin !== window.location.origin) return;
            event.preventDefault();
            const request = ++roomRequest;
            link.setAttribute('aria-busy', 'true');
            try {
                let items = roomCache.get(target.href);
                if (!items) {
                    const response = await fetch(target.href, {credentials: 'same-origin', headers: {Accept: 'text/html'}});
                    if (!response.ok) throw new Error('Room page unavailable');
                    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                    const photos = [...page.querySelectorAll('.villa-room-main-image img, .villa-room-gallery img')];
                    const seen = new Set();
                    items = photos.flatMap(photo => {
                        const src = photo.getAttribute('src');
                        if (!src) return [];
                        const url = new URL(src, target);
                        if (!['https:', 'http:'].includes(url.protocol) || (url.protocol !== 'https:' && url.origin !== window.location.origin) || seen.has(url.href)) return [];
                        seen.add(url.href);
                        const item = document.createElement('a'), img = document.createElement('img');
                        item.href = url.href; img.alt = photo.alt; item.append(img); return [item];
                    });
                    if (!items.length) throw new Error('Room photos unavailable');
                    roomCache.set(target.href, items);
                }
                if (request !== roomRequest) return;
                const selected = items.findIndex(item => item.href === link.querySelector('img')?.src);
                links = selected > 0 ? [...items.slice(selected), ...items.slice(0, selected)] : items;
                trigger = link; show(0); dialog.showModal();
                document.documentElement.classList.add('villa-lightbox-open'); close.focus();
            } catch {
                if (request === roomRequest) window.location.assign(target.href);
            } finally {
                link.removeAttribute('aria-busy');
            }
        }));
        close.addEventListener('click', () => dialog.close());
        prev.addEventListener('click', () => show(index - 1)); next.addEventListener('click', () => show(index + 1));
        dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
        dialog.addEventListener('close', () => { document.documentElement.classList.remove('villa-lightbox-open'); image.removeAttribute('src'); trigger?.focus(); });
        dialog.addEventListener('keydown', event => {
            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); show(index + (event.key === 'ArrowRight' ? 1 : -1)); }
        });
        dialog.addEventListener('pointerdown', event => { if (event.pointerType === 'touch') startX = event.clientX; });
        dialog.addEventListener('pointerup', event => { if (event.pointerType === 'touch' && startX !== null && Math.abs(event.clientX - startX) > 45) show(index + (event.clientX < startX ? 1 : -1)); startX = null; });
        dialog.addEventListener('pointercancel', () => { startX = null; });
    }

    document.querySelectorAll('[data-contact-form]').forEach(form => {
        const result = form.querySelector('.form-result');
        const image = form.querySelector('[data-captcha-image]');
        const refresh = () => {
            if (image) image.src = image.src.split('?')[0] + '?t=' + Date.now();
            if (form.elements.captcha) form.elements.captcha.value = '';
        };
        form.querySelector('[data-captcha-refresh]')?.addEventListener('click', refresh);
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (form.dataset.sending === 'true' || !form.reportValidity()) return;
            const button = form.querySelector('[type="submit"]');
            form.dataset.sending = 'true';
            if (button) button.disabled = true;
            form.setAttribute('aria-busy', 'true');
            if (result) { result.hidden = false; result.textContent = copy.sending; result.classList.remove('is-error'); }
            try {
                const response = await fetch(form.action, {method:'POST', body:new FormData(form), headers:{Accept:'application/json'}, credentials:'same-origin'});
                const data = await response.json();
                if (result) {
                    result.textContent = !data.ok && polish ? ({422: copy.invalid, 419: copy.session, 429: copy.rate}[response.status] || copy.failed) : (data.message || copy.failed);
                    result.classList.toggle('is-error', !data.ok);
                }
                if (response.ok && data.ok) form.reset();
                refresh();
            } catch {
                if (result) { result.textContent = copy.uncertain; result.classList.add('is-error'); }
                refresh();
            } finally {
                form.dataset.sending = 'false';
                if (button) button.disabled = false;
                form.removeAttribute('aria-busy');
                result?.focus();
            }
        });
    });
})();
