const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
const ICON_PATHS = Object.freeze({
    mail: ['M4 5h16v14H4z', 'm4 6 8 7 8-7'],
    search: ['M16 10a6 6 0 1 1-12 0 6 6 0 0 1 12 0', 'm15 15 5 5'],
    brain: ['M12 5c-1-3-6-2-6 2-4 0-5 6-2 8-1 4 4 7 8 3', 'M12 5c1-3 6-2 6 2 4 0 5 6 2 8 1 4-4 7-8 3', 'M12 5v13', 'M6 8c0 2 1 3 3 3', 'M18 8c0 2-1 3-3 3'],
    pen: ['m4 16-1 5 5-1L20 8l-4-4z', 'm13 7 4 4'],
    'user-check': ['M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0', 'M3 21v-2a7 7 0 0 1 12-5', 'm16 19 2 2 4-5'],
    user: ['M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0', 'M4 21v-2a8 8 0 0 1 16 0v2'],
    send: ['m3 3 18 9-18 9 4-9z', 'M7 12h14'],
    data: ['M20 6c0 2-4 3-8 3S4 8 4 6s4-3 8-3 8 1 8 3', 'M4 6v12c0 2 4 3 8 3s8-1 8-3V6', 'M4 12c0 2 4 3 8 3s8-1 8-3'],
    chart: ['M4 3v17h17', 'M8 16v-5', 'M13 16V7', 'M18 16v-8'],
    trend: ['M3 18 9 12l4 3 8-10', 'M16 5h5v5'],
    forecast: ['M18 10a6 6 0 1 1-12 0 6 6 0 0 1 12 0', 'm9 8 2 2 4-3', 'M9 16v3', 'M15 16v3', 'M6 21h12'],
    lightbulb: ['M9 18h6', 'M9 21h6', 'M8 14a6 6 0 1 1 8 0l-1 4H9z'],
    settings: ['M14 4h-4l-1 3-3 1-2 3 2 3 3 1 1 3h4l1-3 3-1 2-3-2-3-3-1z', 'M15 11a3 3 0 1 1-6 0 3 3 0 0 1 6 0'],
    preferences: ['M4 6h16', 'M4 12h16', 'M4 18h16', 'M8 3v6', 'M16 9v6', 'M10 15v6'],
    code: ['m8 6-6 6 6 6', 'm16 6 6 6-6 6', 'm14 3-4 18'],
    message: ['M21 4H3v14h4v4l5-4h9z', 'M7 9h10', 'M7 13h7'],
    puzzle: ['M4 4h5a3 3 0 1 1 6 0h5v5a3 3 0 1 0 0 6v5h-5a3 3 0 1 0-6 0H4v-5a3 3 0 1 1 0-6z'],
    check: ['m5 12 4 4L20 5'],
    workflow: ['M4 4h6v6H4z', 'M14 14h6v6h-6z', 'M10 7h7v7', 'M7 10v7h7'],
    cloud: ['M7 18a5 5 0 1 1 0-10 6 6 0 0 1 11-1 5 5 0 0 1 0 11z'],
    replay: ['M4 11a8 8 0 1 1 2 7', 'M4 4v7h7'],
    back: ['m10 5-7 7 7 7', 'M3 12h18'],
    arrow: ['M4 12h16', 'm14 6 6 6-6 6'],
});
const ICON_ALIASES = Object.freeze({
    email: 'mail', database: 'data', 'bar-chart': 'chart', 'line-chart': 'trend',
    'chart-line': 'trend', sparkles: 'brain', gear: 'settings', globe: 'cloud',
    automation: 'workflow', adapt: 'preferences', developer: 'code', insights: 'lightbulb',
});
let presentationId = 0;
let isBusy = false;

function text(value, maximum = 240) {
    return typeof value === 'string' ? value.slice(0, maximum) : '';
}

function element(tag, className = '', value = null) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (value !== null) node.textContent = value;
    return node;
}

function svgElement(tag, attributes = {}) {
    const node = document.createElementNS(SVG_NAMESPACE, tag);
    Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, String(value)));
    return node;
}

function icon(name, className = '') {
    const svg = svgElement('svg', {
        viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor',
        'stroke-width': 1.65, 'stroke-linecap': 'round', 'stroke-linejoin': 'round',
        'aria-hidden': 'true', focusable: 'false',
    });
    svg.setAttribute('class', `jc-visual-icon ${className}`.trim());
    const requested = typeof name === 'string' ? name : 'brain';
    const key = ICON_ALIASES[requested] || requested;
    const paths = Object.hasOwn(ICON_PATHS, key) ? ICON_PATHS[key] : ICON_PATHS.brain;
    paths.forEach((path) => svg.append(svgElement('path', { d: path })));
    return svg;
}

function button(label, className, iconName = null) {
    const control = element('button', `jc-visual-button ${className}`, null);
    control.type = 'button';
    if (iconName) control.append(icon(iconName));
    control.append(element('span', '', label));
    return control;
}

function validSeries(chart) {
    return (Array.isArray(chart?.series) ? chart.series : [])
        .filter((entry) => entry && typeof entry.value === 'number' && Number.isFinite(entry.value))
        .slice(0, 12)
        .map((entry) => ({ label: text(entry.label, 35), value: entry.value, forecast: entry.forecast === true }));
}

function sampleChart(chart) {
    const series = validSeries(chart);
    if (!series.length) return null;

    const figure = element('figure', 'jc-visual-chart');
    const caption = element('figcaption', 'jc-visual-chart-heading');
    caption.append(element('strong', '', text(chart.title, 80) || 'Example trend'));
    caption.append(element('span', 'jc-visual-sample-label', 'Illustrative sample data'));
    figure.append(caption);

    if (chart.format === 'bar' || (!chart.format && !series.some((entry) => entry.forecast))) {
        figure.append(barChart(series));
    } else {
        figure.append(lineChart(series));
    }

    if (series.some((entry) => entry.forecast)) {
        const legend = element('div', 'jc-visual-chart-legend');
        legend.append(element('span', 'jc-visual-legend-actual', 'Past values'));
        legend.append(element('span', 'jc-visual-legend-forecast', 'Estimate'));
        figure.append(legend);
        figure.append(element('p', 'jc-visual-chart-note', 'Estimates show a possible trend, not a guaranteed result.'));
    }
    return figure;
}

function barChart(series) {
    const section = element('div', 'jc-visual-bars');
    const scale = Math.max(...series.map((entry) => Math.abs(entry.value)), 1);
    const minimum = Math.min(...series.map((entry) => entry.value / scale), 0);
    const maximum = Math.max(...series.map((entry) => entry.value / scale), 0);
    const range = maximum - minimum || 1;
    const origin = (-minimum / range) * 100;
    series.forEach((entry) => {
        const row = element('div', `jc-visual-bar-row${entry.forecast ? ' is-forecast' : ''}`);
        row.append(element('span', 'jc-visual-bar-label', entry.label));
        const track = element('div', 'jc-visual-bar-track');
        const baseline = element('span', 'jc-visual-bar-baseline');
        baseline.style.left = `${origin}%`;
        const bar = element('span', 'jc-visual-bar');
        const value = entry.value / scale;
        bar.style.left = `${value < 0 ? ((value - minimum) / range) * 100 : origin}%`;
        bar.style.width = `${(Math.abs(value) / range) * 100}%`;
        track.append(bar, baseline);
        row.append(track, element('strong', '', String(entry.value)));
        if (entry.forecast) row.append(element('span', 'jc-visual-forecast-label', 'Estimate'));
        section.append(row);
    });
    return section;
}

function lineChart(series) {
    const scale = Math.max(...series.map((entry) => Math.abs(entry.value)), 1);
    const minimum = Math.min(0, ...series.map((entry) => entry.value / scale));
    const maximum = Math.max(0, ...series.map((entry) => entry.value / scale), minimum + (1 / scale));
    const left = 42;
    const right = 506;
    const top = 28;
    const bottom = 130;
    const points = series.map((entry, index) => ({
        ...entry,
        x: series.length === 1 ? (left + right) / 2 : left + ((right - left) * index) / (series.length - 1),
        y: bottom - (((entry.value / scale) - minimum) / (maximum - minimum)) * (bottom - top),
    }));
    const svg = svgElement('svg', { viewBox: '0 0 540 165', role: 'img', class: 'jc-visual-line-chart' });
    const title = svgElement('title');
    title.textContent = `Illustrative sample data. ${series.map((entry) => `${entry.label}: ${entry.value}${entry.forecast ? ' estimate' : ''}`).join('; ')}.`;
    svg.append(title);

    [top, (top + bottom) / 2, bottom].forEach((y) => {
        svg.append(svgElement('line', { x1: left, y1: y, x2: right, y2: y, class: 'jc-visual-chart-grid' }));
    });
    [[maximum, top], [minimum, bottom]].forEach(([value, y]) => {
        const label = svgElement('text', { x: left - 10, y: y + 4, 'text-anchor': 'end', class: 'jc-visual-axis-label' });
        label.textContent = Number((value * scale).toFixed(1)).toLocaleString();
        svg.append(label);
    });
    points.forEach((entry, index) => {
        const previous = points[index - 1];
        if (previous) {
            svg.append(svgElement('line', {
                x1: previous.x, y1: previous.y, x2: entry.x, y2: entry.y,
                class: `jc-visual-chart-line${entry.forecast || previous.forecast ? ' is-forecast' : ''}`,
            }));
        }
        svg.append(svgElement('circle', {
            cx: entry.x, cy: entry.y, r: 4,
            class: `jc-visual-chart-point${entry.forecast ? ' is-forecast' : ''}`,
        }));
        const label = svgElement('text', { x: entry.x, y: 155, 'text-anchor': 'middle', class: 'jc-visual-axis-label' });
        label.textContent = entry.label;
        svg.append(label);
    });
    return svg;
}

function flowSteps(steps) {
    const list = element('ol', 'jc-visual-flow');
    const items = (Array.isArray(steps) ? steps : []).filter((step) => step && text(step.title)).slice(0, 8);
    list.style.setProperty('--jc-visual-step-count', Math.max(1, items.length));
    items.forEach((step, index) => {
        const item = element('li', 'jc-visual-step');
        item.style.setProperty('--jc-visual-delay', `${index * 280}ms`);
        const symbol = element('span', 'jc-visual-step-symbol');
        symbol.append(icon(step.icon));
        item.append(symbol, element('span', 'jc-visual-step-title', text(step.title, 90)));
        if (index < items.length - 1) item.append(icon('arrow', 'jc-visual-connector'));
        list.append(item);
    });
    return list;
}

function replay(flow) {
    flow.classList.remove('is-playing');
    void flow.offsetWidth;
    flow.classList.add('is-playing');
}

function liveAction(action) {
    const command = text(action?.command, 500);
    const label = text(action?.label, 70);
    if (!command.trim() || !label.trim()) return null;
    const control = button(label, 'jc-visual-live-action', 'arrow');
    control.dataset.visualLive = 'true';
    control.disabled = isBusy;
    control.addEventListener('click', () => {
        if (isBusy || control.disabled) return;
        setBusy(true);
        window.dispatchEvent(new CustomEvent('jc:visual-demo-command', { detail: { command } }));
    });
    return control;
}

function flowPresentation(data, onBack = null) {
    const presentation = element('section', 'jc-visual-detail');
    const header = element('div', 'jc-visual-detail-header');
    if (onBack) {
        const back = button('Back to choices', 'jc-visual-back', 'back');
        back.addEventListener('click', onBack);
        header.append(back);
    } else {
        header.append(element('span', 'jc-visual-kicker', 'How it works'));
    }
    const replayControl = button('Replay', 'jc-visual-replay', 'replay');
    const flow = flowSteps(data.steps);
    replayControl.addEventListener('click', () => replay(flow));
    header.append(replayControl);
    const heading = element('h2', 'jc-visual-title', text(data.title, 90));
    heading.tabIndex = -1;
    presentation.append(header, heading, flow);
    const summary = text(data.summary);
    if (summary) presentation.append(element('p', 'jc-visual-summary', summary));
    const chart = data.chart ? sampleChart(data.chart) : null;
    if (chart) presentation.append(chart);
    const availability = text(data.availability, 100);
    const action = liveAction(data.live_action);
    if (availability || action) {
        const controls = element('div', 'jc-visual-live-controls');
        if (availability) controls.append(element('p', 'jc-visual-flow-availability', availability));
        if (action) controls.append(action);
        presentation.append(controls);
    }
    replay(flow);
    return presentation;
}

function categoryPresentation(payload, root) {
    const choices = element('div', 'jc-visual-choices');
    const heading = element('h2', 'jc-visual-title', text(payload.title, 90) || 'Here’s what I can help with');
    heading.id = `jc-visual-heading-${++presentationId}`;
    root.setAttribute('aria-labelledby', heading.id);
    choices.append(heading);
    if (text(payload.summary)) choices.append(element('p', 'jc-visual-summary', text(payload.summary)));
    const grid = element('div', 'jc-visual-categories');
    const detail = element('div', 'jc-visual-expanded');
    detail.hidden = true;
    (Array.isArray(payload.categories) ? payload.categories : []).filter((category) => category && text(category.title)).slice(0, 8).forEach((category) => {
        const card = element('article', 'jc-visual-category');
        card.append(icon(category.icon, 'jc-visual-category-icon'));
        card.append(element('h3', '', text(category.title, 90)));
        card.append(element('p', '', text(category.description || category.summary, 100)));
        const availability = text(category.availability, 100);
        if (availability) card.append(element('span', 'jc-visual-availability', availability));
        const show = button('Show me', 'jc-visual-show', 'arrow');
        show.setAttribute('aria-label', `Show me: ${text(category.title, 90)}`);
        show.setAttribute('aria-expanded', 'false');
        show.addEventListener('click', () => {
            show.setAttribute('aria-expanded', 'true');
            window.dispatchEvent(new CustomEvent('jc:visual-demo-category', { detail: { category: text(category.id, 60) } }));
            const presentation = flowPresentation({ ...category, title: category.flow_title || category.title }, () => {
                detail.replaceChildren();
                detail.hidden = true;
                choices.hidden = false;
                root.setAttribute('aria-labelledby', heading.id);
                show.setAttribute('aria-expanded', 'false');
                show.focus({ preventScroll: true });
                window.dispatchEvent(new CustomEvent('jc:visual-demo-category', { detail: { category: null } }));
            });
            choices.hidden = true;
            detail.hidden = false;
            detail.replaceChildren(presentation);
            const detailHeading = presentation.querySelector('h2');
            detailHeading.id = `jc-visual-heading-${++presentationId}`;
            root.setAttribute('aria-labelledby', detailHeading.id);
            detailHeading.focus({ preventScroll: true });
        });
        card.append(show);
        grid.append(card);
    });
    choices.append(grid);
    root.append(choices, detail);
}

function setBusy(busy) {
    isBusy = Boolean(busy);
    document.querySelectorAll('[data-visual-live]').forEach((control) => { control.disabled = isBusy; });
}

export function isExplicitDraftCommand(command, capabilityExplanationActive = false) {
    if (typeof command !== 'string') return false;
    const match = command.trim().match(/^(?:please[, ]+)?(proceed|go\s+ahead|draft\s+it|draft\s+a\s+reply)(?:[, ]+please)?[.!?]*$/i);
    if (!match) return false;
    const phrase = match[1].toLowerCase().replace(/\s+/g, ' ');
    return !capabilityExplanationActive || !['proceed', 'go ahead'].includes(phrase);
}

export function renderVisualDemo(host, payload) {
    if (!host) return false;
    const isVisual = payload?.type === 'visual_demo';
    host.closest('#jarvis-result')?.classList.toggle('jc-visual-result', isVisual);
    if (!isVisual) return false;
    const root = element('div', 'jc-visual-demo');
    root.setAttribute('role', 'region');
    if (payload.mode === 'categories' || (Array.isArray(payload.categories) && payload.categories.length)) {
        categoryPresentation(payload, root);
    } else {
        const presentation = flowPresentation(payload);
        const heading = presentation.querySelector('h2');
        heading.id = `jc-visual-heading-${++presentationId}`;
        root.setAttribute('aria-labelledby', heading.id);
        root.append(presentation);
    }
    host.replaceChildren(root);
    return true;
}

if (typeof window !== 'undefined') {
    window.jcVisualDemo = { render: renderVisualDemo, setBusy, isExplicitDraftCommand };
    window.addEventListener('jc:visual-demo-busy', (event) => setBusy(event.detail?.busy));
}
