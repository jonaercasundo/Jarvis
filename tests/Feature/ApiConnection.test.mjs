import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../resources/views/jarvis/connections.blade.php', import.meta.url), 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];

function setup(response, saved = false) {
    const handlers = {};
    const button = { disabled: false, textContent: 'Test connection', addEventListener: (name, callback) => { handlers[name] = callback; } };
    const result = { textContent: '', className: '' };
    const form = {
        dataset: saved ? {} : { testUrl: '/jarvis/settings/connections/test' },
        action: '/jarvis/settings/connections/1/test',
        reportValidity: () => true,
        querySelector: (selector) => selector === '[data-test-connection]' ? button : selector === '[data-test-result]' ? result : { value: 'csrf-value' },
        addEventListener: (name, callback) => { handlers[name] = callback; },
    };
    const calls = [];
    vm.runInNewContext(script, {
        document: { querySelectorAll: () => [form] },
        FormData: class { constructor(value) { assert.equal(value, form); } },
        fetch: async (url, options) => { calls.push({ url, options }); return response; },
    });
    return { handlers, button, result, calls };
}

test('test click prevents navigation, sends CSRF, shows loading and an inline result', async () => {
    const page = setup({ ok: true, json: async () => ({ success: true, message: 'JARVIS connected to MMC Tracker' }) });
    let prevented = false;
    const pending = page.handlers.click({ preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(page.button.disabled, true);
    assert.equal(page.button.textContent, 'Testing...');
    await pending;
    assert.equal(page.calls[0].url, '/jarvis/settings/connections/test');
    assert.equal(page.calls[0].options.method, 'POST');
    assert.equal(page.calls[0].options.headers['X-CSRF-TOKEN'], 'csrf-value');
    assert.equal(page.calls[0].options.redirect, 'error');
    assert.equal(page.result.textContent, '✓ JARVIS connected to MMC Tracker');
    assert.equal(page.button.disabled, false);
});

test('authentication failure is displayed inline and restores the button', async () => {
    const page = setup({ ok: false, json: async () => ({ success: false, message: 'Authentication failed. Check the Bearer token.' }) }, true);
    await page.handlers.click({ preventDefault() {} });
    assert.equal(page.calls[0].url, '/jarvis/settings/connections/1/test');
    assert.equal(page.result.textContent, '✕ Authentication failed. Check the Bearer token.');
    assert.equal(page.button.textContent, 'Test connection');
});

test('saved test form submit stays on the page and triggers testing', () => {
    const page = setup({}, true);
    let clicked = false;
    let prevented = false;
    page.button.click = () => { clicked = true; };
    page.handlers.submit({ preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(clicked, true);
});
