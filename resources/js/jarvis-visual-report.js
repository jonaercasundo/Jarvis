import { renderJarvisChart } from './jarvis-visuals.js';

const TYPES = new Set(['kpi_grid', 'progress', 'progress_group', 'pipeline', 'bar_chart', 'line_chart', 'pie_chart', 'table', 'key_value', 'text_summary', 'timeline']);
export const REPORT_TYPES = new Set(['visual_report', 'project_status', 'delivery_status', 'warehouse_status', 'billing_status', 'logistics_status', 'project_comparison', 'project_portfolio']);
let activeModal = null;
let reportId = 0;

function node(tag, className = '', value = null) {
    const result = document.createElement(tag);
    result.className = className;
    if (value !== null) result.textContent = String(value);
    return result;
}

function valueLabel(value, unit = '', maximumFractionDigits = 2) {
    if (value === null || value === undefined) return 'Unavailable';
    const formatted = typeof value === 'number' ? new Intl.NumberFormat(undefined, { maximumFractionDigits }).format(value) : String(value);
    return `${formatted}${unit === '%' ? '%' : ''}`;
}

function cellValue(cell) {
    const formatted = valueLabel(cell.value, cell.unit);
    return cell.unit && cell.unit !== '%' && cell.value !== null ? `${formatted} ${cell.unit}` : formatted;
}

function button(label, handler) {
    const control = node('button', 'jc-report-button', label);
    control.type = 'button';
    control.addEventListener('click', handler);
    return control;
}

function validateEnvelope(artifact) {
    if (artifact?.type === 'project_status') {
        if ((artifact.version !== undefined && artifact.version !== 1) || typeof artifact.title !== 'string'
            || typeof artifact.project?.name !== 'string' || typeof artifact.project?.status !== 'string'
            || !Array.isArray(artifact.warehouse_readiness) || artifact.warehouse_readiness.length > 12
            || !Array.isArray(artifact.pipeline) || !artifact.pipeline.length || artifact.pipeline.length > 12
            || !artifact.source?.system) throw new Error('Invalid project status.');
        const validPercentage = (value) => value === null || (typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 100);
        if (!validPercentage(artifact.overall_progress ?? null)
            || !artifact.warehouse_readiness.every((item) => typeof item?.unit === 'string' && validPercentage(item.percentage))
            || !artifact.pipeline.every((stage) => typeof stage?.label === 'string'
                && (stage.value === null || (typeof stage.value === 'number' && Number.isFinite(stage.value)))
                && (stage.total === undefined || stage.total === null || (typeof stage.total === 'number' && Number.isFinite(stage.total)))
                && (stage.percentage === undefined || validPercentage(stage.percentage)))) throw new Error('Invalid project status metric.');
        return;
    }
    if (!REPORT_TYPES.has(artifact?.type) || artifact.version !== 1 || typeof artifact.title !== 'string'
        || !Array.isArray(artifact.blocks) || !artifact.blocks.length || artifact.blocks.length > 10
        || !artifact.blocks.every((block) => TYPES.has(block?.type)) || !artifact.source?.system) throw new Error('Invalid visual report.');
    let points = 0;
    let rows = 0;
    artifact.blocks.forEach((block) => {
        if (block.type === 'table' && (!Array.isArray(block.rows) || block.rows.length > 100)) throw new Error('Too many table rows.');
        if (block.type === 'table') rows += block.rows.length;
        (block.series || []).forEach((series) => { points += series.data?.length || 0; });
    });
    if (points > 100) throw new Error('Too many chart points.');
    if (rows > 100) throw new Error('Too many table rows.');
}

function table(columns, rows) {
    const scroll = node('div', 'jc-report-table-scroll');
    scroll.setAttribute('tabindex', '0');
    scroll.setAttribute('role', 'region');
    scroll.setAttribute('aria-label', 'Report data');
    const grid = node('table', 'jc-report-table');
    const head = node('thead');
    const header = node('tr');
    columns.forEach((label) => { const cell = node('th', '', label); cell.setAttribute('scope', 'col'); header.append(cell); });
    head.append(header);
    const body = node('tbody');
    rows.forEach((row) => {
        const line = node('tr');
        row.forEach((cell) => line.append(node('td', '', cellValue(cell))));
        body.append(line);
    });
    grid.append(head, body);
    scroll.append(grid);
    return scroll;
}

function progress(item) {
    const row = node('div', `jc-report-progress${item.value === null ? ' is-unavailable' : ''}`);
    const caption = node('div', 'jc-report-progress-caption');
    caption.append(node('span', '', item.label), node('strong', '', valueLabel(item.value, '%', item.maximumFractionDigits ?? 2)));
    const track = node('div', 'jc-report-progress-track');
    if (item.value !== null) {
        if (!Number.isFinite(Number(item.value)) || item.value < 0 || item.value > 100) throw new Error('Invalid progress value.');
        track.setAttribute('role', 'progressbar');
        track.setAttribute('aria-label', item.label);
        track.setAttribute('aria-valuemin', '0');
        track.setAttribute('aria-valuemax', '100');
        track.setAttribute('aria-valuenow', String(item.value));
        const fill = node('span');
        fill.style.width = `${item.value}%`;
        track.append(fill);
    } else {
        track.setAttribute('aria-label', `${item.label}: unavailable`);
    }
    row.append(caption, track);
    if (item.note) row.append(node('p', 'jc-report-note', item.note));
    return row;
}

async function downloadChart(svg, title, control) {
    control.disabled = true;
    let url = null;
    try {
        const copy = svg.cloneNode(true);
        copy.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        copy.setAttribute('color', '#173c52');
        const serialized = new XMLSerializer().serializeToString(copy);
        url = URL.createObjectURL(new Blob([serialized], { type: 'image/svg+xml;charset=utf-8' }));
        const bitmap = new Image();
        await new Promise((resolve, reject) => { bitmap.onload = resolve; bitmap.onerror = reject; bitmap.src = url; });
        const canvas = document.createElement('canvas');
        canvas.width = 1400; canvas.height = 740;
        const context = canvas.getContext('2d');
        context.fillStyle = '#f5fafc'; context.fillRect(0, 0, canvas.width, canvas.height);
        context.fillStyle = '#173c52'; context.font = '24px sans-serif'; context.fillText(title, 30, 42, 1340);
        context.drawImage(bitmap, 0, 70, 1400, 660);
        const png = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
        if (!png) throw new Error('PNG unavailable.');
        const downloadUrl = URL.createObjectURL(png);
        const link = document.createElement('a'); link.href = downloadUrl;
        link.download = `${title.replace(/[^a-z0-9]+/gi, '-').slice(0, 80) || 'jarvis-chart'}.png`;
        document.body.append(link); link.click(); link.remove();
        setTimeout(() => URL.revokeObjectURL(downloadUrl), 1000);
    } catch {
        console.warn('JARVIS chart download failed.');
        control.textContent = 'Download unavailable';
    } finally {
        if (url) URL.revokeObjectURL(url);
        control.disabled = false;
    }
}

function chart(block) {
    const visual = { type: 'chart', chart_type: block.type === 'bar_chart' ? 'bar' : (block.type === 'line_chart' ? 'line' : (block.chart_type || 'donut')),
        title: block.title, series: block.series, y_axis: { label: block.unit, format: block.unit === '%' ? 'percent' : 'number' } };
    const root = node('div', 'jc-report-chart');
    const graphic = renderJarvisChart(visual, { palette: ['#087b91', '#6754a4', '#9c6106', '#177252', '#ac4760', '#32649f'] });
    const actions = node('div', 'jc-report-chart-actions');
    const disclosure = node('details', 'jc-report-data');
    disclosure.append(node('summary', '', 'View Data'), table(['Series', 'Label', 'Value'], block.series.flatMap((series) => series.data.map((point) => [
        { value: series.name }, { value: point.label }, { value: point.value, unit: block.unit },
    ]))));
    let download;
    download = button('Download PNG', () => downloadChart(graphic.querySelector('svg'), block.title || 'JARVIS chart', download));
    actions.append(download);
    root.append(graphic, actions, disclosure);
    return root;
}

const blockRenderers = {
    kpi_grid(block) {
        const grid = node('div', 'jc-report-kpis');
        block.items.forEach((item) => {
            const card = node('article', 'jc-report-kpi');
            card.append(node('span', 'jc-report-kpi-label', item.label), node('strong', '', valueLabel(item.value, item.unit)));
            if (item.unit && item.unit !== '%') card.append(node('span', 'jc-report-kpi-unit', item.unit));
            if (item.note) card.append(node('p', 'jc-report-note', item.note));
            grid.append(card);
        });
        return grid;
    },
    progress,
    progress_group(block) {
        const group = node('div', 'jc-report-progress-group');
        block.items.forEach((item) => group.append(progress(item)));
        return group;
    },
    pipeline(block) {
        const stages = node('ol', 'jc-report-pipeline');
        block.stages.forEach((stage) => {
            const step = node('li', `jc-report-stage${stage.value === null ? ' is-unavailable' : ''}${stage.value > 0 ? ' is-recorded' : ''}`);
            step.append(node('span', 'jc-report-stage-marker'), node('h4', '', stage.label), node('strong', '', valueLabel(stage.value)));
            if (stage.unit) step.append(node('span', 'jc-report-stage-unit', stage.unit));
            if (stage.total !== undefined) step.append(node('span', 'jc-report-stage-unit', stage.total === null ? 'Total unavailable' : `of ${valueLabel(stage.total)} total`));
            if (stage.percent !== undefined) step.append(node('span', 'jc-report-stage-percent', stage.percent === null ? 'Completion unavailable' : `${valueLabel(stage.percent, '%', block.executive ? 0 : 2)} complete`));
            if (stage.status) step.append(node('span', 'jc-report-stage-unit', stage.status));
            if (stage.note) step.append(node('p', 'jc-report-note', stage.note));
            stages.append(step);
        });
        return stages;
    },
    bar_chart: chart, line_chart: chart, pie_chart: chart,
    table: (block) => table(block.columns, block.rows),
    key_value(block) {
        const list = node('dl', 'jc-report-key-values');
        block.items.forEach((item) => { const pair = node('div'); pair.append(node('dt', '', item.label), node('dd', '', cellValue(item))); list.append(pair); });
        return list;
    },
    text_summary: (block) => node('p', 'jc-report-summary', block.text),
    timeline(block) {
        const list = node('ol', 'jc-report-timeline');
        block.events.forEach((event) => { const item = node('li'); item.append(node('time', '', new Date(event.date).toLocaleDateString()), node('strong', '', event.label)); if (event.value) item.append(node('p', '', event.value)); list.append(item); });
        return list;
    },
};

export function renderProjectStatus(artifact) {
    validateEnvelope(artifact);
    if (artifact.type !== 'project_status') throw new Error('Invalid project status.');
    const root = node('div', 'jc-report-blocks');
    const project = node('section', 'jc-report-block jc-report-project');
    project.append(node('p', 'jc-report-eyebrow', 'Project / Contract'), node('h3', 'jc-report-project-name', artifact.project.name),
        node('p', 'jc-report-eyebrow jc-report-status-label', 'Current Project Status'),
        node('p', 'jc-report-project-status', artifact.project.status));
    root.append(project);
    const sections = [
        ['Operational Flow', blockRenderers.pipeline({ executive: true, stages: artifact.pipeline.map((stage) => ({ ...stage, percent: stage.percentage,
            status: stage.status || (stage.key === 'dr' && stage.value > 0 ? 'Recorded' : undefined) })) })],
    ];
    if (artifact.overall_progress !== null && artifact.overall_progress !== undefined) {
        sections.push(['Overall Operational Progress', progress({ label: 'Overall Operational Progress', value: artifact.overall_progress, maximumFractionDigits: 0 })]);
    }
    sections.forEach(([title, content]) => {
        const section = node('section', `jc-report-block${title === 'Operational Flow' ? ' jc-report-operational-flow' : ''}`);
        section.append(node('h3', 'jc-report-block-title', title), content);
        root.append(section);
    });
    if (artifact.warehouse_readiness.length) {
        const warehouse = node('section', 'jc-report-block jc-report-warehouse-compact');
        warehouse.append(node('h3', 'jc-report-block-title', 'Warehouse Readiness'));
        const metrics = node('dl', 'jc-report-readiness-metrics');
        artifact.warehouse_readiness.forEach((item) => {
            const metric = node('div');
            metric.append(node('dt', '', item.unit), node('dd', '', valueLabel(item.percentage, '%', 0)));
            metrics.append(metric);
        });
        warehouse.append(metrics);
        root.append(warehouse);
    }
    const details = node('details', 'jc-report-data');
    const rows = artifact.pipeline.map((stage) => [{ value: stage.label }, { value: stage.value, unit: stage.unit },
        { value: stage.total ?? null, unit: stage.unit }, { value: stage.percentage ?? null, unit: '%' }]);
    details.append(node('summary', '', 'View Details'), table(['Stage', 'Recorded', 'Total', 'Completion'], rows));
    if (artifact.warehouse_readiness.length) {
        details.append(table(['Warehouse unit', 'Readiness'], artifact.warehouse_readiness.map((item) => [{ value: item.unit }, { value: item.percentage, unit: '%' }])));
    }
    root.append(details);
    return root;
}

export function renderArtifact(artifact) {
    validateEnvelope(artifact);
    if (artifact.type === 'project_status') return renderProjectStatus(artifact);
    if (artifact.type === 'project_portfolio') return renderPortfolio(artifact);
    const root = node('div', 'jc-report-blocks');
    artifact.blocks.forEach((block) => {
        const section = node('section', `jc-report-block jc-report-block-${block.type}`);
        if (block.title) section.append(node('h3', 'jc-report-block-title', block.title));
        section.append(blockRenderers[block.type](block));
        root.append(section);
    });
    return root;
}

function projectAction(label, projectId, question) {
    return button(label, () => window.dispatchEvent(new CustomEvent('jc:clarification-answer', {
        detail: { answer: `${question} project ID ${projectId}` },
    })));
}

function renderPortfolio(artifact) {
    const block = artifact.blocks.find((item) => item.type === 'table');
    if (!block) return node('p', 'jc-report-summary', artifact.blocks[0]?.text || 'No projects to show.');
    const root = node('div', 'jc-report-portfolio');
    const controls = node('div', 'jc-report-chart-actions');
    const host = node('div');
    let activeOnly = false;
    let sortProgress = false;
    const update = () => {
        let rows = block.rows.filter((row) => !activeOnly || row[5]?.value === true);
        if (sortProgress) rows = [...rows].sort((a, b) => a[2].value === null ? (b[2].value === null ? 0 : 1) : (b[2].value === null ? -1 : b[2].value - a[2].value));
        const grid = table([...block.columns.slice(0, 5), 'Actions'], rows.map((row) => [...row.slice(0, 5), { value: '' }]));
        const body = grid.children[0].children[1];
        rows.forEach((row, index) => {
            const projectId = row[6]?.value;
            if (!Number.isSafeInteger(projectId) || projectId < 1) return;
            const actions = body.children[index].children[5];
            actions.append(projectAction('View project', projectId, 'Show status of'), projectAction('View delivery coverage', projectId, 'Show delivery status of'));
        });
        host.replaceChildren(grid);
    };
    const sort = button('Sort by delivery completion', () => { sortProgress = true; update(); });
    const filter = button('Filter active projects', () => {
        activeOnly = !activeOnly;
        filter.textContent = activeOnly ? 'Show all retrieved projects' : 'Filter active projects';
        filter.setAttribute('aria-pressed', String(activeOnly));
        update();
    });
    filter.setAttribute('aria-pressed', 'false');
    controls.append(sort, filter);
    root.append(controls, host);
    artifact.blocks.filter((item) => item.type !== 'table').forEach((item) => root.append(blockRenderers[item.type](item)));
    update();
    return root;
}

export function renderInlineReport(artifact) {
    validateEnvelope(artifact);
    const root = node('section', 'jc-report-inline');
    root.append(node('h2', 'jc-report-inline-title', artifact.title), renderArtifact(artifact));
    const footer = node('footer', 'jc-report-footer');
    const updated = new Date(artifact.source.retrieved_at);
    footer.append(node('span', '', `Source: ${artifact.source.system}`), node('span', '', `Updated: ${Number.isNaN(updated.getTime()) ? 'Unavailable' : updated.toLocaleString()}`));
    root.append(footer, reportButton(artifact));
    return root;
}

export function openArtifactModal(artifact, focusTarget = document.activeElement) {
    activeModal?.close();
    const dialog = node('dialog', 'jc-report-modal');
    dialog.setAttribute('aria-modal', 'true');
    const titleId = `jc-report-title-${++reportId}`;
    dialog.setAttribute('aria-labelledby', titleId);
    try {
        const header = node('header', 'jc-report-header');
        const heading = node('div');
        const title = node('h2', '', artifact.title); title.id = titleId;
        heading.append(node('p', 'jc-report-eyebrow', artifact.type === 'project_status' ? 'JARVIS / Project Status' : 'JARVIS / Visual Report'), title);
        if (artifact.subtitle) heading.append(node('p', 'jc-report-subtitle', artifact.subtitle));
        heading.append(node('p', 'jc-report-origin', `${artifact.source.system} · ${artifact.source.system === 'User-supplied data' ? 'Provided records' : 'Retrieved data'}`));
        const close = button('Close', () => dialog.close());
        close.setAttribute('aria-label', artifact.type === 'project_status' ? 'Close project status' : 'Close visual report');
        header.append(heading, close);
        const body = node('div', 'jc-report-body'); body.append(renderArtifact(artifact));
        const footer = node('footer', 'jc-report-footer');
        const retrievedAt = new Date(artifact.source.retrieved_at);
        footer.append(node('span', '', `Source: ${artifact.source.system}`), node('span', '', `Updated: ${Number.isNaN(retrievedAt.getTime()) ? 'Unavailable' : retrievedAt.toLocaleString()}`));
        dialog.append(header, body, footer);
        dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
        dialog.addEventListener('close', () => { dialog.remove(); if (activeModal === dialog) activeModal = null; focusTarget?.focus(); });
        document.body.append(dialog);
        dialog.showModal(); activeModal = dialog;
        close.focus();
        return dialog;
    } catch {
        dialog.remove();
        console.warn('JARVIS visual report could not be rendered.');
        return null;
    }
}

export function reportButton(artifact) {
    validateEnvelope(artifact);
    const wrapper = node('div', 'jc-report-entry');
    let control;
    control = button(artifact.type === 'project_status' ? 'View Project Status' : 'View Visual Report', () => {
        if (!openArtifactModal(artifact, control)) {
            control.textContent = 'Report unavailable';
        }
    });
    control.className += ' jc-report-open';
    wrapper.append(control);
    return wrapper;
}

if (typeof window !== 'undefined') window.JarvisArtifactRenderer = { renderArtifact, renderProjectStatus, openArtifactModal };
