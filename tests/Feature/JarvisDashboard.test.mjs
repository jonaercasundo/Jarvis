import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const dashboard = (await readFile(new URL('../../resources/views/jarvis/dashboard.blade.php', import.meta.url), 'utf8')).replace(/\r\n/g, '\n');
const starterHandler = dashboard.match(/document\.querySelectorAll\('\.jarvis-suggestion'\)\.forEach\(button => \{[\s\S]*?\n    \}\);/)?.[0];
const applyNames = dashboard.match(/    function applyNames\([\s\S]*?\n    \}\n\n(?=    function setVal)/)?.[0];

function starterContext(busy = false) {
    const buttons = [...dashboard.matchAll(/<button[^>]+data-prompt="([^"]+)"[^>]+class="jarvis-suggestion[^>]+>/g)]
        .map((match) => ({
            dataset: { prompt: match[1] },
            addEventListener(name, callback) { this[name] = callback; },
        }));
    const calls = [];
    const input = { value: '' };
    runInNewContext(starterHandler, {
        document: { querySelectorAll() { return buttons; } },
        input,
        jarvisWorking: busy,
        talkToJarvis() { calls.push(input.value); },
    });
    return { buttons, calls, input };
}

test('all dashboard starters dispatch natural language through the existing command handler', () => {
    assert.ok(starterHandler, 'The dashboard must retain the shared starter handler.');
    const { buttons, calls } = starterContext();

    buttons.forEach((button) => button.click());

    assert.deepEqual(calls, [
        'Compare all active projects',
        'Show project delivery progress',
        'Check important emails',
        'Show my workflows',
    ]);
});

test('dashboard starters respect the existing busy command guard', () => {
    const { buttons, calls, input } = starterContext(true);

    buttons[0].click();

    assert.deepEqual(calls, []);
    assert.equal(input.value, '');
});

test('changing the configured identity immediately updates the dashboard placeholder and labels', () => {
    assert.ok(applyNames, 'The settings module must update the configured name.');
    const input = { attributes: {}, setAttribute(name, value) { this.attributes[name] = value; } };
    const labels = [{ textContent: '' }, { textContent: '' }];
    const saved = new Map();
    const context = {
        $: (id) => id === 'jarvis-input' ? input : null,
        document: { querySelectorAll() { return labels; }, title: '' },
        window: {},
        localStorage: { setItem(name, value) { saved.set(name, value); } },
    };
    runInNewContext(applyNames, context);

    context.applyNames('Astra', 'Owner');
    assert.equal(input.placeholder, 'Ask Astra anything...');
    context.applyNames('Vanessa', 'Owner');

    assert.equal(input.placeholder, 'Ask Vanessa anything...');
    assert.equal(input.attributes['aria-label'], 'Ask Vanessa anything');
    assert.deepEqual(labels.map((label) => label.textContent), ['Vanessa', 'Vanessa']);
    assert.equal(context.window.jcAiName, 'Vanessa');
    assert.equal(saved.get('jc_ai_name'), 'Vanessa');
});
