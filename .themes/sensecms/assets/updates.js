(() => {
    'use strict';
    const root = document.querySelector('[data-update-center]');
    if (!root) return;
    const tr = (en, pl) => document.documentElement.lang === 'pl' ? pl : en;
    const list = root.querySelector('[data-stable-releases]');
    fetch('/api/updates/v1/catalog', {credentials: 'omit', headers: {'Accept':'application/json'}})
        .then(async response => {
            if (!response.ok) throw new Error('unavailable');
            const envelope = await response.json();
            const data = JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(envelope.signed_payload), c => c.charCodeAt(0))));
            if (data.product !== 'Sense CMS' || data.channel !== 'stable' || !Array.isArray(data.releases) || data.expires_at * 1000 <= Date.now()) throw new Error('invalid');
            list.replaceChildren();
            if (!data.releases.length) {
                const card = document.createElement('article'); card.className = 'update-release-card';
                const title = document.createElement('h3'); title.textContent = tr('The first Stable release is still ahead.', 'Pierwsze stabilne wydanie jest jeszcze przed nami.');
                const text = document.createElement('p'); text.textContent = tr('No Stable Core release has been published yet. Development builds are not Stable releases. When an accepted release is published, it will appear here and in your CMS update checks.', 'Stabilne wydanie Core nie zostało jeszcze opublikowane. Wersje rozwojowe nie są wydaniami stabilnymi. Po publikacji zatwierdzone wydanie pojawi się tutaj oraz w wynikach sprawdzania aktualizacji CMS.');
                card.append(title, text); list.append(card);
            }
            data.releases.forEach(release => {
                const card = document.createElement('article'); card.className = 'update-release-card';
                const heading = document.createElement('h3'); heading.textContent = 'Sense CMS ' + release.version;
                const details = document.createElement('p'); details.textContent = 'Stable · ' + new Date(release.released_at * 1000).toLocaleDateString() + ' · PHP ' + release.php_min + '+';
                const notes = document.createElement('ul');
                release.notes.forEach(note => { const item = document.createElement('li'); item.textContent = note; notes.append(item); });
                card.append(heading, details, notes); list.append(card);
            });
        }).catch(() => { list.textContent = tr('Release information is temporarily unavailable. Please retry later; this is not confirmation that your CMS is up to date.', 'Informacje o wydaniach są chwilowo niedostępne. Spróbuj ponownie później; ten komunikat nie potwierdza aktualności Twojego CMS.'); });
})();
