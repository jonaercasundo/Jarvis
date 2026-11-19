import { renderJarvisChart } from './jarvis-visuals.js';

const MAX_ARTIFACTS = 3;
const MOCK_ACTION_LABELS = Object.freeze({
    save_draft: 'Save Draft',
    send_email: 'Simulate Send',
    approve: 'Simulate Approve',
    reject: 'Simulate Reject',
    retry: 'Simulate Retry',
    refresh: 'Simulate Refresh',
});
const ALLOWED_STATUSES = new Set([
    'loading', 'ready', 'refreshing', 'editing', 'awaiting_approval', 'executing', 'success', 'error',
]);

function element(tag, className = '', text = null) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== null) node.textContent = String(text ?? '');
    return node;
}

function valueText(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

function renderTable(data) {
    const rows = Array.isArray(data.rows) ? data.rows : [];
    const columns = Array.isArray(data.columns)
        ? data.columns.map((column) => typeof column === 'string' ? { key: column, label: column } : column)
        : (rows[0] ? Object.keys(rows[0]).map((key) => ({ key, label: key })) : []);
    const wrapper = element('div', 'jc-artifact-table-wrap');
    const table = element('table', 'jc-artifact-table');
    const head = element('thead');
    const headerRow = element('tr');
    columns.forEach((column) => headerRow.append(element('th', '', column.label || column.key)));
    head.append(headerRow);
    const body = element('tbody');
    rows.slice(0, 100).forEach((row) => {
        const tr = element('tr');
        columns.forEach((column) => tr.append(element('td', '', valueText(row?.[column.key]))));
        body.append(tr);
    });
    table.append(head, body);
    wrapper.append(table);
    if (rows.length > 100) wrapper.append(element('p', 'jc-artifact-note', `Showing 100 of ${rows.length} rows.`));
    if (!rows.length) wrapper.append(element('p', 'jc-artifact-empty', data.message || 'No records to show.'));
    return wrapper;
}

function renderReport(data) {
    const section = element('div', 'jc-artifact-report');
    if (data.summary) section.append(element('p', 'jc-artifact-summary', data.summary));
    Object.entries(data.metrics || {}).forEach(([label, value]) => {
        const row = element('div', 'jc-artifact-metric');
        row.append(element('span', '', label), element('strong', '', valueText(value)));
        section.append(row);
    });
    if (Array.isArray(data.rows)) section.append(renderTable(data));
    if (!data.summary && !Object.keys(data.metrics || {}).length) section.append(renderDetails(data));
    return section;
}

function chartEntries(data) {
    return (Array.isArray(data.series)
        ? data.series
        : (Array.isArray(data.labels) ? data.labels.map((label, index) => ({ label, value: data.values?.[index] })) : []))
        .slice(0, 48)
        .map((item) => {
            const rawValue = item?.value;
            const numericValue = typeof rawValue === 'number'
                ? rawValue
                : (typeof rawValue === 'string' && rawValue.trim() !== '' ? Number(rawValue) : NaN);
            return {
                label: String(item?.label ?? ''),
                value: Number.isFinite(numericValue) ? numericValue : null,
                kind: item?.kind === 'forecast' ? 'forecast' : 'actual',
            };
        });
}

function svgElement(tag, attributes = {}, text = null) {
    const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, String(value)));
    if (text !== null) node.textContent = String(text);
    return node;
}

function renderLineChart(entries) {
    const values = entries.filter((item) => item.value !== null).map((item) => item.value);
    const minimum = Math.min(...values, 0);
    const maximum = Math.max(...values, 0);
    const range = maximum - minimum || 1;
    const chart = svgElement('svg', { viewBox: '0 0 520 240', role: 'img' });
    chart.style.width = '100%';
    chart.style.height = 'auto';
    chart.style.display = 'block';
    chart.style.color = 'rgba(226, 244, 255, 0.6)';
    chart.append(svgElement('title', {}, 'Trend chart'));
    chart.append(svgElement('desc', {}, entries.map((item) => `${item.label}: ${valueText(item.value)}${item.kind === 'forecast' ? ' (forecast)' : ''}`).join('; ')));
    const x = (index) => 52 + (entries.length === 1 ? 0.5 : index / (entries.length - 1)) * 450;
    const y = (value) => 190 - ((value - minimum) / range) * 160;
    const format = new Intl.NumberFormat(undefined, { maximumFractionDigits: 2, notation: maximum >= 100000 ? 'compact' : 'standard' });

    for (let tick = 0; tick <= 4; tick += 1) {
        const value = minimum + (range * tick) / 4;
        const position = y(value);
        chart.append(svgElement('line', { x1: 52, y1: position, x2: 502, y2: position, stroke: 'currentColor', 'stroke-opacity': 0.14 }));
        chart.append(svgElement('text', { x: 43, y: position + 4, fill: 'currentColor', 'text-anchor': 'end', 'font-size': 11 }, format.format(value)));
    }

    entries.forEach((item, index) => {
        if (item.value === null) return;
        const previous = entries[index - 1];
        const forecast = item.kind === 'forecast';
        if (previous?.value !== null && previous?.value !== undefined) {
            const segmentForecast = forecast || previous.kind === 'forecast';
            chart.append(svgElement('line', {
                x1: x(index - 1), y1: y(previous.value), x2: x(index), y2: y(item.value),
                stroke: segmentForecast ? '#c4b5fd' : '#67e8f9', 'stroke-width': 2.5,
                ...(segmentForecast ? { 'stroke-dasharray': '6 5' } : {}),
            }));
        }
        const point = svgElement('circle', {
            cx: x(index), cy: y(item.value), r: 4,
            fill: forecast ? '#10283a' : '#67e8f9',
            stroke: forecast ? '#c4b5fd' : '#67e8f9', 'stroke-width': 2,
        });
        point.append(svgElement('title', {}, `${item.label}: ${format.format(item.value)}${forecast ? ' (forecast)' : ''}`));
        chart.append(point);
    });

    const labelInterval = Math.max(1, Math.ceil(entries.length / 8));
    entries.forEach((item, index) => {
        if (index % labelInterval !== 0 && index !== entries.length - 1) return;
        const label = svgElement('text', { x: x(index), y: 217, fill: 'currentColor', 'text-anchor': 'middle', 'font-size': 11 }, item.label.slice(0, 12));
        label.append(svgElement('title', {}, item.label));
        chart.append(label);
    });
    return chart;
}

function renderBarChart(entries) {
    const section = element('div', 'jc-artifact-chart');
    const minimum = Math.min(...entries.map((item) => item.value ?? 0), 0);
    const maximum = Math.max(...entries.map((item) => item.value ?? 0), 0);
    const range = maximum - minimum || 1;
    const origin = (-minimum / range) * 100;
    entries.forEach((item) => {
        const row = element('div', 'jc-artifact-bar-row');
        row.append(element('span', 'jc-artifact-bar-label', item.label));
        const track = element('div', 'jc-artifact-bar-track');
        track.style.position = 'relative';
        if (minimum < 0 && maximum > 0) {
            const baseline = element('span');
            Object.assign(baseline.style, { position: 'absolute', left: `${origin}%`, top: '0', height: '100%', width: '1px', background: 'rgba(226, 244, 255, 0.6)' });
            track.append(baseline);
        }
        if (item.value !== null) {
            const bar = element('span', 'jc-artifact-bar');
            bar.style.position = 'absolute';
            bar.style.left = `${item.value < 0 ? ((item.value - minimum) / range) * 100 : origin}%`;
            bar.style.width = `${(Math.abs(item.value) / range) * 100}%`;
            if (item.kind === 'forecast') {
                bar.style.background = 'repeating-linear-gradient(90deg, #c4b5fd 0 4px, transparent 4px 7px)';
            }
            track.append(bar);
        }
        row.append(track, element('strong', '', valueText(item.value)));
        section.append(row);
    });
    return section;
}

function renderChart(data, artifact) {
    if (Array.isArray(data.series?.[0]?.data)) return renderJarvisChart(data);
    const entries = chartEntries(data);
    const section = element('div', 'jc-artifact-chart');
    if (!entries.length) return renderDetails(data);
    if (data.is_sample || artifact?.is_mock) section.append(element('p', 'jc-artifact-note', 'Illustrative sample data'));
    if (entries.every((item) => item.value === null)) {
        section.append(element('p', 'jc-artifact-empty', 'No numeric values to show.'));
        return section;
    }
    const hasForecast = entries.some((item) => item.kind === 'forecast');
    const chartType = data.chart_type || (hasForecast || ['trend', 'forecast'].includes(data.purpose)
        ? 'line'
        : (entries.length === 1 || data.purpose === 'single_number' ? 'kpi' : (data.purpose === 'records' ? 'table' : 'bar')));
    if (chartType === 'line' || chartType === 'forecast') {
        section.append(renderLineChart(entries));
    } else if (chartType === 'kpi' && entries.length === 1) {
        const metric = element('div', 'jc-artifact-metric');
        const value = element('strong', '', valueText(entries[0].value));
        value.style.fontSize = '1.8rem';
        metric.append(element('span', '', entries[0].label), value);
        section.append(metric);
    } else if (chartType === 'table') {
        section.append(renderTable({ columns: ['label', 'value', ...(hasForecast ? ['kind'] : [])], rows: entries }));
    } else {
        section.append(renderBarChart(entries));
    }
    if (hasForecast) section.append(element('p', 'jc-artifact-note', 'Solid: actual. Dashed: forecast estimate.'));
    if (entries.some((item) => item.value === null)) section.append(element('p', 'jc-artifact-note', 'Missing values are shown as gaps.'));
    const totalEntries = Array.isArray(data.series) ? data.series.length : (Array.isArray(data.labels) ? data.labels.length : 0);
    if (totalEntries > entries.length) section.append(element('p', 'jc-artifact-note', `Showing the first ${entries.length} of ${totalEntries} values.`));
    if (data.summary) section.append(element('p', 'jc-artifact-summary', String(data.summary).slice(0, 280)));
    return section;
}

function renderEmail(data, artifact) {
    const section = element('div', 'jc-artifact-email');
    const isEditableDemo = Boolean(artifact?.is_mock && document.getElementById('jc-app')?.dataset.testMode === 'true');
    [['To', 'to'], ['CC', 'cc'], ['Subject', 'subject']].forEach(([label, key]) => {
        const row = element('div', 'jc-artifact-email-field');
        row.append(element('span', '', label));
        if (isEditableDemo) {
            const input = element('input', 'jc-artifact-email-input');
            input.type = 'text';
            input.value = data[key] || '';
            input.setAttribute('aria-label', label);
            input.addEventListener('input', () => {
                data[key] = input.value;
                setArtifactStatus(artifact, 'editing');
            });
            row.append(input);
        } else {
            row.append(element('p', '', valueText(data[key])));
        }
        section.append(row);
    });
    if (isEditableDemo) {
        const body = element('textarea', 'jc-artifact-email-body jc-artifact-email-editor', data.body || '');
        body.rows = 8;
        body.setAttribute('aria-label', 'Email message');
        body.addEventListener('input', () => {
            data.body = body.value;
            setArtifactStatus(artifact, 'editing');
        });
        section.append(body);
    } else {
        section.append(element('pre', 'jc-artifact-email-body', data.body || ''));
    }
    if (artifact?.is_mock) {
        section.append(element('p', 'jc-artifact-approval-note', 'Demo email only. Save and Send actions are simulated; nothing is sent.'));
    } else if (data.confirmation_code) {
        section.append(element('p', 'jc-artifact-approval-note', `This draft has not been sent. To send it, type “confirm ${data.confirmation_code}” in the JARVIS conversation.`));
    }
    if (data.message) section.append(element('p', 'jc-artifact-note', data.message));
    return section;
}

function renderAgentStatus(data) {
    const list = element('div', 'jc-agent-status-list');
    (Array.isArray(data.agents) ? data.agents : []).slice(0, 100).forEach((agent) => {
        const card = element('article', 'jc-agent-status-card');
        const header = element('div', 'jc-agent-status-header');
        header.append(element('strong', '', agent.label || 'Agent'));
        const status = element('span', `jc-agent-status-pill is-${agent.status || 'offline'}`, (agent.status || 'offline').replaceAll('_', ' '));
        header.append(status);
        card.append(header);
        const counts = element('div', 'jc-agent-status-counts');
        [['Completed', agent.today?.successful ?? 0], ['Failed', agent.today?.failed ?? 0], ['Processed', agent.today?.processed ?? 0]].forEach(([label, value]) => {
            const item = element('div');
            item.append(element('strong', '', valueText(value)), element('span', '', label));
            counts.append(item);
        });
        card.append(counts);
        if (agent.current_task) card.append(element('p', 'jc-artifact-note', `Current task: ${agent.current_task.type || 'Task'} · ${agent.current_task.status}`));
        if (agent.last_error) card.append(element('p', 'jc-artifact-error', agent.last_error));
        list.append(card);
    });
    if (!list.childElementCount) list.append(element('p', 'jc-artifact-empty', 'No Agent records were returned.'));
    return list;
}

function renderWorkflowRun(data) {
    return renderWorkflowGraph(data);
}

function renderDetails(data) {
    const list = element('dl', 'jc-artifact-details');
    Object.entries(data || {}).slice(0, 40).forEach(([key, value]) => {
        const row = element('div');
        row.append(element('dt', '', key.replaceAll('_', ' ')), element('dd', '', valueText(value)));
        list.append(row);
    });
    return list;
}

function renderApproval(data) {
    const section = element('div', 'jc-artifact-report');
    section.append(element('p', 'jc-artifact-summary', data.summary || 'This action is waiting for review.'));
    if (data.owner) section.append(element('p', 'jc-artifact-note', `Prepared by ${data.owner}.`));
    return section;
}

function renderTaskList(data) {
    return renderTable({ columns: data.columns, rows: data.tasks || data.rows || [] });
}

function renderSyncStatus(data) {
    return renderReport({ summary: data.summary, metrics: data.metrics || data.counts });
}

function renderWorkflowGraph(data) {
    const nodes = Array.isArray(data.nodes) ? data.nodes : [];
    const edges = Array.isArray(data.edges) ? data.edges : [];
    if (!nodes.length) return renderDetails(data);

    const graph = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    const positions = graphPositions(nodes, edges);
    const levels = Math.max(...positions.map((position) => position.level), 0);
    const height = Math.max(230, (levels + 1) * 78 + 24);
    graph.setAttribute('viewBox', `0 0 520 ${height}`);
    graph.setAttribute('role', 'img');
    graph.setAttribute('aria-label', data.simulation ? 'Simulated workflow execution graph' : 'Workflow graph');
    graph.classList.add('jc-artifact-workflow-graph');
    const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
    const marker = document.createElementNS('http://www.w3.org/2000/svg', 'marker');
    marker.setAttribute('id', 'jc-workflow-arrow');
    marker.setAttribute('viewBox', '0 0 10 10');
    marker.setAttribute('refX', '8');
    marker.setAttribute('refY', '5');
    marker.setAttribute('markerWidth', '5');
    marker.setAttribute('markerHeight', '5');
    marker.setAttribute('orient', 'auto-start-reverse');
    const arrow = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    arrow.setAttribute('d', 'M 0 0 L 10 5 L 0 10 z');
    arrow.setAttribute('class', 'jc-workflow-arrow');
    marker.append(arrow);
    defs.append(marker);
    graph.append(defs);

    edges.forEach((edge) => {
        const source = positions.find((position) => position.node.id === edge.source);
        const target = positions.find((position) => position.node.id === edge.target);
        if (!source || !target) return;
        const line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        const startY = source.y + 20;
        const endY = target.y - 20;
        const midpoint = (startY + endY) / 2;
        line.setAttribute('d', `M ${source.x} ${startY} C ${source.x} ${midpoint}, ${target.x} ${midpoint}, ${target.x} ${endY}`);
        line.setAttribute('class', `jc-workflow-edge is-${edge.status || 'waiting'}`);
        line.setAttribute('marker-end', 'url(#jc-workflow-arrow)');
        graph.append(line);
    });

    positions.forEach(({ node, x, y }) => {
        const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        group.setAttribute('class', `jc-workflow-node is-${node.status || 'ready'}`);
        const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        rect.setAttribute('x', String(x - 82));
        rect.setAttribute('y', String(y - 20));
        rect.setAttribute('width', '164');
        rect.setAttribute('height', '40');
        rect.setAttribute('rx', '10');
        const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        label.setAttribute('x', String(x));
        label.setAttribute('y', String(y + 4));
        label.textContent = node.label || node.name || 'Step';
        const status = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        status.setAttribute('x', String(x));
        status.setAttribute('y', String(y + 16));
        status.setAttribute('class', 'jc-workflow-node-status');
        status.textContent = (node.status || 'ready').replaceAll('_', ' ');
        group.append(rect, label, status);
        graph.append(group);
    });

    const section = element('div', 'jc-artifact-graph');
    section.append(graph);
    if (data.message) section.append(element('p', data.simulation === 'failure' ? 'jc-artifact-error' : 'jc-artifact-note', data.message));
    return section;
}

function graphPositions(nodes, edges) {
    const levels = new Map(nodes.map((node) => [node.id, 0]));
    for (let pass = 0; pass < nodes.length; pass += 1) {
        edges.forEach((edge) => {
            if (levels.has(edge.source) && levels.has(edge.target)) {
                levels.set(edge.target, Math.max(levels.get(edge.target), levels.get(edge.source) + 1));
            }
        });
    }
    const grouped = new Map();
    nodes.forEach((node) => {
        const level = levels.get(node.id) || 0;
        grouped.set(level, [...(grouped.get(level) || []), node]);
    });
    return [...grouped.entries()].flatMap(([level, group]) => group.map((node, index) => ({
        node,
        level,
        x: 260 + (index - (group.length - 1) / 2) * 190,
        y: 38 + level * 76,
    })));
}

export const artifactRenderers = Object.freeze({
    table: renderTable,
    report: renderReport,
    chart: renderChart,
    email: renderEmail,
    workflow_graph: renderWorkflowGraph,
    workflow_run: renderWorkflowRun,
    agent_status: renderAgentStatus,
    task_list: renderTaskList,
    sync_status: renderSyncStatus,
    approval: renderApproval,
    details: renderDetails,
});

function button(label, action, title) {
    const control = element('button', 'jc-workspace-control', label);
    control.type = 'button';
    control.dataset.action = action;
    control.title = title;
    control.setAttribute('aria-label', title);
    return control;
}

function setArtifactStatus(artifact, status) {
    artifact.status = status;
    const card = [...document.querySelectorAll('[data-artifact-id]')]
        .find((candidate) => candidate.dataset.artifactId === artifact.id);
    const badge = card?.querySelector('.jc-artifact-status');
    if (badge) {
        badge.className = `jc-artifact-status is-${status}`;
        badge.textContent = status.replaceAll('_', ' ');
    }
}

function renderMockActions(artifact) {
    if (!artifact.is_mock || document.getElementById('jc-app')?.dataset.testMode !== 'true') return null;
    const actions = (Array.isArray(artifact.actions) ? artifact.actions : [])
        .filter((action) => Object.hasOwn(MOCK_ACTION_LABELS, action));
    if (!actions.length) return null;
    const controls = element('div', 'jc-artifact-actions');
    actions.forEach((action) => {
        controls.append(button(MOCK_ACTION_LABELS[action], `mock:${action}`, MOCK_ACTION_LABELS[action]));
    });
    return controls;
}

function renderArtifact(artifact) {
    const card = element('article', 'jc-artifact-card');
    card.dataset.artifactId = artifact.id;
    const header = element('header', 'jc-artifact-card-header');
    const heading = element('div', 'jc-artifact-heading');
    heading.append(element('h3', '', artifact.title), element('p', '', artifact.subtitle || artifact.source || ''));
    const controls = element('div', 'jc-artifact-controls');
    controls.append(button('−', 'minimize', 'Minimize artifact'), button('□', 'maximize', 'Maximize artifact'), button('×', 'close', 'Close artifact'));
    header.append(heading, controls);
    const metadata = element('div', 'jc-artifact-metadata');
    metadata.append(element('span', `jc-artifact-status is-${artifact.status}`, (artifact.status || 'ready').replaceAll('_', ' ')));
    if (artifact.is_mock) metadata.append(element('span', 'jc-artifact-mock-badge', 'TEST DATA'));
    if (artifact.data_kind) metadata.append(element('span', '', artifact.data_kind.replaceAll('_', ' ')));
    if (artifact.source) metadata.append(element('span', '', `Source · ${artifact.source}`));
    const renderer = artifactRenderers[artifact.type] || renderDetails;
    const content = element('div', 'jc-artifact-content');
    content.append(renderer(artifact.data || {}, artifact));
    const actions = renderMockActions(artifact);
    if (actions) content.append(actions);
    const footer = element('footer', 'jc-artifact-card-footer');
    if (artifact.owner_agent) footer.append(element('span', '', `Owner · ${artifact.owner_agent}`));
    if (artifact.generated_at) footer.append(element('time', '', new Date(artifact.generated_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })));
    card.append(header, metadata, content, footer);
    return card;
}

function initializeArtifactWorkspace() {
    const workspace = document.getElementById('jc-workspace');
    const host = document.getElementById('jc-workspace-artifacts');
    const minimizedHost = document.getElementById('jc-workspace-minimized');
    const stage = document.getElementById('jc-workspace-stage');
    if (!workspace || !host || !minimizedHost || !stage) return;

    let artifacts = [];
    let minimized = new Set();
    let widthPercent = 48;
    const app = document.getElementById('jc-app');
    const testModeEnabled = app?.dataset.testMode === 'true';

    const repaint = () => {
        host.replaceChildren();
        minimizedHost.replaceChildren();
        artifacts.filter((artifact) => !minimized.has(artifact.id)).forEach((artifact) => host.append(renderArtifact(artifact)));
        artifacts.filter((artifact) => minimized.has(artifact.id)).forEach((artifact) => {
            const restore = button(artifact.title, 'restore', `Restore ${artifact.title}`);
            restore.dataset.artifactId = artifact.id;
            minimizedHost.append(restore);
        });
        workspace.hidden = artifacts.length === 0;
        app?.classList.toggle('jc-workspace-active', artifacts.length > 0);
        app?.classList.toggle('jc-workspace-collapsed', workspace.classList.contains('is-collapsed'));
        app?.style.setProperty('--jc-workspace-width', `${workspace.classList.contains('is-collapsed') ? '3.5rem' : `${widthPercent}%`}`);
        workspace.style.setProperty('--jc-workspace-width', `${widthPercent}%`);
        const count = document.getElementById('jc-workspace-count');
        if (count) count.textContent = String(artifacts.length);
        stage.classList.toggle('has-artifacts', artifacts.length > 0);
        workspace.dataset.count = String(artifacts.length);
    };

    const show = (incoming) => {
        if (!Array.isArray(incoming)) return;
        const normalized = incoming.filter((artifact) => artifact && artifactRenderers[artifact.type]
                && (!artifact.is_mock || testModeEnabled)
                && typeof artifact.title === 'string' && artifact.data && typeof artifact.data === 'object')
            .slice(0, MAX_ARTIFACTS)
            .map((artifact) => ({ ...artifact, status: ALLOWED_STATUSES.has(artifact.status) ? artifact.status : 'ready' }));
        if (!normalized.length) return;
        const retained = artifacts.filter((artifact) => artifact.pinned && !normalized.some((next) => next.type === artifact.type));
        artifacts = [...retained, ...normalized].slice(0, MAX_ARTIFACTS);
        minimized.clear();
        repaint();
        normalized.filter((artifact) => artifact.is_mock && artifact.data.simulation)
            .forEach((artifact) => { void animateWorkflow(artifact, artifact.data.simulation === 'failure'); });
    };

    host.addEventListener('click', (event) => {
        const control = event.target.closest('[data-action]');
        if (!control) return;
        const card = control.closest('[data-artifact-id]');
        const id = card?.dataset.artifactId || control.dataset.artifactId;
        if (control.dataset.action?.startsWith('mock:')) {
            const artifact = artifacts.find((item) => item.id === id);
            if (artifact?.is_mock && testModeEnabled) void simulateMockAction(artifact, control.dataset.action.slice(5));
            return;
        }
        if (control.dataset.action === 'close') artifacts = artifacts.filter((item) => item.id !== id);
        if (control.dataset.action === 'minimize') minimized.add(id);
        if (control.dataset.action === 'maximize') {
            card?.classList.toggle('is-maximized');
            return;
        }
        repaint();
    });
    minimizedHost.addEventListener('click', (event) => {
        const control = event.target.closest('[data-action="restore"]');
        if (control) minimized.delete(control.dataset.artifactId);
        repaint();
    });
    workspace.querySelector('[data-workspace-action="close-all"]')?.addEventListener('click', () => {
        artifacts = [];
        minimized.clear();
        repaint();
    });
    workspace.querySelector('[data-workspace-action="conversation"]')?.addEventListener('click', () => {
        document.getElementById('jc-app')?.classList.toggle('jc-mobile-conversation');
    });
    document.getElementById('jc-mobile-workspace-toggle')?.addEventListener('click', () => {
        document.getElementById('jc-app')?.classList.remove('jc-mobile-conversation');
    });
    workspace.querySelector('[data-workspace-action="collapse"]')?.addEventListener('click', (event) => {
        workspace.classList.toggle('is-collapsed');
        const collapsed = workspace.classList.contains('is-collapsed');
        event.currentTarget.textContent = collapsed ? '+' : '−';
        event.currentTarget.setAttribute('aria-label', collapsed ? 'Expand workspace' : 'Collapse workspace');
        repaint();
    });

    const wait = (duration) => new Promise((resolve) => window.setTimeout(resolve, duration));

    async function animateWorkflow(artifact, shouldFail) {
        if (!testModeEnabled || !artifact.is_mock) return;
        artifact.status = 'executing';
        artifact.data.nodes.forEach((node) => { node.status = 'waiting'; });
        artifact.data.edges.forEach((edge) => { edge.status = 'waiting'; });
        repaint();
        const order = ['operations', 'masterlist', 'tracker', 'sap'];

        for (const id of order) {
            const node = artifact.data.nodes.find((item) => item.id === id);
            if (!node) continue;
            node.status = 'running';
            updateEdgeStatuses(artifact.data);
            repaint();
            await wait(420);
            node.status = shouldFail && id === 'sap' ? 'failed' : 'success';
            updateEdgeStatuses(artifact.data);
            repaint();
            await wait(260);
            if (node.status === 'failed') break;
        }

        artifact.status = shouldFail ? 'error' : 'success';
        artifact.data.message = shouldFail
            ? 'TEST ERROR: SAP validation failed. No SAP request was made.'
            : 'TEST RUN COMPLETE. No external system was contacted.';
        repaint();
    }

    function updateEdgeStatuses(data) {
        data.edges.forEach((edge) => {
            const source = data.nodes.find((node) => node.id === edge.source)?.status;
            const target = data.nodes.find((node) => node.id === edge.target)?.status;
            edge.status = source === 'failed' || target === 'failed'
                ? 'failed'
                : (source === 'success' && target === 'success' ? 'success' : (target === 'running' ? 'running' : 'waiting'));
        });
    }

    async function simulateMockAction(artifact, action) {
        if (!testModeEnabled || !artifact.is_mock) return;
        setArtifactStatus(artifact, action === 'refresh' ? 'refreshing' : 'executing');
        await wait(500);

        if (action === 'retry' && artifact.data.nodes && artifact.data.edges) {
            artifact.data.simulation = 'success';
            artifact.data.message = 'Test retry running. No external system was contacted.';
            await animateWorkflow(artifact, false);
            return;
        }

        setArtifactStatus(artifact, 'success');
        const messages = {
            save_draft: 'TEST DRAFT SAVED. No email was sent.',
            send_email: 'TEST SEND COMPLETE. No email was sent.',
            approve: 'TEST APPROVAL COMPLETE. No Agent action was performed.',
            reject: 'TEST ACTION REJECTED. No Agent action was performed.',
            retry: 'TEST RETRY COMPLETE. No external system was contacted.',
            refresh: 'TEST DATA REFRESHED from the fixed fixture.',
        };
        artifact.data.message = messages[action] || 'TEST ACTION COMPLETE.';
        repaint();
    }

    const splitter = workspace.querySelector('[data-workspace-splitter]');
    splitter?.addEventListener('pointerdown', (event) => {
        if (window.matchMedia('(max-width: 760px)').matches) return;
        splitter.setPointerCapture(event.pointerId);
        const move = (pointerEvent) => {
            widthPercent = Math.max(34, Math.min(66, ((window.innerWidth - pointerEvent.clientX) / window.innerWidth) * 100));
            workspace.style.setProperty('--jc-workspace-width', `${widthPercent}%`);
            document.getElementById('jc-app')?.style.setProperty('--jc-workspace-width', `${widthPercent}%`);
        };
        const stop = () => {
            splitter.removeEventListener('pointermove', move);
            splitter.removeEventListener('pointerup', stop);
            splitter.removeEventListener('pointercancel', stop);
        };
        splitter.addEventListener('pointermove', move);
        splitter.addEventListener('pointerup', stop);
        splitter.addEventListener('pointercancel', stop);
    });

    window.jcArtifactWorkspace = { show, renderers: artifactRenderers };
    repaint();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeArtifactWorkspace, { once: true });
} else {
    initializeArtifactWorkspace();
}
