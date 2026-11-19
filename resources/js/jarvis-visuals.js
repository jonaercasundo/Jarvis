const COLORS = ['#67e8f9', '#c4b5fd', '#fbbf24', '#6ee7b7', '#fda4af', '#93c5fd', '#fdba74', '#d8b4fe'];

function node(tag, className = '', text = null) {
    const element = document.createElement(tag);
    element.className = className;
    if (text !== null) element.textContent = String(text);
    return element;
}

function svgNode(tag, attributes = {}, text = null) {
    const element = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, String(value)));
    if (text !== null) element.textContent = String(text);
    return element;
}

function numberFormat(visual) {
    const options = { maximumFractionDigits: 2, notation: 'compact' };
    if (visual.y_axis?.format === 'currency' && /^[A-Z]{3}$/.test(visual.y_axis.currency || '')) {
        options.style = 'currency';
        options.currency = visual.y_axis.currency;
    }
    const formatter = new Intl.NumberFormat(undefined, options);
    return (value) => `${formatter.format(value)}${visual.y_axis?.format === 'percent' ? '%' : ''}`;
}

function chartData(visual) {
    return (Array.isArray(visual.series) ? visual.series : []).slice(0, 8).map((series) => ({
        name: String(series.name || ''), forecast: series.forecast === true,
        data: (Array.isArray(series.data) ? series.data : []).slice(0, 100).map((point) => ({
            label: String(point.label || ''), value: typeof point.value === 'number' && Number.isFinite(point.value) ? point.value : null,
            x: typeof point.x === 'number' && Number.isFinite(point.x) ? point.x : null,
        })),
    }));
}

function tooltip(mark, label) {
    mark.setAttribute('tabindex', '0');
    mark.setAttribute('aria-label', label);
    mark.append(svgNode('title', {}, label));
    return mark;
}

export function renderJarvisChart(visual, { palette = COLORS } = {}) {
    const COLORS = palette;
    const root = node('div', 'jc-visual-chart');
    const series = chartData(visual);
    if (!series.some((set) => set.data.some((point) => point.value !== null))) {
        root.append(node('p', '', 'No numerical observations are available.'));
        return root;
    }
    const format = numberFormat(visual);
    const chart = svgNode('svg', { viewBox: '0 0 700 330', role: 'img', 'aria-label': visual.title || 'Chart' });
    chart.append(svgNode('title', {}, visual.title || 'Chart'));
    const type = visual.chart_type || 'line';
    const groups = [];
    const labels = [...new Set(series.flatMap((set) => set.data.map((point) => point.label)))];
    const values = series.flatMap((set) => set.data.map((point) => point.value)).filter((value) => value !== null);
    const minimum = Math.min(0, ...values);
    const maximum = Math.max(0, ...values);
    const range = maximum - minimum || 1;
    const y = (value) => 270 - ((value - minimum) / range) * 230;
    const x = (label) => 85 + (labels.length === 1 ? 0.5 : labels.indexOf(label) / (labels.length - 1)) * 570;
    const scatterXs = series.flatMap((set) => set.data.map((point) => point.x)).filter((value) => value !== null);
    const minX = Math.min(...scatterXs); const maxX = Math.max(...scatterXs);
    const scatterX = (value) => 85 + ((value - minX) / (maxX - minX || 1)) * 570;
    if (!['donut', 'pie'].includes(type)) {
        for (let tick = 0; tick <= 4; tick += 1) {
            const value = minimum + range * tick / 4;
            chart.append(svgNode('line', { x1: 85, x2: 655, y1: y(value), y2: y(value), stroke: 'currentColor', 'stroke-opacity': 0.12 }));
            chart.append(svgNode('text', { x: 75, y: y(value) + 4, 'text-anchor': 'end', 'font-size': 12, fill: 'currentColor' }, format(value)));
        }
        const interval = Math.max(1, Math.ceil(labels.length / 6));
        if (type === 'scatter') {
            for (let tick = 0; tick <= 4; tick += 1) {
                const value = minX + (maxX - minX) * tick / 4;
                chart.append(svgNode('text', { x: scatterX(value), y: 295, 'text-anchor': 'middle', 'font-size': 12, fill: 'currentColor' }, String(Number(value.toFixed(2)))));
            }
        } else {
            labels.forEach((label, index) => {
                if (index % interval === 0 || index === labels.length - 1) {
                    const caption = svgNode('text', { x: x(label), y: 295, 'text-anchor': 'middle', 'font-size': 12, fill: 'currentColor' }, label.slice(0, 14));
                    caption.append(svgNode('title', {}, label)); chart.append(caption);
                }
            });
        }
    }
    if (['donut', 'pie'].includes(type)) {
        const points = series[0].data.filter((point) => point.value > 0);
        const total = points.reduce((sum, point) => sum + point.value, 0);
        let offset = 0;
        points.forEach((point, index) => {
            const fraction = point.value / total;
            let segment;
            if (type === 'donut') {
                segment = svgNode('circle', { cx: 350, cy: 160, r: 100, fill: 'none', stroke: COLORS[index % COLORS.length], 'stroke-width': 45,
                    pathLength: 100, 'stroke-dasharray': `${fraction * 100} ${100 - fraction * 100}`, 'stroke-dashoffset': -offset * 100, transform: 'rotate(-90 350 160)' });
            } else {
                const start = offset * Math.PI * 2 - Math.PI / 2;
                const end = (offset + fraction) * Math.PI * 2 - Math.PI / 2;
                segment = fraction === 1 ? svgNode('circle', { cx: 350, cy: 160, r: 125, fill: COLORS[index % COLORS.length] })
                    : svgNode('path', { d: `M350 160 L${350 + Math.cos(start) * 125} ${160 + Math.sin(start) * 125} A125 125 0 ${fraction > 0.5 ? 1 : 0} 1 ${350 + Math.cos(end) * 125} ${160 + Math.sin(end) * 125} Z`, fill: COLORS[index % COLORS.length] });
            }
            chart.append(tooltip(segment, `${point.label}: ${format(point.value)}`));
            offset += fraction;
        });
        const legend = node('div', 'jc-visual-legend');
        points.forEach((point, index) => {
            const label = node('span', '', `${point.label}: ${format(point.value)}`);
            label.style.setProperty('--jc-series-color', COLORS[index % COLORS.length]); legend.append(label);
        });
        root.append(chart, legend);
    } else {
        series.forEach((set, seriesIndex) => {
            const group = svgNode('g'); groups.push(group);
            const color = set.forecast ? '#c4b5fd' : COLORS[seriesIndex % COLORS.length];
            set.data.forEach((point, index) => {
                if (point.value === null) return;
                const position = type === 'scatter' ? scatterX(point.x) : x(point.label);
                const tip = `${set.name}, ${point.label}${type === 'scatter' ? `, x ${point.x}` : ''}: ${format(point.value)}${set.forecast ? ' (forecast estimate)' : ''}`;
                if (type === 'bar') {
                    const width = Math.min(36, 480 / Math.max(1, labels.length * series.length));
                    const left = position - (series.length * width) / 2 + seriesIndex * width;
                    group.append(tooltip(svgNode('rect', { x: left, y: Math.min(y(point.value), y(0)), width: width - 2, height: Math.abs(y(point.value) - y(0)), fill: color }), tip));
                } else {
                    const previous = set.data[index - 1];
                    if (type !== 'scatter' && previous?.value !== null && previous?.value !== undefined) {
                        if (type === 'area') group.append(svgNode('path', { d: `M${x(previous.label)} ${y(0)} L${x(previous.label)} ${y(previous.value)} L${position} ${y(point.value)} L${position} ${y(0)} Z`, fill: color, 'fill-opacity': 0.12 }));
                        group.append(svgNode('line', { x1: x(previous.label), y1: y(previous.value), x2: position, y2: y(point.value), stroke: color, 'stroke-width': 3, ...(set.forecast ? { 'stroke-dasharray': '7 5' } : {}) }));
                    }
                    group.append(tooltip(svgNode('circle', { cx: position, cy: y(point.value), r: 4.5, fill: set.forecast ? '#10283a' : color, stroke: color, 'stroke-width': 2 }), tip));
                }
            });
            chart.append(group);
        });
        const legend = node('div', 'jc-visual-legend');
        series.forEach((set, index) => {
            const control = node('button', set.forecast ? 'is-forecast' : '', set.name + (set.forecast && !/forecast/i.test(set.name) ? ' · Forecast' : ''));
            control.type = 'button'; control.setAttribute('aria-pressed', 'true');
            control.style.setProperty('--jc-series-color', set.forecast ? '#c4b5fd' : COLORS[index % COLORS.length]);
            let visible = true;
            control.addEventListener('click', () => { visible = !visible; groups[index].style.display = visible ? '' : 'none'; control.setAttribute('aria-pressed', String(visible)); });
            legend.append(control);
        });
        root.append(chart, legend);
    }
    if (visual.x_axis?.label || visual.y_axis?.label) root.append(node('p', 'jc-artifact-note', [visual.x_axis?.label, visual.y_axis?.label].filter(Boolean).join(' · ')));
    if (series.some((set) => set.data.some((point) => point.value === null))) root.append(node('p', 'jc-artifact-note', 'Missing observations remain gaps.'));
    return root;
}

function dataTable(visual) {
    const rows = visual.rows || chartData(visual).flatMap((set) => set.data.map((point) => ({ series: set.name, period: point.label, value: point.value, ...(point.x !== null ? { x: point.x } : {}), kind: set.forecast ? 'Forecast' : 'Actual' })));
    const columns = visual.columns || ['series', 'period', 'value', ...(rows.some((row) => 'x' in row) ? ['x'] : []), 'kind'];
    const table = node('table', 'jc-artifact-table');
    const header = node('tr'); columns.forEach((column) => header.append(node('th', '', column)));
    const head = node('thead'); head.append(header); const body = node('tbody');
    rows.forEach((row) => { const line = node('tr'); columns.forEach((column) => line.append(node('td', '', row[column] ?? '—'))); body.append(line); });
    table.append(head, body); const wrapper = node('div', 'jc-artifact-table-wrap'); wrapper.append(table); return wrapper;
}

function disclosure(label, content) {
    const detail = node('details', 'jc-response-details'); detail.append(node('summary', '', label), content); return detail;
}

export function openVisualModal(visual, focusTarget) {
    const dialog = node('dialog', 'jc-visual-modal');
    const content = node('section', 'jc-visual-modal-content');
    dialog.setAttribute('aria-label', visual.title || 'Visual');
    const close = node('button', 'jc-visual-button', 'Close'); close.type = 'button'; close.autofocus = true;
    close.addEventListener('click', () => dialog.close());
    content.append(close, renderJarvisVisual(visual, { expanded: true }));
    if (visual.insight) content.append(node('p', 'jc-response-message', visual.insight));
    if (visual.source) content.append(node('p', 'jc-artifact-note', `Source: ${visual.source}`));
    if (visual.type === 'chart') content.append(disclosure('Data', dataTable(visual)));
    if (visual.explanation) content.append(disclosure('Why?', node('p', '', visual.explanation)));
    dialog.append(content); document.body.append(dialog);
    dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => { dialog.remove(); focusTarget?.focus(); });
    dialog.showModal();
    return dialog;
}

export function renderJarvisVisual(visual, { expanded = false } = {}) {
    const root = node('section', 'jc-visual-artifact');
    root.append(node('h3', 'jc-visual-title', visual.title || 'Visual'));
    if (visual.is_demo) root.append(node('p', 'jc-visual-demo-badge', 'SAMPLE DATA'));
    if (visual.subtitle) root.append(node('p', 'jc-artifact-note', visual.subtitle));
    if (visual.type === 'chart') root.append(renderJarvisChart(visual));
    else if (visual.type === 'table') root.append(dataTable(visual));
    else if (visual.type === 'metrics') {
        const grid = node('div', 'jc-response-metrics');
        (visual.metrics || []).forEach((metric) => { const card = node('div', 'jc-response-metric'); card.append(node('strong', '', metric.value), node('span', '', metric.label)); grid.append(card); }); root.append(grid);
    } else if (['process', 'timeline'].includes(visual.type)) {
        const steps = node('ol', 'jc-visual-steps');
        (visual.steps || []).forEach((step, index) => { const item = node('li'); item.style.setProperty('--jc-arrival', `${index * 45}ms`); item.append(node('strong', '', step.label)); if (step.date) item.append(node('span', '', step.date)); if (step.detail) item.append(node('p', '', step.detail)); steps.append(item); }); root.append(steps);
    } else if (visual.type === 'email_preview') {
        (visual.items || []).forEach((email) => { const card = node('article', 'jc-response-email'); card.append(node('p', '', email.sender), node('h3', '', email.subject)); if (email.action) card.append(node('p', '', email.action)); root.append(card); });
    }
    if (!expanded && visual.type === 'chart') {
        const controls = node('div', 'jc-response-actions');
        const expand = node('button', 'jc-visual-button', 'Expand'); expand.type = 'button';
        expand.addEventListener('click', () => openVisualModal(visual, expand)); controls.append(expand); root.append(controls);
        root.append(disclosure('View data', dataTable(visual)));
        if (visual.explanation) root.append(disclosure('Why?', node('p', '', visual.explanation)));
    }
    return root;
}
