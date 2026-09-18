'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const providers = ['facebook', 'x', 'linkedin', 'bluesky', 'mastodon'];

for (const provider of providers) {
    const events = {};
    let closed = false;
    const outcome = `${provider}OauthOutcome`;
    const context = {
        window: {
            name: `sensecms-${provider}-oauth`,
            opener: null,
            close() { closed = true; },
            SenseCMSUI: {toast() {}},
        },
        document: {
            body: {dataset: {sensecmsFlash: 'Connected'}, classList: {add() {}, remove() {}}},
            querySelector(selector) {
                return selector === `[data-${provider}-page]` ? {dataset: {[outcome]: 'success'}} : null;
            },
            addEventListener(event, callback) { events[event] = callback; },
        },
        location: {origin: 'https://www.sensecms.com', reload() {}},
        sessionStorage: {getItem() { return null; }, setItem() {}, removeItem() {}},
        screen: {availWidth: 1280, availHeight: 900},
        setInterval() { return 1; },
        clearInterval() {},
        URL,
        FormData,
        fetch,
    };
    vm.runInNewContext(
        fs.readFileSync(path.join(root, `.plugins/${provider}-publisher/assets/${provider}.js`), 'utf8'),
        context,
        {filename: `${provider}.js`},
    );
    if (typeof events.DOMContentLoaded !== 'function') throw new Error(`${provider}: missing boot handler`);
    events.DOMContentLoaded();
    if (!closed) throw new Error(`${provider}: provider-isolated OAuth popup remained open`);
    console.log(`PASS ${provider} OAuth popup closes without window.opener`);
}
