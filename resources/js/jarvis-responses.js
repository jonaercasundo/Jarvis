import { renderClarification } from './jarvis-clarifications.js';
import { renderVisualDemo } from './jarvis-visual-demos.js';
import { renderJarvisVisual } from './jarvis-visuals.js';
import { REPORT_TYPES, renderInlineReport } from './jarvis-visual-report.js';

let busy = false;
let thinkingTimer = null;

function element(tag, className = '', value = null) {
    const node = document.createElement(tag);
    node.className = className;
    if (value !== null) node.textContent = String(value);
    return node;
}

function text(value, limit = 500) {
    return typeof value === 'string' ? value.slice(0, limit) : '';
}

export function safeSpeech(value, fallback = 'The details are on screen.') {
    const spoken = text(value, 2000).trim();
    if (!spoken) return '';
    if (spoken.length > 360 || /[^\p{L}\p{N}\s.,!?'’\-]/u.test(spoken)
        || /\b(?:READ_ONLY|EXTERNAL_SIDE_EFFECT|DESTRUCTIVE|Authorization|Bearer|tool|executor|class|schema|HTTP)\b/i.test(spoken)
        || /\b(?:[A-Z][a-z]+(?:[A-Z][a-z]+)+|[a-z]+_[a-z_]+)\b/.test(spoken)) return fallback;
    return spoken;
}

export function speechFor(payload) {
    return typeof payload?.speech_text === 'string' ? safeSpeech(payload.speech_text) : 'The details are on screen.';
}

export function emailAttentionResponse(notifications) {
    const emails = Array.isArray(notifications) ? notifications : [];
    const message = emails.length === 1
        ? 'One email needs your attention.'
        : `${emails.length} emails need your attention.`;
    return {
        speech_text: message,
        display: {
            type: 'email_preview',
            message,
            items: emails.map((email) => ({
                sender: email.from || 'Unknown sender',
                subject: email.subject || '(No subject)',
                category: email.priority,
                action: email.action || email.reason,
            })),
            details: emails.map((email) => [
                `From: ${email.from || 'Unknown sender'}`,
                `Subject: ${email.subject || '(No subject)'}`,
                `Priority: ${email.priority || 'Needs attention'}`,
                `Why it matters: ${email.reason || ''}`,
                ...(email.action ? [`Action: ${email.action}`] : []),
                ...(email.deadline ? [`Deadline: ${email.deadline}`] : []),
            ].join('\n')).join('\n\n'),
        },
    };
}

function details(label, value, open = false) {
    const disclosure = element('details', 'jc-response-details');
    disclosure.open = open;
    disclosure.append(element('summary', '', label), element('div', 'jc-response-detail-text', String(value ?? '')));
    return disclosure;
}

function structuredDetails(items) {
    const disclosure = element('details', 'jc-response-details');
    const list = element('dl', 'jc-report-key-values jc-response-structured-details');
    items.slice(0, 12).forEach((item) => {
        if (!item || typeof item.label !== 'string' || (!['string', 'number'].includes(typeof item.value) && item.value !== null)) return;
        const pair = element('div');
        const value = item.value === null ? 'Unavailable' : `${item.value}${item.unit === '%' ? '%' : item.unit ? ` ${text(item.unit, 70)}` : ''}`;
        pair.append(element('dt', '', text(item.label, 160)), element('dd', '', String(value).slice(0, 500)));
        list.append(pair);
    });
    if (!list.children.length) return null;
    disclosure.append(element('summary', '', 'Details'), list);
    return disclosure;
}

function commandButton(action) {
    const control = element('button', 'jc-visual-button jc-response-action', text(action.label, 70));
    control.type = 'button';
    control.disabled = busy;
    control.dataset.responseAction = 'true';
    control.addEventListener('click', () => {
        if (busy || control.disabled) return;
        const command = text(action.command, 2000);
        const fill = text(action.fill, 2000);
        if (fill) {
            window.dispatchEvent(new CustomEvent('jc:response-fill', { detail: { text: fill } }));
        } else if (command.trim()) {
            setBusy(true);
            window.dispatchEvent(new CustomEvent('jc:response-command', { detail: { command } }));
        }
    });
    return control;
}

function metrics(items) {
    const grid = element('div', 'jc-response-metrics');
    (Array.isArray(items) ? items : []).slice(0, 6).forEach((item, index) => {
        const card = element('div', 'jc-response-metric');
        card.style.setProperty('--jc-arrival', `${Math.min(index * 45, 180)}ms`);
        card.append(element('strong', '', String(item.value ?? '')), element('span', '', text(item.label, 70)));
        grid.append(card);
    });
    return grid;
}

function emailCard(email, index) {
    const card = element('article', 'jc-response-email');
    card.style.setProperty('--jc-arrival', `${Math.min(index * 45, 180)}ms`);
    const content = element('div', 'jc-response-email-content');
    content.append(element('p', 'jc-response-email-sender', text(email.sender, 100)), element('h3', '', text(email.subject, 160)));
    if (email.action || email.category) {
        content.append(element('p', 'jc-response-email-note', text(email.action || String(email.category).replaceAll('_', ' ').toLowerCase(), 180)));
    }
    card.append(content);
    if (/^\d+$/.test(String(email.uid ?? ''))) {
        const open = element('button', 'jc-visual-button jc-response-action', 'Open');
        open.type = 'button';
        open.disabled = busy;
        open.dataset.responseAction = 'true';
        open.addEventListener('click', () => {
            if (busy || open.disabled) return;
            window.dispatchEvent(new CustomEvent('jc:response-open-email', { detail: { uid: String(email.uid) } }));
        });
        card.append(open);
    }
    return card;
}

export function finishThinking(host) {
    clearTimeout(thinkingTimer);
    thinkingTimer = null;
    host?.closest('#jarvis-result')?.setAttribute('aria-busy', 'false');
}

export function beginThinking(host, command = '') {
    if (!host) return;
    finishThinking(host);
    host.closest('#jarvis-result')?.setAttribute('aria-busy', 'true');
    const stage = element('div', 'jc-response-thinking');
    stage.setAttribute('role', 'status');
    let label = 'Thinking';
    if (!/^\s*(?:what can|how can|explain|describe|demo|show me how|just show me)/i.test(command)) {
        if (/\b(?:emails?|inbox)\b/i.test(command)) label = 'Checking your inbox';
        else if (/\bforecast\b/i.test(command)) label = 'Finding the trend';
        else if (/\b(?:workflow|automation)\b/i.test(command)) label = 'Checking your automation';
        else if (/\b(?:source|code)\b/i.test(command)) label = 'Looking through my code';
        else if (/\b(?:data|deliveries|projects?|inventory)\b/i.test(command)) label = 'Looking at your data';
    }
    const caption = element('span', '', label);
    const dots = element('span', 'jc-response-thinking-dots');
    dots.setAttribute('aria-hidden', 'true');
    for (let index = 0; index < 3; index += 1) dots.append(element('i'));
    stage.append(caption, dots);
    host.replaceChildren(stage);
    thinkingTimer = setTimeout(() => { caption.textContent = 'Still working on that'; }, 8000);
}

export function renderResponse(host, payload) {
    if (!host) return false;
    finishThinking(host);
    const legacyType = payload?.type === 'visual_demo' ? 'visual_demo' : (payload?.type === 'clarification' ? 'clarification' : 'conversation');
    const display = payload?.display || { type: payload?.visual?.type || legacyType, message: text(payload?.message || payload?.response, 480), visual: payload?.visual, details: payload?.visual ? null : payload?.response };
    const report = display.report || payload?.artifact;
    const hasReport = REPORT_TYPES.has(report?.type);
    const visualPayload = display.payload || payload;
    host.closest('#jarvis-result')?.classList.remove('jc-visual-result', 'jc-clarification-result');
    if (display.type === 'clarification' && renderClarification(host, visualPayload)) return true;
    if (['capability_cards', 'visual_demo'].includes(display.type) && renderVisualDemo(host, visualPayload)) return true;

    const root = element('section', `jc-response-content${display.type === 'error' ? ' is-error' : ''}`);
    if (display.message) root.append(element('p', 'jc-response-message', text(display.message, 1200)));
    if (hasReport) {
        try { root.append(renderInlineReport(report)); } catch { console.warn('JARVIS report could not be rendered.'); }
    }
    if (display.visual && !hasReport) root.append(renderJarvisVisual(display.visual));
    if (display.metrics?.length && !hasReport) root.append(metrics(display.metrics));
    if (display.type === 'email_preview') {
        const emails = Array.isArray(display.items) ? display.items : [];
        const cards = element('div', 'jc-response-emails');
        emails.slice(0, 3).forEach((email, index) => cards.append(emailCard(email, index)));
        root.append(cards);
        if (emails.length > 3) {
            const more = element('details', 'jc-response-details');
            more.append(element('summary', '', `Show all ${emails.length} messages`));
            const remaining = element('div', 'jc-response-emails');
            emails.slice(3, 100).forEach((email, index) => remaining.append(emailCard(email, index)));
            more.append(remaining);
            root.append(more);
        }
    }
    if (display.artifact && !display.visual && !hasReport) {
        const artifact = display.artifact;
        const renderer = window.jcArtifactWorkspace?.renderers?.[artifact.type];
        if (typeof renderer === 'function') {
            const figure = element('div', 'jc-response-figure');
            if (!['report', 'sync_status'].includes(artifact.type) || !display.metrics?.length) {
                figure.append(renderer(artifact.data || {}, artifact));
                root.append(figure);
            } else if (artifact.data?.rows) {
                const records = element('details', 'jc-response-details');
                records.append(element('summary', '', 'Show data'), window.jcArtifactWorkspace.renderers.table(artifact.data));
                root.append(records);
            }
        }
    }
    if (display.draft) {
        const draft = element('details', 'jc-response-details');
        draft.append(element('summary', '', 'Review email'), element('p', '', `To: ${text(display.draft.to, 200)}`),
            element('p', '', `Subject: ${text(display.draft.subject, 255)}`), element('div', 'jc-response-detail-text', String(display.draft.body || '')));
        root.append(draft);
    }
    if (display.actions?.length) {
        const controls = element('div', 'jc-response-actions');
        display.actions.slice(0, 6).forEach((action) => controls.append(commandButton(action)));
        root.append(controls);
    }
    if (display.review_note) root.append(element('p', 'jc-response-email-note', text(display.review_note, 160)));
    if (/^\/(?!\/)/.test(String(display.connection_url || ''))) {
        const connection = element('a', 'jc-visual-button jc-response-action', 'Check connection');
        connection.href = display.connection_url;
        root.append(connection);
    }
    if (display.explanation && !display.visual?.explanation) root.append(details('Why?', display.explanation));
    if (Array.isArray(display.structured_details)) {
        const structured = structuredDetails(display.structured_details);
        if (structured) root.append(structured);
    } else if (!hasReport && typeof display.details === 'string' && display.details.trim() !== (display.message || '').trim()) {
        root.append(details(display.expanded ? 'Full explanation' : 'Details', display.details, display.expanded));
    }
    host.replaceChildren(root);
    return true;
}

export function setBusy(value) {
    busy = Boolean(value);
    document.querySelectorAll('[data-response-action]').forEach((control) => { control.disabled = busy; });
}

if (typeof window !== 'undefined') {
    window.jcResponse = { render: renderResponse, begin: beginThinking, finish: finishThinking, setBusy, speechFor, safeSpeech, emailAttentionResponse };
}
