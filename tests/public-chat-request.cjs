const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../.cms/source/public/assets/public-chat.js'), 'utf8');
const requestSource = source.slice(source.indexOf('    const request ='), source.indexOf('    const setStatus ='));
let clock = 0, pending, timer;
const context = vm.createContext({
    AbortController, labels: {error: 'Request failed'},
    setTimeout(fn, ms) { timer = {fn, at: clock + ms}; return timer; },
    clearTimeout(value) { if (timer === value) timer = null; },
    fetch(path, options) {
        return new Promise((resolve, reject) => {
            pending = {resolve, path, options};
            options.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
        });
    }
});
vm.runInContext(requestSource + '\nthis.request = request;', context);
const advance = ms => { clock += ms; if (timer && timer.at <= clock) timer.fn(); };
(async () => {
    for (const [path, data] of [['/api/ai/chat', 'message=test'], ['/api/chat/state', undefined]]) {
        const result = context.request(path, data);
        advance(16000);
        assert.equal(pending.options.signal.aborted, false, 'AI work survives the former 15-second limit');
        advance(39000);
        assert.equal(pending.options.signal.aborted, false, 'Provider can use its full generation deadline');
        pending.resolve({ok: true, json: async () => ({ok: true})});
        assert.equal((await result).ok, true);
        assert.equal(timer, null, 'Completed requests release their timer');
    }
    const failure = assert.rejects(context.request('/api/chat/state'), {name: 'AbortError'});
    advance(70000); await failure;
    assert.equal(timer, null, 'Failed requests release their timer');
    console.log('PASS Slow send/state responses succeed and stalled requests remain bounded.');
})().catch(error => { console.error(error); process.exitCode = 1; });
