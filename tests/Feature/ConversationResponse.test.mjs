import assert from 'node:assert/strict';
import test from 'node:test';

class Node {
    constructor(tag) {
        this.tagName = tag;
        this.children = [];
        this.attributes = {};
        this.dataset = {};
        this.listeners = {};
        this.textContent = '';
        this.open = false;
        this.className = '';
        this.style = { setProperty() {} };
        this.classList = { remove() {}, toggle() {}, add() {} };
    }
    set innerHTML(value) { throw new Error(`Unsafe HTML assignment: ${value}`); }
    append(...nodes) { nodes.forEach((node) => { node.parent = this; }); this.children.push(...nodes); }
    replaceChildren(...nodes) { this.children = nodes; }
    setAttribute(name, value) { this.attributes[name] = value; }
    addEventListener(name, callback) { this.listeners[name] = callback; }
    closest() { return this; }
    click() { this.listeners.click?.(); }
    showModal() { this.open = true; }
    close() { this.open = false; this.listeners.close?.(); }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter((node) => node !== this); }
    focus() { this.focused = true; }
}

const nodes = [];
const events = [];
globalThis.CustomEvent = class { constructor(type, options) { this.type = type; this.detail = options.detail; } };
globalThis.window = { addEventListener() {}, dispatchEvent(event) { events.push(event); } };
globalThis.document = {
    body: new Node('body'),
    readyState: 'loading', addEventListener() {},
    createElement(tag) { const node = new Node(tag); nodes.push(node); return node; },
    createElementNS(namespace, tag) { return this.createElement(tag); },
    querySelectorAll(selector) { return nodes.filter((node) => (selector === '[data-response-action]' && node.dataset.responseAction) || (selector === '[data-clarification-choice]' && node.dataset.clarificationChoice)); },
};

const { renderResponse, speechFor, safeSpeech, beginThinking, finishThinking, setBusy, emailAttentionResponse } = await import('../../resources/js/jarvis-responses.js');
const { artifactRenderers } = await import('../../resources/js/jarvis-artifacts.js');
window.jcArtifactWorkspace = { renderers: artifactRenderers };
const { renderJarvisChart } = await import('../../resources/js/jarvis-visuals.js');
const { renderArtifact } = await import('../../resources/js/jarvis-visual-report.js');

function all(root) { return [root, ...root.children.flatMap(all)]; }
function find(root, tag) { return all(root).filter((node) => node.tagName === tag); }

const report = (blocks) => ({ type: 'visual_report', version: 1, title: 'Project Operational Report',
    source: { system: 'MMC Tracker', retrieved_at: '2026-10-03T06:18:09Z' }, blocks });

const projectStatus = () => ({ type: 'project_status', version: 1, title: 'North Campus Project Status',
    project: { name: 'North Campus', status: 'For Contract Signing' },
    warehouse_readiness: [{ unit: 'pc', percentage: 59.36 }, { unit: 'set', percentage: 42 }, { unit: 'pcs', percentage: null }],
    pipeline: [
        { key: 'dr', label: 'DR', value: 28649, unit: 'DR receipts', status: 'Recorded' },
        { key: 'stock_out', label: 'Stock Out', value: null, status: 'Unavailable' },
        { key: 'delivered', label: 'Delivered', value: 0, total: 251557, percentage: 0 },
        { key: 'billing', label: 'Billing', value: 0 }, { key: 'billed', label: 'Billed', value: 0 },
    ],
    overall_progress: null, source: { system: 'MMC Tracker', retrieved_at: '2026-10-03T06:18:09Z' },
});

test('project status opens real readiness and flow values without repeating the AI narrative in Details', () => {
    const host = new Node('div');
    const artifact = projectStatus();
    const fullAnswer = 'North Campus is currently For Contract Signing. DR setup is complete, warehouse readiness varies by item unit, and deliveries have not started.';

    renderResponse(host, { artifact, display: { type: 'conversation', message: 'North Campus is currently For Contract Signing.', details: fullAnswer, expanded: true,
        metrics: [{ label: 'DR', value: 28649 }], visual: { type: 'metrics', metrics: [{ label: 'DR', value: 28649 }] } } });
    const trigger = find(host, 'button')[0];
    trigger.click();

    assert.equal(trigger.textContent, 'View Project Status');
    assert.equal(find(host, 'details').length, 1);
    assert.ok(find(host, 'strong').some((item) => item.textContent === '28,649'));
    const modal = find(document.body, 'dialog')[0];
    assert.ok(find(modal, 'h3').some((item) => item.textContent === 'North Campus'));
    assert.ok(find(modal, 'p').some((item) => item.textContent === 'For Contract Signing'));
    const bars = all(modal).filter((item) => item.attributes.role === 'progressbar');
    assert.equal(bars.length, 0);
    const sections = find(modal, 'section');
    assert.deepEqual(sections.flatMap((section) => section.children.filter((item) => item.tagName === 'h3').map((item) => item.textContent)),
        ['North Campus', 'Operational Flow', 'Warehouse Readiness']);
    assert.deepEqual(find(modal, 'dd').map((item) => item.textContent), ['59%', '42%', 'Unavailable']);
    const stages = find(modal, 'ol')[0];
    assert.deepEqual(find(stages, 'h4').map((item) => item.textContent), ['DR', 'Stock Out', 'Delivered', 'Billing', 'Billed']);
    assert.deepEqual(find(stages, 'strong').map((item) => item.textContent), ['28,649', 'Unavailable', '0', '0', '0']);
    assert.ok(find(stages, 'span').some((item) => item.textContent === '0% complete'));
    const details = find(modal, 'details')[0];
    assert.equal(details.open, false);
    assert.equal(find(details, 'summary')[0].textContent, 'View Details');
    assert.ok(find(details, 'td').some((item) => item.textContent === '59.36%'));
    assert.ok(find(modal, 'span').some((item) => item.textContent === 'Source: MMC Tracker'));
    find(modal, 'button')[0].click();
    assert.equal(trigger.focused, true);
    assert.equal(find(document.body, 'dialog').length, 0);
});

test('executive readiness rounds each unit independently without changing raw values or adding an overall average', () => {
    const artifact = projectStatus();
    artifact.warehouse_readiness[2].percentage = 51.59;

    const rendered = renderArtifact(artifact);

    assert.deepEqual(find(rendered, 'dt').map((item) => item.textContent), ['pc', 'set', 'pcs']);
    assert.deepEqual(find(rendered, 'dd').map((item) => item.textContent), ['59%', '42%', '52%']);
    assert.deepEqual(artifact.warehouse_readiness.map((item) => item.percentage), [59.36, 42, 51.59]);
    assert.equal(all(rendered).filter((item) => item.attributes.role === 'progressbar').length, 0);
});

test('project status omits empty warehouse data while warehouse reports retain separate readiness bars', () => {
    const artifact = projectStatus();
    artifact.warehouse_readiness = [];
    assert.ok(!find(renderArtifact(artifact), 'h3').some((item) => item.textContent === 'Warehouse Readiness'));

    const warehouse = renderArtifact(report([{ type: 'progress_group', title: 'Warehouse readiness',
        items: [{ label: 'pc', value: 59.36 }, { label: 'set', value: 42 }, { label: 'pcs', value: 51.59 }] }]));

    assert.deepEqual(all(warehouse).filter((item) => item.attributes.role === 'progressbar').map((item) => item.attributes['aria-valuenow']), ['59.36', '42', '51.59']);
});

test('project status allows genuine zero progress and safe literal project text, but rejects invalid percentages', () => {
    const artifact = projectStatus();
    artifact.project.name = '<img src=x onerror=alert(1)>';
    artifact.overall_progress = 0;

    const rendered = renderArtifact(artifact);

    assert.equal(find(rendered, 'img').length, 0);
    assert.ok(find(rendered, 'h3').some((item) => item.textContent === '<img src=x onerror=alert(1)>'));
    assert.ok(find(rendered, 'strong').some((item) => item.textContent === '0%'));
    assert.ok(all(rendered).some((item) => item.attributes['aria-label'] === 'Overall Operational Progress' && item.attributes['aria-valuenow'] === '0'));
    for (const invalid of [101, -1, Infinity, '59', undefined]) {
        artifact.warehouse_readiness[0].percentage = invalid;
        assert.throws(() => renderArtifact(artifact), /Invalid project status metric/);
    }
});

test('portfolio appears immediately and sorts, filters, and opens projects using authoritative IDs', () => {
    const host = new Node('div');
    const artifact = { ...report([{ type: 'table', columns: ['Project', 'Status', 'Delivery completion', 'Billing completion', 'Overall progress', 'Active', 'Project ID'], rows: [
        [{ value: 'Science North' }, { value: 'Implementation' }, { value: 20, unit: '%' }, { value: null, unit: '%' }, { value: null, unit: '%' }, { value: true }, { value: 771 }],
        [{ value: 'Book Supply' }, { value: 'Collected' }, { value: 80, unit: '%' }, { value: 100, unit: '%' }, { value: null, unit: '%' }, { value: false }, { value: 225 }],
    ] }]), type: 'project_portfolio' };

    renderResponse(host, { artifact, display: { message: 'Two projects retrieved.' } });

    assert.equal(find(host, 'table').length, 1);
    assert.equal(find(document.body, 'dialog').length, 0);
    find(host, 'button').find((item) => item.textContent === 'Sort by delivery completion').click();
    assert.equal(find(host, 'tbody')[0].children[0].children[0].textContent, 'Book Supply');
    find(host, 'button').find((item) => item.textContent === 'Filter active projects').click();
    assert.equal(find(host, 'tbody')[0].children.length, 1);
    assert.equal(find(host, 'tbody')[0].children[0].children[0].textContent, 'Science North');
    find(host, 'button').find((item) => item.textContent === 'View delivery coverage').click();
    assert.equal(events.at(-1).detail.answer, 'Show delivery status of project ID 771');
});

test('report Details contain explicitly structured values rather than a natural-language explanation', () => {
    const host = new Node('div');

    renderResponse(host, { artifact: projectStatus(), display: { type: 'conversation', message: 'The project is waiting for contract signing.',
        details: 'The project is waiting for contract signing. Deliveries have not started.',
        structured_details: [{ label: 'Project status', value: 'For Contract Signing' }, { label: 'Delivery', value: 0, unit: '%' }, { label: 'Overall progress', value: null }] } });

    const disclosure = find(host, 'details').find((item) => find(item, 'summary')[0]?.textContent === 'Details');
    assert.equal(disclosure.open, false);
    assert.equal(find(disclosure, 'summary')[0].textContent, 'Details');
    assert.deepEqual(find(disclosure, 'dd').map((item) => item.textContent), ['For Contract Signing', '0%', 'Unavailable']);
    assert.equal(all(host).filter((item) => item.className === 'jc-response-detail-text').length, 0);
});

test('visual reports keep chat compact and open an accessible dialog with source and restored focus', () => {
    const host = new Node('div');
    const artifact = report([
        { type: 'kpi_grid', items: [{ label: 'DR recorded', value: 28649, unit: 'DR receipts' }] },
        { type: 'progress_group', title: 'Readiness', items: [{ label: 'pc', value: 59.36 }, { label: 'Billing', value: null }] },
        { type: 'pipeline', stages: [{ label: 'DR', value: 28649, total: 28649, unit: 'DR receipts' }, { label: 'Billed', value: null, total: null, percent: null }] },
    ]);
    renderResponse(host, { artifact, display: { type: 'conversation', message: 'The project has recorded DRs.', details: 'The project has recorded DRs.', expanded: true } });
    assert.equal(find(host, 'article').length, 1);
    assert.equal(find(host, 'details').length, 0);
    const trigger = find(host, 'button')[0];
    assert.equal(trigger.textContent, 'View Visual Report');
    trigger.click();
    const modal = find(document.body, 'dialog')[0];
    assert.equal(modal.open, true);
    assert.equal(modal.attributes['aria-modal'], 'true');
    assert.equal(modal.attributes['aria-labelledby'], find(modal, 'h2')[0].id);
    assert.equal(find(modal, 'article').length, 1);
    const bars = all(modal).filter((item) => item.attributes.role === 'progressbar');
    assert.equal(bars.length, 1);
    assert.equal(bars[0].attributes['aria-valuenow'], '59.36');
    assert.ok(find(modal, 'strong').some((item) => item.textContent === 'Unavailable'));
    assert.ok(find(modal, 'span').some((item) => item.textContent === 'Total unavailable'));
    assert.ok(find(modal, 'span').some((item) => item.textContent === 'Source: MMC Tracker'));
    assert.ok(find(modal, 'span').some((item) => item.textContent.startsWith('Updated: ') && !item.textContent.includes('Unavailable')));
    const close = find(modal, 'button')[0];
    assert.equal(close.focused, true);
    close.click();
    assert.equal(find(document.body, 'dialog').length, 0);
    assert.equal(trigger.focused, true);
});

test('report charts render SVG with underlying values and a PNG action while other blocks use plain DOM', () => {
    const blocks = ['bar_chart', 'line_chart', 'pie_chart'].map((type) => ({ type, title: 'Observed values', unit: 'PHP',
        series: [{ name: 'Actual', data: [{ label: '2026-01', value: 100 }, { label: '2026-02', value: 200 }] }] }));
    blocks.push({ type: 'table', columns: ['Status', 'Progress'], rows: [[{ value: '<script>alert(1)</script>' }, { value: null, unit: '%' }]] },
        { type: 'progress', label: 'Accepted', value: 0 },
        { type: 'key_value', items: [{ label: 'Project status', value: 'Contract signing' }] },
        { type: 'text_summary', text: 'Recorded observations only.' },
        { type: 'timeline', events: [{ label: 'Recorded', date: '2026-10-03', value: 'Contract review' }] });
    const root = renderArtifact(report(blocks));
    assert.equal(find(root, 'svg').length, 3);
    assert.equal(find(root, 'script').length, 0);
    assert.ok(find(root, 'td').some((item) => item.textContent === '<script>alert(1)</script>'));
    assert.ok(find(root, 'td').some((item) => item.textContent === '200 PHP'));
    assert.equal(find(root, 'summary').filter((item) => item.textContent === 'View Data').length, 3);
    assert.equal(find(root, 'button').filter((item) => item.textContent === 'Download PNG').length, 3);
    assert.equal(find(root, 'time').length, 1);
});

test('broken optional reports retain the answer without adding natural-language Details', () => {
    const host = new Node('div');
    for (const blocks of [[{ type: 'unsupported' }], [{ type: 'kpi_grid', items: null }]]) {
        renderResponse(host, { artifact: report(blocks), display: { type: 'conversation', message: 'Verified answer.', details: 'Additional verified detail.', expanded: true } });
        assert.equal(find(host, 'p')[0].textContent, 'Verified answer.');
        assert.equal(find(host, 'details').length, 0);
        const trigger = find(host, 'button')[0];
        if (trigger) {
            trigger.click();
            assert.equal(trigger.textContent, 'Report unavailable');
        }
        assert.equal(find(document.body, 'dialog').length, 0);
    }
});

test('voice uses only speech_text and never display labels or raw result content', () => {
    assert.equal(speechFor({ speech_text: 'Six deliveries need your attention.', display: { message: '**Delivered 142**' }, response: 'Read this entire essay.' }), 'Six deliveries need your attention.');
    assert.equal(speechFor({ response: 'Legacy essay with confidential contents.' }), 'The details are on screen.');
    for (const unsafe of ['https://example.com', '**Read this**', '{"success":true}', 'FindEmails is READ_ONLY.', 'app/Ai/Agents/JcAgent.php', 'Use [Send] (now)', 'step_one', 'Email → Reply']) {
        assert.equal(safeSpeech(unsafe), 'The details are on screen.');
    }
    assert.equal(safeSpeech('Your reply is ready. Want me to send it?'), 'Your reply is ready. Want me to send it?');
    assert.equal(safeSpeech(''), '');
});

test('ordinary and technical responses keep full details with safe text nodes', () => {
    const host = new Node('div');
    const dangerous = '<img src=x onerror=alert(1)> **full result**';
    renderResponse(host, { display: { type: 'conversation', message: 'I found the answer.', details: dangerous } });
    assert.equal(find(host, 'details')[0].open, false);
    assert.equal(all(host).find((node) => node.className === 'jc-response-detail-text').textContent, dangerous);
    assert.equal(find(host, 'img').length, 0);
    renderResponse(host, { display: { type: 'conversation', message: 'Here are the details.', details: dangerous, expanded: true } });
    assert.equal(find(host, 'details')[0].open, true);
});

test('metrics render source values without introducing extra values', () => {
    const host = new Node('div');
    renderResponse(host, { display: { type: 'metrics', message: 'Six need your attention.', metrics: [{ label: 'Delivered', value: 142 }, { label: 'Pending', value: 18 }, { label: 'Overdue', value: 6 }] } });
    assert.deepEqual(find(host, 'strong').map((node) => node.textContent), ['142', '18', '6']);
});

test('charts reuse the real renderer and keep forecasts distinct from actual observations', () => {
    const host = new Node('div');
    renderResponse(host, { display: { type: 'chart', message: 'Next month may be higher.', artifact: { type: 'chart', data: { format: 'line', series: [{ label: 'September', value: 100, kind: 'actual' }, { label: 'October', value: 124, kind: 'forecast' }] } }, explanation: 'Recent trend only.' } });
    assert.equal(find(host, 'svg').length, 1);
    assert.ok(find(host, 'line').some((line) => line.attributes['stroke-dasharray']));
    assert.equal(find(host, 'summary')[0].textContent, 'Why?');
});

test('email previews show three cards first, keep bodies out, and open through the existing event', () => {
    const host = new Node('div');
    renderResponse(host, { display: { type: 'email_preview', message: 'I found four messages.', items: Array.from({ length: 4 }, (_, index) => ({ uid: String(index + 1), sender: 'RISE', subject: '<script>alert(1)</script>', body: 'Do not display this body.' })) } });
    const disclosure = find(host, 'details')[0];
    assert.equal(disclosure.open, false);
    assert.equal(find(disclosure, 'summary')[0].textContent, 'Show all 4 messages');
    assert.equal(find(host, 'script').length, 0);
    assert.ok(!all(host).some((node) => node.textContent.includes('Do not display this body.')));
    setBusy(false);
    find(host, 'button')[0].click();
    assert.deepEqual(events.at(-1), new CustomEvent('jc:response-open-email', { detail: { uid: '1' } }));
});

test('automatic email alerts use compact cards and preserve full analysis only in details', () => {
    const host = new Node('div');
    const notifications = Array.from({ length: 4 }, (_, index) => ({
        token: 'acknowledgement-secret', message_id: String(index + 1),
        from: 'sender@example.com', subject: '<script>alert(1)</script>',
        priority: 'HIGH', reason: 'An invoice needs review.',
        action: 'Check the amount.', deadline: '2026-10-04',
    }));
    const payload = emailAttentionResponse(notifications);
    assert.equal(speechFor(payload), '4 emails need your attention.');
    assert.equal(payload.display.items.length, 4);
    assert.ok(!JSON.stringify(payload).includes('acknowledgement-secret'));
    assert.ok(payload.display.items.every((email) => !('uid' in email)));
    renderResponse(host, payload);
    assert.equal(find(host, 'script').length, 0);
    assert.equal(find(host, 'button').length, 0);
    assert.ok(find(host, 'details').every((disclosure) => !disclosure.open));
    const detailText = all(host).find((node) => node.className === 'jc-response-detail-text').textContent;
    assert.ok(detailText.includes('Why it matters: An invoice needs review.'));
    assert.ok(detailText.includes('Action: Check the amount.'));
    assert.ok(detailText.includes('Deadline: 2026-10-04'));
    assert.ok(!speechFor(payload).includes('sender@example.com'));
});

test('a single automatic email alert speaks its meaning without reading its contents', () => {
    const payload = emailAttentionResponse([{ from: 'FindEmails', subject: '**URGENT**', reason: 'Visit https://example.com/review', action: null }]);
    assert.equal(speechFor(payload), 'One email needs your attention.');
    assert.equal(payload.display.items[0].action, 'Visit https://example.com/review');
    assert.equal(payload.display.message, payload.speech_text);
});

test('confirmation actions preserve the exact command and block repeat clicks while processing', () => {
    const host = new Node('div');
    setBusy(false);
    renderResponse(host, { display: { type: 'confirmation', message: 'The email is ready.', draft: { to: 'carlo@example.com', subject: 'Update', body: 'Review this message.' }, actions: [{ label: 'Send', command: 'confirm ABC123' }, { label: 'Edit', fill: 'Revise the email draft: ' }, { label: 'Cancel', command: 'cancel ABC123' }] } });
    const buttons = find(host, 'button');
    const before = events.length;
    buttons[0].click();
    buttons[0].click();
    assert.equal(events.length, before + 1);
    assert.equal(events.at(-1).detail.command, 'confirm ABC123');
    setBusy(false);
    buttons[1].click();
    assert.equal(events.at(-1).type, 'jc:response-fill');
    buttons[2].click();
    assert.equal(events.at(-1).detail.command, 'cancel ABC123');
    setBusy(false);
});

test('clarification choices preserve their question reference and send a normal answer', () => {
    const host = new Node('div');
    const id = '11111111-1111-4111-8111-111111111111';
    renderResponse(host, { display: { type: 'clarification', payload: { type: 'clarification', question: 'What should I focus on?', clarification_id: id, allow_custom_answer: true, options: [{ label: 'Deliveries', value: 'deliveries' }] } } });
    find(host, 'button')[0].click();
    assert.equal(events.at(-1).type, 'jc:clarification-answer');
    assert.deepEqual(events.at(-1).detail, { answer: 'deliveries', clarificationId: id });
    assert.ok(find(host, 'p').some((node) => node.textContent === 'Or type your answer below.'));
});

test('thinking state exposes a natural status and finishes without leaking internal tool names', () => {
    const host = new Node('div');
    beginThinking(host, 'Find unread emails from Carlo.');
    assert.equal(host.attributes['aria-busy'], 'true');
    assert.ok(find(host, 'span').some((node) => node.textContent === 'Checking your inbox'));
    assert.equal(find(host, 'i').length, 3);
    finishThinking(host);
    assert.equal(host.attributes['aria-busy'], 'false');
});

test('structured expense forecast renders actual and forecast lines, a sample badge, and a usable modal', () => {
    const host = new Node('div');
    const visual = { type: 'chart', chart_type: 'line', title: 'Expense Trend & Forecast', is_demo: true, insight: 'Expenses fell sharply.', source: 'Illustrative sample data', explanation: 'The forecast is illustrative.', y_axis: { format: 'currency', currency: 'PHP' }, series: [
        { name: 'Actual', data: [{ label: 'Last year', value: 6000000 }, { label: 'This year', value: 2000000 }] },
        { name: 'Forecast', forecast: true, data: [{ label: 'This year', value: 2000000 }, { label: 'Next year', value: 2100000 }] },
    ] };
    renderResponse(host, { message: 'Expenses fell sharply.', speech_text: 'Expenses fell sharply.', visual });
    assert.equal(find(host, 'svg').length, 1);
    assert.ok(find(host, 'p').some((node) => node.textContent === 'SAMPLE DATA'));
    const lines = find(host, 'line');
    assert.ok(lines.some((line) => line.attributes['stroke-width'] === '3' && !line.attributes['stroke-dasharray']));
    assert.ok(lines.some((line) => line.attributes['stroke-dasharray'] === '7 5'));
    assert.ok(find(host, 'title').some((node) => node.textContent.includes('forecast estimate')));
    assert.ok(find(host, 'details').every((detail) => !detail.open));
    const legend = find(host, 'button').find((control) => control.textContent === 'Actual');
    legend.click();
    assert.equal(legend.attributes['aria-pressed'], 'false');
    assert.equal(find(host, 'g')[0].style.display, 'none');
    legend.click();
    assert.equal(legend.attributes['aria-pressed'], 'true');
    const expand = find(host, 'button').find((control) => control.textContent === 'Expand');
    expand.click();
    const dialog = find(document.body, 'dialog')[0];
    assert.equal(dialog.open, true);
    assert.equal(find(dialog, 'svg').length, 1);
    assert.ok(find(dialog, 'summary').some((node) => node.textContent === 'Data'));
    assert.ok(find(dialog, 'summary').some((node) => node.textContent === 'Why?'));
    assert.ok(find(dialog, 'p').some((node) => node.textContent === 'Source: Illustrative sample data'));
    find(dialog, 'button').find((control) => control.textContent === 'Close').click();
    assert.equal(find(document.body, 'dialog').length, 0);
    assert.equal(expand.focused, true);
});

test('bar, area, donut, pie and scatter render actual marks rather than text descriptions', () => {
    for (const type of ['bar', 'area', 'donut', 'pie', 'scatter']) {
        const visual = { type: 'chart', chart_type: type, title: 'Business data', series: [{ name: 'Observed', data: [{ label: 'A', value: 10, x: 1 }, { label: 'B', value: 20, x: 2 }] }] };
        const result = renderJarvisChart(visual);
        assert.equal(find(result, 'svg').length, 1, type);
        const tag = { bar: 'rect', area: 'path', donut: 'circle', pie: 'path', scatter: 'circle' }[type];
        assert.ok(find(result, tag).length > 0, type);
    }
});

test('a missing observation remains a gap and null values never become zero', () => {
    const result = renderJarvisChart({ chart_type: 'line', title: 'Incomplete', series: [{ name: 'Actual', data: [{ label: 'Jan', value: 10 }, { label: 'Feb', value: null }, { label: 'Mar', value: 30 }] }] });
    assert.equal(find(result, 'circle').length, 2);
    assert.equal(find(result, 'line').filter((line) => line.attributes['stroke-width'] === '3').length, 0);
    assert.ok(find(result, 'p').some((node) => node.textContent === 'Missing observations remain gaps.'));
});

test('metrics, tables, processes and timelines use dedicated safe visual renderers', () => {
    for (const visual of [
        { type: 'metrics', title: 'Ongoing projects', metrics: [{ label: 'Ongoing', value: 10 }] },
        { type: 'table', title: 'Records', columns: ['period', 'value'], rows: [{ period: 'Jan', value: 10 }] },
        { type: 'process', title: 'Email process', steps: [{ label: 'Understand', detail: 'Review what is needed.' }] },
        { type: 'timeline', title: 'Milestones', steps: [{ label: 'Delivery', date: 'October', detail: 'Expected completion.' }] },
    ]) {
        const host = new Node('div');
        renderResponse(host, { message: 'Here is the result.', visual });
        assert.equal(find(host, 'svg').length, 0);
        assert.ok(find(host, visual.type === 'table' ? 'table' : visual.type === 'metrics' ? 'strong' : 'ol').length > 0);
    }
});

test('an adapted legacy chart renders once and keeps its original artifact as compatibility data', () => {
    const host = new Node('div');
    const visual = { type: 'chart', chart_type: 'line', title: 'Deliveries', explanation: 'Trend estimate.', series: [{ name: 'Actual', data: [{ label: 'January', value: 10 }, { label: 'February', value: 20 }] }] };
    renderResponse(host, { display: { type: 'chart', message: 'Deliveries increased.', visual, explanation: visual.explanation, artifact: { type: 'chart', data: { series: [{ label: 'January', value: 10 }] } } } });
    assert.equal(find(host, 'svg').length, 1);
    assert.equal(find(host, 'summary').filter((summary) => summary.textContent === 'Why?').length, 1);
    assert.ok(find(host, 'button').some((button) => button.textContent === 'Expand'));
});
