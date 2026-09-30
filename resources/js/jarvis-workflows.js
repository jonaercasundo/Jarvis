(() => {
    const root = document.getElementById('jc-workflow-builder');
    if (!root) return;

    const $ = (id) => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const api = {
        list: '/jarvis/workflows/data',
        catalog: '/jarvis/workflows/catalog',
        collection: '/jarvis/workflows',
        propose: '/jarvis/workflows/propose',
    };

    let catalog = {};
    let workflows = [];
    let current = null;
    let selectedNodeId = null;
    let transform = { x: 80, y: 70, scale: 0.85 };
    let pendingSource = null;
    let gesture = null;
    let saveTimer = null;
    let webhookUrl = '';

    const clone = (value) => JSON.parse(JSON.stringify(value));
    const graph = () => current?.workflow_data || { nodes: [], edges: [] };
    const nodeById = (id) => graph().nodes.find((node) => node.id === id);

    function showMessage(message, error = false) {
        const el = $('wf-message');
        el.textContent = message;
        el.classList.remove('hidden', 'text-red-300', 'text-emerald-200', 'text-white/50');
        el.classList.add(error ? 'text-red-300' : 'text-emerald-200');
        clearTimeout(saveTimer);
        if (!error) saveTimer = setTimeout(() => el.classList.add('hidden'), 5000);
    }

    async function request(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            headers: {
                Accept: 'application/json',
                ...(options.body ? { 'Content-Type': 'application/json' } : {}),
                ...(options.method && options.method !== 'GET' ? { 'X-CSRF-TOKEN': csrf } : {}),
                ...(options.headers || {}),
            },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.success === false) {
            const validation = data.errors ? Object.values(data.errors).flat().join(' ') : '';
            throw new Error(validation || data.error || data.message || `Request failed (${response.status}).`);
        }
        return data;
    }

    function escapeText(value) {
        return String(value ?? '');
    }

    function workflowData() {
        return {
            nodes: graph().nodes.map((node) => ({
                id: node.id,
                type: node.type,
                label: node.label || '',
                position: { x: Number(node.position?.x || 0), y: Number(node.position?.y || 0) },
                config: node.config || {},
            })),
            edges: graph().edges.map((edge) => ({ source: edge.source, target: edge.target })),
        };
    }

    function renderWorkflowList() {
        const list = $('wf-list');
        list.replaceChildren();
        if (!workflows.length) {
            const empty = document.createElement('div');
            empty.className = 'px-2 py-3 text-[11px] leading-5 text-white/30';
            empty.textContent = 'No saved workflows yet.';
            list.append(empty);
        }
        workflows.forEach((workflow) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `mb-1 flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2.5 text-left transition ${current?.id === workflow.id ? 'bg-cyan-200/[.08] text-cyan-50' : 'text-white/60 hover:bg-white/[.04] hover:text-white/80'}`;
            const name = document.createElement('span');
            name.className = 'min-w-0 truncate text-xs';
            name.textContent = workflow.name;
            const status = document.createElement('span');
            status.className = `shrink-0 text-[8px] uppercase tracking-wider ${workflow.status === 'active' ? 'text-emerald-300' : workflow.status === 'error' ? 'text-red-300' : 'text-white/25'}`;
            status.textContent = workflow.status;
            button.append(name, status);
            button.addEventListener('click', () => loadWorkflow(workflow.id));
            list.append(button);
        });
    }

    function renderPalette() {
        const palette = $('wf-palette');
        palette.replaceChildren();
        Object.entries(catalog).forEach(([type, definition]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.draggable = true;
            button.dataset.nodeType = type;
            button.className = 'group w-full rounded-xl border border-transparent px-2.5 py-2 text-left transition hover:border-white/[.08] hover:bg-white/[.035]';
            const label = document.createElement('div');
            label.className = 'flex items-center gap-2 text-xs text-white/75 group-hover:text-white';
            const icon = document.createElement('span');
            icon.className = `flex h-7 w-7 items-center justify-center rounded-lg text-[10px] ${definition.category === 'trigger' ? 'bg-cyan-300/10 text-cyan-100' : 'bg-violet-300/10 text-violet-100'}`;
            icon.textContent = definition.category === 'trigger' ? '↯' : '✦';
            const title = document.createElement('span');
            title.textContent = definition.label;
            label.append(icon, title);
            const description = document.createElement('div');
            description.className = 'mt-1 pl-9 text-[9px] leading-4 text-white/30';
            description.textContent = definition.description;
            button.append(label, description);
            button.addEventListener('click', () => addNode(type));
            button.addEventListener('dragstart', (event) => {
                event.dataTransfer.setData('application/x-jc-workflow-node', type);
                event.dataTransfer.effectAllowed = 'copy';
            });
            palette.append(button);
        });
    }

    function setTransform() {
        const world = $('wf-world');
        world.style.transform = `translate(${transform.x}px, ${transform.y}px) scale(${transform.scale})`;
        $('wf-zoom-label').textContent = `${Math.round(transform.scale * 100)}%`;
    }

    function renderNodes() {
        const layer = $('wf-nodes');
        layer.replaceChildren();
        const nodes = graph().nodes;
        $('wf-drop-hint').classList.toggle('hidden', nodes.length > 0);
        $('wf-node-count').textContent = `${nodes.length} ${nodes.length === 1 ? 'step' : 'steps'}`;

        nodes.forEach((node) => {
            const definition = catalog[node.type] || { label: node.type, category: 'action' };
            const card = document.createElement('article');
            card.className = 'wf-node rounded-xl border border-white/10 bg-[#0c1422]/95 shadow-[0_12px_36px_rgba(0,0,0,.28)] backdrop-blur';
            card.dataset.nodeId = node.id;
            card.dataset.category = definition.category;
            card.dataset.selected = String(selectedNodeId === node.id);
            card.style.left = `${Number(node.position?.x || 0)}px`;
            card.style.top = `${Number(node.position?.y || 0)}px`;

            const header = document.createElement('div');
            header.className = 'wf-node-header flex cursor-grab items-center gap-2 border-b border-white/[.07] px-3 py-2.5 active:cursor-grabbing';
            const icon = document.createElement('span');
            icon.className = `flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-[10px] ${definition.category === 'trigger' ? 'bg-cyan-300/10 text-cyan-100' : 'bg-violet-300/10 text-violet-100'}`;
            icon.textContent = definition.category === 'trigger' ? '↯' : '✦';
            const titleWrap = document.createElement('div');
            titleWrap.className = 'min-w-0 flex-1';
            const title = document.createElement('div');
            title.className = 'truncate text-[11px] font-medium text-white/85';
            title.textContent = node.label || definition.label;
            const subtitle = document.createElement('div');
            subtitle.className = 'mt-0.5 text-[8px] uppercase tracking-[.14em] text-white/30';
            subtitle.textContent = definition.category === 'trigger' ? 'Trigger' : 'Action';
            titleWrap.append(title, subtitle);
            header.append(icon, titleWrap);
            card.append(header);

            const preview = document.createElement('div');
            preview.className = 'px-3 py-3 text-[10px] leading-4 text-white/40';
            preview.textContent = nodePreview(node);
            card.append(preview);

            const incoming = document.createElement('button');
            incoming.type = 'button';
            incoming.disabled = current?.status === 'active';
            incoming.className = 'wf-port wf-port-in';
            incoming.title = 'Connect from another step';
            incoming.setAttribute('aria-label', `Connect to ${node.label || definition.label}`);
            incoming.addEventListener('pointerdown', (event) => event.stopPropagation());
            incoming.addEventListener('click', (event) => {
                event.stopPropagation();
                if (!pendingSource) {
                    showMessage('Choose an output port first.', true);
                    return;
                }
                if (pendingSource === node.id) return;
                if (!graph().edges.some((edge) => edge.source === pendingSource && edge.target === node.id)) {
                    graph().edges.push({ source: pendingSource, target: node.id });
                    selectedNodeId = node.id;
                    pendingSource = null;
                    renderAll();
                }
            });
            const outgoing = document.createElement('button');
            outgoing.type = 'button';
            outgoing.disabled = current?.status === 'active';
            outgoing.className = 'wf-port wf-port-out';
            outgoing.title = 'Choose a step to connect to';
            outgoing.setAttribute('aria-label', `Connect from ${node.label || definition.label}`);
            outgoing.addEventListener('pointerdown', (event) => event.stopPropagation());
            outgoing.addEventListener('click', (event) => {
                event.stopPropagation();
                pendingSource = node.id;
                showMessage(`Now choose the input port on the next step.`);
            });
            card.append(incoming, outgoing);

            card.addEventListener('click', () => {
                selectedNodeId = node.id;
                renderAll();
            });
            header.addEventListener('pointerdown', (event) => {
                if (event.button !== 0) return;
                if (current?.status === 'active') return;
                event.preventDefault();
                event.stopPropagation();
                selectedNodeId = node.id;
                gesture = {
                    kind: 'node', nodeId: node.id,
                    x: event.clientX, y: event.clientY,
                    startX: Number(node.position?.x || 0), startY: Number(node.position?.y || 0),
                };
                card.setPointerCapture?.(event.pointerId);
            });
            layer.append(card);
        });
        renderEdges();
        setTransform();
    }

    function nodePreview(node) {
        const config = node.config || {};
        switch (node.type) {
            case 'email_trigger': return config.subject_contains ? `Subject contains “${config.subject_contains}”` : 'When a new email arrives';
            case 'schedule_trigger': return `${config.frequency || 'daily'} · ${config.time || '09:00'} ${config.timezone || ''}`.trim();
            case 'webhook_trigger': return 'Waits for a signed webhook event';
            case 'jarvis_ai': return config.instruction || 'Add an instruction in step settings';
            case 'send_email': return config.to ? `To ${config.to}` : 'Configure recipient and message';
            case 'webhook_action': return config.url || 'Configure an HTTPS endpoint';
            default: return '';
        }
    }

    function renderEdges() {
        const svg = $('wf-edges');
        svg.replaceChildren();
        const ns = 'http://www.w3.org/2000/svg';
        graph().edges.forEach((edge) => {
            const source = nodeById(edge.source);
            const target = nodeById(edge.target);
            if (!source || !target) return;
            const x1 = Number(source.position?.x || 0) + 236;
            const y1 = Number(source.position?.y || 0) + 56;
            const x2 = Number(target.position?.x || 0);
            const y2 = Number(target.position?.y || 0) + 56;
            const bend = Math.max(50, Math.abs(x2 - x1) * 0.48);
            const path = document.createElementNS(ns, 'path');
            path.setAttribute('d', `M ${x1} ${y1} C ${x1 + bend} ${y1}, ${x2 - bend} ${y2}, ${x2} ${y2}`);
            path.setAttribute('class', 'wf-line');
            svg.append(path);
        });
    }

    function renderStatus() {
        const status = $('wf-status');
        const pause = $('wf-pause');
        if (!current) {
            status.classList.add('hidden');
            $('wf-current-name').textContent = 'Choose a workflow or create one';
            $('wf-test').disabled = true;
            $('wf-activate').disabled = true;
            $('wf-save').disabled = true;
            if (pause) pause.remove();
            webhookUrl = '';
            renderWebhookCard();
            return;
        }
        $('wf-current-name').textContent = current.description || current.name || 'Untitled workflow';
        status.textContent = current.status || 'draft';
        status.className = `rounded-full border px-2.5 py-1 text-[10px] uppercase tracking-wider ${current.status === 'active' ? 'border-emerald-300/20 bg-emerald-300/[.06] text-emerald-200' : current.status === 'error' ? 'border-red-300/20 bg-red-300/[.06] text-red-200' : 'border-white/10 bg-white/[.03] text-white/45'}`;
        $('wf-test').disabled = false;
        $('wf-activate').disabled = false;
        $('wf-save').disabled = current.status === 'active';
        $('wf-save').title = current.status === 'active' ? 'Pause the workflow before saving changes.' : '';
        $('wf-activate').textContent = current.status === 'active' ? 'Active' : 'Activate';
        $('wf-activate').disabled = current.status === 'active';

        if (current.status === 'active' && !$('wf-pause')) {
            const button = document.createElement('button');
            button.id = 'wf-pause';
            button.type = 'button';
            button.className = 'rounded-lg border border-orange-300/15 bg-orange-300/[.06] px-3 py-2 text-xs text-orange-100/80 hover:bg-orange-300/10';
            button.textContent = 'Pause';
            button.addEventListener('click', pauseWorkflow);
            $('wf-activate').before(button);
        } else if (current.status !== 'active' && $('wf-pause')) {
            $('wf-pause').remove();
        }
        renderWebhookCard();
    }

    function renderWebhookCard() {
        const trigger = graph().nodes.find((node) => catalog[node.type]?.category === 'trigger');
        const enabled = Boolean(current?.status === 'active' && trigger?.type === 'webhook_trigger');
        $('wf-webhook-card').classList.toggle('hidden', !enabled);
        $('wf-webhook-url').textContent = webhookUrl || 'The URL is only shown when created. Rotate it to issue a fresh URL.';
    }

    function renderAll() {
        renderWorkflowList();
        renderNodes();
        renderStatus();
        renderInspector();
    }

    function defaultsFor(type) {
        const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
        switch (type) {
            case 'email_trigger': return { from: '', subject_contains: '' };
            case 'schedule_trigger': return { frequency: 'daily', time: '09:00', day: 'monday', timezone: timeZone };
            case 'webhook_trigger': return {};
            case 'jarvis_ai': return { instruction: '', input: '', fields: ['summary'] };
            case 'send_email': return { to: '', subject: '', body: '' };
            case 'webhook_action': return { url: '', method: 'POST', payload: '' };
            default: return {};
        }
    }

    function nextPosition() {
        const nodes = graph().nodes;
        if (!nodes.length) return { x: 240, y: 220 };
        const last = nodes[nodes.length - 1];
        return { x: Math.min(Number(last.position?.x || 240) + 330, 1500), y: Number(last.position?.y || 220) };
    }

    function addNode(type, position = null) {
        if (!current) {
            newWorkflow();
        }
        if (current.status === 'active') {
            showMessage('Pause this workflow before editing its steps.', true);
            return;
        }
        const definition = catalog[type];
        if (!definition) return;
        if (definition.category === 'trigger' && graph().nodes.some((node) => catalog[node.type]?.category === 'trigger')) {
            showMessage('A workflow can have one trigger. Remove the current trigger before adding another.', true);
            return;
        }

        const node = {
            id: newNodeId(),
            type,
            label: '',
            position: position || nextPosition(),
            config: defaultsFor(type),
        };
        const previous = graph().nodes[graph().nodes.length - 1];
        graph().nodes.push(node);
        if (definition.category !== 'trigger' && previous && !graph().edges.some((edge) => edge.source === previous.id && edge.target === node.id)) {
            graph().edges.push({ source: previous.id, target: node.id });
        } else if (definition.category === 'trigger') {
            const roots = graph().nodes.filter((item) => item.id !== node.id && !graph().edges.some((edge) => edge.target === item.id));
            if (roots.length === 1) graph().edges.push({ source: node.id, target: roots[0].id });
        }
        selectedNodeId = node.id;
        renderAll();
    }

    function newNodeId() {
        return `step_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
    }

    function newWorkflow() {
        current = { id: null, name: 'Untitled workflow', description: '', status: 'draft', workflow_data: { nodes: [], edges: [] } };
        selectedNodeId = null;
        webhookUrl = '';
        $('wf-runs').textContent = 'Save this workflow to see its run history.';
        renderAll();
    }

    async function loadWorkflow(id) {
        try {
            const data = await request(`${api.collection}/${id}`);
            current = data.workflow;
            webhookUrl = '';
            selectedNodeId = null;
            renderAll();
            await loadExecutions();
            showMessage(`Loaded “${current.name}”.`);
        } catch (error) { showMessage(error.message, true); }
    }

    async function loadWorkflowList() {
        const data = await request(api.list);
        workflows = data.workflows || [];
        renderWorkflowList();
        if (!current && workflows.length) await loadWorkflow(workflows[0].id);
    }

    async function saveWorkflow(silent = false) {
        if (!current) return false;
        const payload = {
            name: current.name || 'Untitled workflow',
            description: current.description || null,
            workflow_data: workflowData(),
        };
        try {
            const data = await request(current.id ? `${api.collection}/${current.id}` : api.collection, {
                method: current.id ? 'PUT' : 'POST',
                body: JSON.stringify(payload),
            });
            current = data.workflow;
            if (!silent) showMessage('Workflow saved.');
            await loadWorkflowList();
            return true;
        } catch (error) {
            showMessage(error.message, true);
            return false;
        }
    }

    function field(label, key, options = {}) {
        const wrap = document.createElement('label');
        wrap.className = 'block space-y-1.5';
        const title = document.createElement('span');
        title.className = 'block text-[10px] uppercase tracking-[.13em] text-white/35';
        title.textContent = label;
        let input;
        if (options.select) {
            input = document.createElement('select');
            options.select.forEach(([value, text]) => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = text;
                input.append(option);
            });
        } else if (options.multiline) {
            input = document.createElement('textarea');
        } else {
            input = document.createElement('input');
            input.type = options.type || 'text';
            if (options.placeholder) input.placeholder = options.placeholder;
        }
        input.value = escapeText(nodeById(selectedNodeId)?.config?.[key] ?? options.default ?? '');
        if (options.min !== undefined) input.min = String(options.min);
        if (options.max !== undefined) input.max = String(options.max);
        if (!key.startsWith('__') && key !== 'fields_text') {
            input.addEventListener('input', () => updateNodeConfig(key, input.value));
            input.addEventListener('change', () => updateNodeConfig(key, input.value));
        }
        wrap.append(title, input);
        if (options.help) {
            const help = document.createElement('span');
            help.className = 'block text-[9px] leading-4 text-white/25';
            help.textContent = options.help;
            wrap.append(help);
        }
        if (options.map) addMappingSelector(wrap, input);
        return wrap;
    }

    function addMappingSelector(wrap, input) {
        const available = [];
        const ancestors = new Set();
        const pending = [selectedNodeId];
        while (pending.length) {
            const target = pending.pop();
            graph().edges.filter((edge) => edge.target === target).forEach((edge) => {
                if (!ancestors.has(edge.source)) {
                    ancestors.add(edge.source);
                    pending.push(edge.source);
                }
            });
        }
        const outputNodes = graph().nodes.filter((node) => ancestors.has(node.id) && (catalog[node.type]?.category === 'trigger' || node.type === 'jarvis_ai'));
        outputNodes.forEach((node) => {
            const outputs = catalog[node.type]?.outputs?.length
                ? catalog[node.type].outputs
                : (node.config?.fields || []);
            outputs.forEach((output) => available.push({ token: `{{${node.id}.${output}}}`, label: `${node.label || catalog[node.type]?.label || node.type} · ${output}` }));
        });
        if (!available.length) return;
        const select = document.createElement('select');
        select.className = 'mt-1 w-full rounded-lg border border-white/10 bg-[#101722] px-2 py-2 text-[10px] text-white/50';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Insert value from a previous step…';
        select.append(placeholder);
        available.forEach(({ token, label }) => {
            const option = document.createElement('option');
            option.value = token;
            option.textContent = label;
            select.append(option);
        });
        select.addEventListener('change', () => {
            if (!select.value) return;
            const start = input.selectionStart ?? input.value.length;
            const end = input.selectionEnd ?? start;
            const before = input.value.slice(0, start);
            const after = input.value.slice(end);
            input.value = `${before}${select.value}${after}`;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            select.value = '';
        });
        wrap.append(select);
    }

    function updateNodeConfig(key, value) {
        const node = nodeById(selectedNodeId);
        if (!node) return;
        node.config = node.config || {};
        node.config[key] = value;
        renderNodes();
        renderStatus();
        if (key === 'frequency') renderInspector();
    }

    function renderInspector() {
        const inspector = $('wf-inspector');
        inspector.replaceChildren();
        const node = nodeById(selectedNodeId);
        $('wf-inspector-title').textContent = node ? (node.label || catalog[node.type]?.label || node.type) : 'Workflow settings';

        if (!current) {
            inspector.textContent = 'Create or choose a workflow to edit its steps.';
            return;
        }

        if (!node) {
            inspector.append(
                field('Workflow name', '__name', { placeholder: 'Customer email summary' }),
                field('Description', '__description', { multiline: true, placeholder: 'What this automation does' }),
            );
            const nameInput = inspector.querySelector('input');
            const descInput = inspector.querySelector('textarea');
            nameInput.value = current.name || '';
            descInput.value = current.description || '';
            nameInput.addEventListener('input', () => { current.name = nameInput.value; $('wf-current-name').textContent = current.description || current.name; renderWorkflowList(); });
            descInput.addEventListener('input', () => { current.description = descInput.value; $('wf-current-name').textContent = current.description || current.name; });
            if (graph().edges.length) {
                const connections = document.createElement('div');
                connections.className = 'space-y-2 border-t border-white/[.07] pt-4';
                const heading = document.createElement('div');
                heading.className = 'text-[10px] uppercase tracking-[.13em] text-white/35';
                heading.textContent = 'Connections';
                connections.append(heading);
                graph().edges.forEach((edge, index) => {
                    const row = document.createElement('div');
                    row.className = 'flex items-center justify-between gap-2 rounded-lg border border-white/[.06] bg-white/[.02] px-2.5 py-2';
                    const label = document.createElement('span');
                    label.className = 'min-w-0 truncate text-[9px] text-white/45';
                    const source = nodeById(edge.source);
                    const target = nodeById(edge.target);
                    label.textContent = `${source?.label || catalog[source?.type]?.label || 'Step'} → ${target?.label || catalog[target?.type]?.label || 'Step'}`;
                    const remove = actionButton('×', 'border border-white/10 px-2 text-white/45 hover:text-white');
                    remove.title = 'Remove connection';
                    remove.disabled = current.status === 'active';
                    remove.addEventListener('click', () => {
                        graph().edges.splice(index, 1);
                        renderAll();
                    });
                    row.append(label, remove);
                    connections.append(row);
                });
                inspector.append(connections);
            }
            if (current.id) {
                const meta = document.createElement('div');
                meta.className = 'rounded-lg border border-white/[.07] bg-white/[.02] p-3 text-[10px] leading-5 text-white/35';
                meta.textContent = current.status === 'active' ? `Active${current.next_run_at ? ` · next run ${new Date(current.next_run_at).toLocaleString()}` : ''}${current.last_run_at ? ` · last run ${new Date(current.last_run_at).toLocaleString()}` : ''}` : 'Save and activate this workflow when the steps are ready.';
                inspector.append(meta);
                const removeWorkflow = actionButton('Delete workflow', 'w-full border border-red-300/15 text-red-200/70 hover:text-red-100');
                removeWorkflow.addEventListener('click', deleteWorkflow);
                inspector.append(removeWorkflow);
            }
            if (current.status === 'active') {
                inspector.querySelectorAll('input, textarea, select').forEach((control) => { control.disabled = true; });
                inspector.prepend(helpText('Pause this workflow to edit it.'));
            }
            return;
        }

        inspector.append(field('Step name (optional)', '__label', { placeholder: catalog[node.type]?.label || node.type }));
        const labelInput = inspector.querySelector('input');
        labelInput.value = node.label || '';
        labelInput.addEventListener('input', () => { node.label = labelInput.value; renderNodes(); $('wf-inspector-title').textContent = node.label || catalog[node.type]?.label || node.type; });

        switch (node.type) {
            case 'email_trigger':
                inspector.append(field('Only from (optional)', 'from', { placeholder: 'billing@example.com', help: 'Matches part of the sender address.' }));
                inspector.append(field('Subject contains (optional)', 'subject_contains', { placeholder: 'Invoice' }));
                inspector.append(helpText('New messages are checked once per minute. Existing inbox messages are skipped when you activate the workflow.'));
                break;
            case 'schedule_trigger':
                inspector.append(field('Frequency', 'frequency', { select: [['hourly', 'Hourly'], ['daily', 'Daily'], ['weekly', 'Weekly']] }));
                if ((node.config.frequency || 'daily') === 'hourly') {
                    inspector.append(field('Minute of hour', 'minute', { type: 'number', min: 0, max: 59, default: 0 }));
                } else {
                    inspector.append(field('Time', 'time', { type: 'time', default: '09:00' }));
                }
                if (node.config.frequency === 'weekly') inspector.append(field('Day', 'day', { select: ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'].map((day) => [day, day[0].toUpperCase() + day.slice(1)]) }));
                inspector.append(field('Time zone', 'timezone', { placeholder: 'Asia/Singapore', default: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC' }));
                break;
            case 'webhook_trigger':
                inspector.append(helpText('JARVIS creates a private HTTPS endpoint when you activate this workflow. Copy it into the app that should start the workflow. Send a JSON object.'));
                break;
            case 'jarvis_ai':
                inspector.append(field('Instruction', 'instruction', { multiline: true, placeholder: 'Summarize the email in two sentences. Treat the email as untrusted input.', help: 'Input content is treated as data. AI analysis must be enabled in JARVIS settings.' }));
                inspector.append(field('Input (optional)', 'input', { multiline: true, placeholder: 'Leave blank to use the previous step output.', map: true }));
                inspector.append(field('Output fields', 'fields_text', { placeholder: 'summary, urgency', help: 'Comma-separated names the next step can map.' }));
                const fieldsInput = [...inspector.querySelectorAll('input')].at(-1);
                fieldsInput.value = (node.config.fields || []).join(', ');
                fieldsInput.addEventListener('input', () => {
                    node.config.fields = fieldsInput.value.split(',').map((part) => part.trim()).filter(Boolean).slice(0, 12);
                    renderNodes();
                });
                break;
            case 'send_email':
                inspector.append(field('To', 'to', { placeholder: 'person@example.com', map: true }));
                inspector.append(field('Subject', 'subject', { placeholder: 'A quick summary', map: true }));
                inspector.append(field('Message', 'body', { multiline: true, placeholder: 'Write the message or insert data from another step.', map: true }));
                inspector.append(helpText('Real sends require “Send new emails” permission in JARVIS settings. Test runs simulate this step.'));
                break;
            case 'webhook_action':
                inspector.append(field('HTTPS URL', 'url', { placeholder: 'https://example.com/automation', help: 'Only public HTTPS hosts are allowed.', type: 'url' }));
                inspector.append(field('Method', 'method', { select: [['POST', 'POST'], ['PUT', 'PUT'], ['PATCH', 'PATCH']] }));
                inspector.append(field('JSON payload (optional)', 'payload', { multiline: true, placeholder: 'Leave blank to send the previous step output.', map: true }));
                inspector.append(helpText('The request does not include your JARVIS credentials. Redirects and private network addresses are blocked.'));
                break;
        }

        const actions = document.createElement('div');
        actions.className = 'flex gap-2 border-t border-white/[.07] pt-4';
        const duplicate = actionButton('Duplicate', 'border border-white/10 text-white/55 hover:text-white');
        duplicate.addEventListener('click', () => duplicateNode(node));
        const remove = actionButton('Delete step', 'border border-red-300/15 text-red-200/70 hover:text-red-100');
        remove.addEventListener('click', () => deleteNode(node.id));
        actions.append(duplicate, remove);
        inspector.append(actions);

        if (current.status === 'active') {
            inspector.querySelectorAll('input, textarea, select, button').forEach((control) => { control.disabled = true; });
            inspector.prepend(helpText('Pause this workflow to change step settings.'));
        }
    }

    function helpText(text) {
        const element = document.createElement('p');
        element.className = 'rounded-lg border border-white/[.06] bg-white/[.02] p-3 text-[10px] leading-5 text-white/35';
        element.textContent = text;
        return element;
    }

    function actionButton(label, classes) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `rounded-lg px-3 py-2 text-[10px] transition ${classes}`;
        button.textContent = label;
        return button;
    }

    function duplicateNode(node) {
        const copy = clone(node);
        copy.id = newNodeId();
        copy.label = node.label ? `${node.label} copy` : '';
        copy.position = { x: Math.min(Number(node.position.x) + 70, 1500), y: Number(node.position.y) + 130 };
        graph().nodes.push(copy);
        if (catalog[copy.type]?.category !== 'trigger') graph().edges.push({ source: node.id, target: copy.id });
        selectedNodeId = copy.id;
        renderAll();
    }

    function deleteNode(id) {
        graph().nodes = graph().nodes.filter((node) => node.id !== id);
        graph().edges = graph().edges.filter((edge) => edge.source !== id && edge.target !== id);
        selectedNodeId = null;
        pendingSource = null;
        renderAll();
    }

    async function deleteWorkflow() {
        if (!current?.id) return;
        const warning = current.status === 'active'
            ? 'This active workflow will stop running and its history will be deleted. Continue?'
            : 'Delete this workflow and its run history?';
        if (!window.confirm(warning)) return;
        try {
            await request(`${api.collection}/${current.id}`, { method: 'DELETE' });
            current = null;
            webhookUrl = '';
            $('wf-runs').textContent = 'Select a workflow to see its run history.';
            await loadWorkflowList();
            if (!current) newWorkflow();
            showMessage('Workflow deleted.');
        } catch (error) { showMessage(error.message, true); }
    }

    async function loadExecutions() {
        if (!current?.id) return;
        try {
            const data = await request(`${api.collection}/${current.id}/executions`);
            renderExecutions(data.executions || []);
        } catch (error) { $('wf-runs').textContent = error.message; }
    }

    function renderExecutions(executions) {
        const container = $('wf-runs');
        container.replaceChildren();
        if (!executions.length) {
            container.textContent = 'No runs yet. Use Test run to preview the steps.';
            return;
        }
        executions.forEach((run) => {
            const details = document.createElement('details');
            details.className = 'rounded-lg border border-white/[.07] bg-white/[.02]';
            const summary = document.createElement('summary');
            summary.className = 'flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5';
            const left = document.createElement('span');
            left.className = 'text-[11px] text-white/70';
            left.textContent = `${run.trigger_type} · ${new Date(run.started_at).toLocaleString()}`;
            const status = document.createElement('span');
            status.className = `text-[9px] uppercase tracking-wider ${run.status === 'success' ? 'text-emerald-300' : run.status === 'failed' ? 'text-red-300' : 'text-white/40'}`;
            status.textContent = run.status;
            summary.append(left, status);
            const content = document.createElement('pre');
            content.className = 'max-h-44 overflow-auto border-t border-white/[.06] p-3 text-[9px] leading-4 text-white/40';
            content.textContent = JSON.stringify({ error: run.error_message, nodes: run.output_data?.nodes || [] }, null, 2);
            details.append(summary, content);
            container.append(details);
        });
    }

    async function testWorkflow() {
        if (current.status !== 'active' && !await saveWorkflow(true)) return;
        const testBody = window.prompt('Optional sample email or webhook body for this dry run:', 'This is sample content for a test run.');
        if (testBody === null) return;
        $('wf-test').disabled = true;
        showMessage('Running test… JARVIS AI steps may use your configured AI provider. Email and webhook actions are simulated.');
        try {
            const data = await request(`${api.collection}/${current.id}/test`, { method: 'POST', body: JSON.stringify({ test_body: testBody }) });
            const history = await request(`${api.collection}/${current.id}/executions`);
            renderExecutions([data.execution, ...(history.executions || []).filter((run) => run.id !== data.execution.id)]);
            showMessage(data.message || 'Test finished.');
        } catch (error) {
            showMessage(error.message, true);
            await loadExecutions();
        } finally {
            $('wf-test').disabled = false;
        }
    }

    async function activateWorkflow() {
        if (current?.status === 'active') return;
        if (!current?.id && !await saveWorkflow(true)) return;
        if (!window.confirm('Activate this workflow? Its email or schedule triggers can run actions automatically.')) return;
        $('wf-activate').disabled = true;
        try {
            const data = await request(`${api.collection}/${current.id}/activate`, { method: 'POST', body: '{}' });
            current = data.workflow;
            webhookUrl = data.webhook_url || '';
            renderAll();
            await loadWorkflowList();
            showMessage(webhookUrl ? 'Workflow activated. Copy its private webhook URL on the right.' : 'Workflow activated.');
        } catch (error) { showMessage(error.message, true); }
        finally { $('wf-activate').disabled = current?.status === 'active'; }
    }

    async function pauseWorkflow() {
        if (!current?.id) return;
        try {
            const data = await request(`${api.collection}/${current.id}/pause`, { method: 'POST', body: '{}' });
            current = data.workflow;
            webhookUrl = '';
            renderAll();
            await loadWorkflowList();
            showMessage('Workflow paused.');
        } catch (error) { showMessage(error.message, true); }
    }

    async function rotateWebhook() {
        if (!current?.id) return;
        try {
            const data = await request(`${api.collection}/${current.id}/rotate-webhook`, { method: 'POST', body: '{}' });
            webhookUrl = data.webhook_url;
            renderWebhookCard();
            showMessage('A fresh webhook URL is ready. The old URL no longer works.');
        } catch (error) { showMessage(error.message, true); }
    }

    async function proposeWorkflow() {
        const prompt = $('wf-ai-prompt').value.trim();
        if (prompt.length < 5) { showMessage('Describe the automation you want JARVIS to create.', true); return; }
        $('wf-ai-propose').disabled = true;
        showMessage('JARVIS is drafting a workflow…');
        try {
            const data = await request(api.propose, { method: 'POST', body: JSON.stringify({ prompt }) });
            current = {
                id: null,
                name: data.proposal.name || 'JARVIS proposal',
                description: data.proposal.description || '',
                status: 'draft',
                workflow_data: data.proposal.workflow_data,
            };
            selectedNodeId = null;
            webhookUrl = '';
            renderAll();
            showMessage('Draft created. Review the steps and settings, then save or test it. JARVIS has not activated it.');
        } catch (error) { showMessage(error.message, true); }
        finally { $('wf-ai-propose').disabled = false; }
    }

    function worldPoint(event) {
        const rect = $('wf-viewport').getBoundingClientRect();
        return { x: (event.clientX - rect.left - transform.x) / transform.scale, y: (event.clientY - rect.top - transform.y) / transform.scale };
    }

    $('wf-viewport').addEventListener('pointerdown', (event) => {
        if (event.button !== 0 || event.target.closest('.wf-node')) return;
        gesture = { kind: 'pan', x: event.clientX, y: event.clientY, startX: transform.x, startY: transform.y };
        $('wf-viewport').setPointerCapture(event.pointerId);
    });
    $('wf-viewport').addEventListener('pointermove', (event) => {
        if (!gesture) return;
        if (gesture.kind === 'pan') {
            transform.x = gesture.startX + event.clientX - gesture.x;
            transform.y = gesture.startY + event.clientY - gesture.y;
            setTransform();
        } else {
            const node = nodeById(gesture.nodeId);
            if (!node) return;
            node.position.x = Math.round(gesture.startX + (event.clientX - gesture.x) / transform.scale);
            node.position.y = Math.round(gesture.startY + (event.clientY - gesture.y) / transform.scale);
            const card = $('wf-nodes').querySelector(`[data-node-id="${CSS.escape(gesture.nodeId)}"]`);
            if (card) { card.style.left = `${node.position.x}px`; card.style.top = `${node.position.y}px`; }
            renderEdges();
        }
    });
    const finishGesture = () => { if (gesture?.kind === 'node') renderEdges(); gesture = null; };
    $('wf-viewport').addEventListener('pointerup', finishGesture);
    $('wf-viewport').addEventListener('pointercancel', finishGesture);
    $('wf-viewport').addEventListener('wheel', (event) => {
        event.preventDefault();
        const oldScale = transform.scale;
        const nextScale = Math.min(1.6, Math.max(0.45, oldScale * (event.deltaY < 0 ? 1.08 : 0.92)));
        const rect = $('wf-viewport').getBoundingClientRect();
        const x = event.clientX - rect.left;
        const y = event.clientY - rect.top;
        transform.x = x - ((x - transform.x) / oldScale) * nextScale;
        transform.y = y - ((y - transform.y) / oldScale) * nextScale;
        transform.scale = nextScale;
        setTransform();
    }, { passive: false });
    $('wf-viewport').addEventListener('dragover', (event) => { event.preventDefault(); event.dataTransfer.dropEffect = 'copy'; });
    $('wf-viewport').addEventListener('drop', (event) => {
        event.preventDefault();
        const type = event.dataTransfer.getData('application/x-jc-workflow-node');
        if (type) addNode(type, worldPoint(event));
    });
    $('wf-zoom-in').addEventListener('click', () => { transform.scale = Math.min(1.6, transform.scale * 1.12); setTransform(); });
    $('wf-zoom-out').addEventListener('click', () => { transform.scale = Math.max(0.45, transform.scale / 1.12); setTransform(); });
    $('wf-fit').addEventListener('click', () => {
        const nodes = graph().nodes;
        const viewport = $('wf-viewport').getBoundingClientRect();
        if (!nodes.length || !viewport.width || !viewport.height) {
            transform = { x: 80, y: 70, scale: 0.85 };
        } else {
            const minX = Math.min(...nodes.map((node) => Number(node.position?.x || 0)));
            const minY = Math.min(...nodes.map((node) => Number(node.position?.y || 0)));
            const maxX = Math.max(...nodes.map((node) => Number(node.position?.x || 0) + 236));
            const maxY = Math.max(...nodes.map((node) => Number(node.position?.y || 0) + 112));
            const scale = Math.min(1.35, Math.max(0.45, Math.min((viewport.width - 100) / (maxX - minX + 80), (viewport.height - 100) / (maxY - minY + 80))));
            transform = {
                scale,
                x: (viewport.width - (maxX - minX) * scale) / 2 - minX * scale,
                y: (viewport.height - (maxY - minY) * scale) / 2 - minY * scale,
            };
        }
        setTransform();
    });
    $('wf-new').addEventListener('click', newWorkflow);
    $('wf-workflow-settings').addEventListener('click', () => { selectedNodeId = null; renderAll(); });
    $('wf-save').addEventListener('click', () => saveWorkflow());
    $('wf-test').addEventListener('click', testWorkflow);
    $('wf-activate').addEventListener('click', activateWorkflow);
    $('wf-ai-propose').addEventListener('click', proposeWorkflow);
    $('wf-ai-prompt').addEventListener('keydown', (event) => { if (event.key === 'Enter') proposeWorkflow(); });
    $('wf-refresh-runs').addEventListener('click', loadExecutions);
    $('wf-copy-webhook').addEventListener('click', async () => {
        if (!webhookUrl) { showMessage('Rotate the webhook URL to create a new copyable endpoint.', true); return; }
        await navigator.clipboard.writeText(webhookUrl);
        showMessage('Webhook URL copied. Keep it private.');
    });
    $('wf-rotate-webhook').addEventListener('click', () => {
        if (window.confirm('Rotate the webhook URL? The old URL will stop working immediately.')) rotateWebhook();
    });

    async function start() {
        try {
            const data = await request(api.catalog);
            catalog = data.nodes || {};
            renderPalette();
            renderAll();
            await loadWorkflowList();
            if (!current) newWorkflow();
        } catch (error) {
            showMessage(error.message, true);
        }
    }

    start();
})();
