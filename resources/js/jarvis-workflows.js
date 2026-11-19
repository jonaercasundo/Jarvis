import { API_PRESETS, apiPresetValues, apiTestSummary } from './workflow-api-presets';

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
        connections: '/jarvis/workflows/connections', // GET -> { connections: [{ id, name }] } (secrets never leave the server)
        agentStatus: '/jarvis/agents/status',
    };

    /* ------------------------------------------------------------------
     * Orchestrator -> AI agents -> Apps
     *
     * Graph shape:  Trigger -> Orchestrator -> AI agent (1..n) -> App (0..n per agent)
     * The three new node types are declared here so the UI works immediately.
     * The server catalog can override them (server entries win).
     * ------------------------------------------------------------------ */

    const LOCAL_CATALOG = {
        orchestrator: {
            label: 'Orchestrator',
            category: 'orchestrator',
            description: 'Reads each request and hands it to the right AI agent.',
        },
        ai_agent: {
            label: 'AI agent',
            category: 'agent',
            description: 'A specialist with its own instructions and apps.',
        },
        app_action: {
            label: 'App',
            category: 'app',
            description: 'Lets an agent use Gmail, Outlook, Google Sheets and more.',
        },
        manual_trigger: { label: 'Manual test trigger', category: 'trigger', description: 'Starts with safe sample data when you test.' },
        condition: { label: 'Condition', category: 'condition', description: 'Route data through a true or false branch.' },
        transform: { label: 'Transform', category: 'transform', description: 'Map input fields into a new data shape.' },
        output: { label: 'Output', category: 'output', description: 'Record the workflow result.' },
    };
    const NEW_TYPES = Object.keys(LOCAL_CATALOG);

    const SPECS = {
        secretary: {
            label: 'Secretary',
            routes: 'Email, calendar, meetings, documents, tasks, reminders, follow-ups, scheduling, administrative requests',
            prompt: 'You are the executive secretary. Manage administrative requests, organize emails, schedules, meetings, documents, tasks, reminders, and follow-ups. Keep information organized, communicate clearly and professionally, and summarize important actions. Never make commitments, approve expenses, or send sensitive information without authorization.',
        },

        operations: {
            label: 'Operations',
            routes: 'Orders, deliveries, inventory, vendors, schedules, internal tasks, logistics, operational reports',
            prompt: 'Manage and analyze company operations. Query connected company systems when needed. Monitor projects and deliveries, analyze operational and financial performance, prepare reports, identify problems, and automate operational tasks when requested. Keep records accurate and ask for clarification when the task is unclear.',
        },

        it: {
            label: 'IT',
            routes: 'Access requests, password resets, device issues, software tickets, network, servers, applications, troubleshooting',
            prompt: 'You handle IT support and technical operations. Triage incidents by urgency, troubleshoot systems, document resolutions, and track access requests. Never expose credentials, passwords, API keys, or other secrets. Escalate destructive or high-risk changes for approval.',
        },

        sales: {
            label: 'Sales',
            routes: 'Leads, quotes, pricing, demos, follow-ups, pipeline questions, customer inquiries',
            prompt: 'You handle sales. Qualify new leads, answer pricing questions, draft quotes and follow-ups, update the sales pipeline, and identify opportunities. Write in a friendly, concise tone and never promise discounts or commercial commitments without approval.',
        },

        customer_support: {
            label: 'Customer Support',
            routes: 'Complaints, refunds, product problems, how-to questions, order status, customer inquiries',
            prompt: 'You handle customer support. Understand the customer issue, provide a clear next step, keep replies concise and helpful, and document the interaction. Escalate angry, urgent, sensitive, or exceptional cases to a human.',
        },

        hr: {
            label: 'HR',
            routes: 'Job applicants, interviews, onboarding, leave requests, employee questions, attendance, HR documents',
            prompt: 'You handle HR requests. Support recruitment, interview scheduling, onboarding, attendance, leave requests, employee questions, and HR documents. Treat employee and applicant information as confidential and require human approval for sensitive employment decisions.',
        },

        finance: {
            label: 'Finance',
            routes: 'Invoices, payments, expenses, reimbursements, budgets, billing, financial reports',
            prompt: 'You handle finance-related tasks. Review invoices, payments, expenses, reimbursements, budgets, billing questions, and financial records. Identify discrepancies and prepare summaries. Never approve, release, or execute a payment without authorized human approval.',
        },

        procurement: {
            label: 'Procurement',
            routes: 'Purchase requests, suppliers, quotations, purchase orders, sourcing, procurement documents',
            prompt: 'You handle procurement. Process purchase requests, compare supplier quotations, organize sourcing information, prepare purchase documentation, and track procurement status. Never commit the company to a purchase or supplier without required approval.',
        },

        accounting: {
            label: 'Accounting',
            routes: 'Accounts payable, accounts receivable, invoices, receipts, reconciliations, journals, accounting reports',
            prompt: 'You handle accounting support. Organize invoices, receipts, accounts payable, accounts receivable, reconciliations, and accounting reports. Identify discrepancies and prepare information for review. Never finalize accounting entries or financial decisions without authorized review.',
        },

        legal: {
            label: 'Legal',
            routes: 'Contracts, agreements, compliance documents, policies, legal requests, document review',
            prompt: 'You handle legal-administrative support. Organize contracts, agreements, policies, compliance documents, and legal requests. Summarize documents and identify items requiring legal review. Do not provide definitive legal advice or make legal commitments; escalate matters to qualified legal personnel.',
        },

        project_manager: {
            label: 'Project Manager',
            routes: 'Projects, milestones, tasks, deadlines, dependencies, status reports, project risks',
            prompt: 'You handle project management. Track milestones, tasks, deadlines, dependencies, risks, blockers, and project status. Prepare concise status reports and identify overdue or at-risk work. Do not change project commitments without authorization.',
        },

        marketing: {
            label: 'Marketing',
            routes: 'Campaigns, social posts, newsletters, content calendar, promotions, marketing reports',
            prompt: 'You handle marketing. Draft campaigns, social posts, newsletters, content plans, and marketing reports using the configured brand voice. Organize campaign information and summarize results. Do not publish external content without approval when approval is required.',
        },

        data_analyst: {
            label: 'Data Analyst',
            routes: 'Reports, dashboards, KPIs, spreadsheets, data analysis, trends, summaries',
            prompt: 'You handle data analysis. Analyze business data, identify trends, calculate metrics, summarize findings, and prepare reports or dashboard-ready outputs. Clearly distinguish observed data from assumptions and flag missing or inconsistent data.',
        },

        inventory: {
            label: 'Inventory',
            routes: 'Stock levels, stock in, stock out, warehouse, item movements, inventory reports, reconciliation',
            prompt: 'You handle inventory operations. Track stock levels, warehouse movements, stock-in and stock-out transactions, item records, and inventory reports. Identify discrepancies and maintain accurate records. Require approval for sensitive inventory adjustments.',
        },

        logistics: {
            label: 'Logistics',
            routes: 'Deliveries, shipments, tracking, warehouse transfers, receiving, dispatch, logistics schedules',
            prompt: 'You handle logistics. Track deliveries, shipments, receiving, dispatch, warehouse transfers, schedules, and logistics issues. Identify delays and exceptions and keep shipment information organized.',
        },

        compliance: {
            label: 'Compliance',
            routes: 'Policies, audits, controls, compliance checks, documentation, regulatory requirements',
            prompt: 'You handle compliance support. Organize policies, audit evidence, control checks, compliance documentation, and follow-up actions. Flag potential compliance issues and route them to the appropriate responsible person for review.',
        },

        document_manager: {
            label: 'Document Manager',
            routes: 'Documents, files, reports, templates, document organization, document requests',
            prompt: 'You handle document management. Organize, classify, summarize, retrieve, and prepare documents and reports. Maintain clear naming and filing conventions and identify missing or outdated documents.',
        },

        executive_assistant: {
            label: 'Executive Assistant',
            routes: 'Executive emails, meetings, schedules, priorities, briefings, follow-ups, executive tasks',
            prompt: 'You support executives with scheduling, meeting preparation, email organization, briefings, priorities, follow-ups, and administrative tasks. Keep information concise and organized. Never make commitments or communicate sensitive decisions without authorization.',
        },

        custom: {
            label: 'Custom',
            routes: '',
            prompt: '',
        },
    };

    const ROUTING = {
        auto: 'Pick the best agent for each request',
        parallel: 'Send every request to all agents',
        approve: 'Ask me which agent to use',
    };

    const MAIL_FIELDS = [
        ['to', 'To', '{{agent.recipient}}'],
        ['subject', 'Subject', '{{agent.subject}}'],
        ['body', 'Message', '{{agent.reply}}', true],
    ];

    const M365 = 'Microsoft 365';
    const GOOGLE = 'Google Workspace';
    const CHAT = 'Team chat';
    const PM = 'Project management';
    const CRM = 'CRM';
    const CUSTOM = 'Custom';
    const ERP = 'ERP';
    const B1_VERSION = ['api_version', 'Service Layer version', '', false, [['v2', 'v2'], ['v1', 'v1']]];
    const KEY_APPS = [
        'gmail',
        'outlook',
        'outlook_calendar',
        'teams',
        'planner',
        'todo',
        'sharepoint',
        'onedrive',
        'excel',
        'custom_api',
        'sap',
    ]; // apps that authenticate with a saved server-side connection
    const HTTP_METHODS = [['GET', 'GET'], ['POST', 'POST'], ['PUT', 'PUT'], ['PATCH', 'PATCH'], ['DELETE', 'DELETE']];

    const APPS = {
        /* ---- Microsoft 365 ---- */
        outlook: { group: M365, label: 'Outlook', color: '#60a5fa', actions: {
            read: { label: 'Read new emails', fields: [['query', 'Folder or filter', 'Inbox, unread']] },
            draft: { label: 'Create draft reply', fields: MAIL_FIELDS },
            save_draft: { label: 'Save draft in Outlook', fields: MAIL_FIELDS },
            send: { label: 'Send email', fields: MAIL_FIELDS },
        } },
        outlook_calendar: { group: M365, label: 'Outlook Calendar', color: '#38bdf8', actions: {
            list: { label: 'List upcoming events', fields: [['days', 'Days ahead', '7']] },
            create: { label: 'Create event', fields: [['title', 'Title', '{{agent.subject}}'], ['when', 'Date and time', '{{agent.datetime}}'], ['attendees', 'Attendees', '{{agent.recipient}}']] },
        } },
        teams: { group: M365, label: 'Microsoft Teams', color: '#818cf8', actions: {
            message: { label: 'Post a channel message', fields: [['team', 'Team', ''], ['channel', 'Channel', 'General'], ['message', 'Message', '{{agent.summary}}', true]] },
            chat: { label: 'Send a chat message', fields: [['to', 'To (email)', '{{agent.recipient}}'], ['message', 'Message', '{{agent.summary}}', true]] },
            meeting: { label: 'Schedule a meeting', fields: [['title', 'Title', '{{agent.subject}}'], ['when', 'Date and time', '{{agent.datetime}}'], ['attendees', 'Attendees', '{{agent.recipient}}']] },
        } },
        planner: { group: M365, label: 'Microsoft Planner', color: '#34d399', actions: {
            create: { label: 'Create task', fields: [['plan', 'Plan', ''], ['bucket', 'Bucket', 'To do'], ['title', 'Task title', '{{agent.subject}}'], ['assignee', 'Assign to (email)', ''], ['due', 'Due date', ''], ['notes', 'Notes', '{{agent.summary}}', true]] },
            update: { label: 'Update task', fields: [['plan', 'Plan', ''], ['task', 'Task title or ID', '{{agent.id}}'], ['progress', 'Progress (not started, in progress, completed)', 'in progress'], ['notes', 'Notes', '', true]] },
            list: { label: 'List open tasks', fields: [['plan', 'Plan', ''], ['assignee', 'Only assigned to (email, optional)', '']] },
        } },
        todo: { group: M365, label: 'Microsoft To Do', color: '#60a5fa', actions: {
            create: { label: 'Create task', fields: [['list', 'List', 'Tasks'], ['title', 'Task title', '{{agent.subject}}'], ['due', 'Due date', ''], ['notes', 'Notes', '{{agent.summary}}', true]] },
        } },
        sharepoint: { group: M365, label: 'SharePoint', color: '#2dd4bf', actions: {
            upload: { label: 'Upload a file', fields: [['site', 'Site', ''], ['library', 'Document library', 'Documents'], ['file_name', 'File name', '{{agent.subject}}.txt'], ['content', 'Content', '{{agent.summary}}', true]] },
            find: { label: 'Find files', fields: [['site', 'Site', ''], ['query', 'Search for', '{{agent.subject}}']] },
            item: { label: 'Add a list item', fields: [['site', 'Site', ''], ['list', 'List', ''], ['row', 'Column values (name: value, one per line)', '', true]] },
        } },
        onedrive: { group: M365, label: 'OneDrive', color: '#3b82f6', actions: {
            upload: { label: 'Upload a file', fields: [['folder', 'Folder', '/Documents'], ['file_name', 'File name', '{{agent.subject}}.txt'], ['content', 'Content', '{{agent.summary}}', true]] },
            find: { label: 'Find files', fields: [['query', 'Search for', '{{agent.subject}}']] },
        } },
        excel: { group: M365, label: 'Excel (Microsoft 365)', color: '#22c55e', actions: {
            read: { label: 'Read table rows', fields: [['workbook', 'Workbook (OneDrive or SharePoint path)', ''], ['table', 'Table name', 'Table1']] },
            append: { label: 'Add a row', fields: [['workbook', 'Workbook (OneDrive or SharePoint path)', ''], ['table', 'Table name', 'Table1'], ['row', 'Row values (comma separated)', '{{agent.summary}}', true]] },
            update: { label: 'Update a row', fields: [['workbook', 'Workbook (OneDrive or SharePoint path)', ''], ['table', 'Table name', 'Table1'], ['match', 'Find row where first column equals', '{{agent.id}}'], ['row', 'New values (comma separated)', '', true]] },
        } },

        /* ---- Google Workspace ---- */
        gmail: { group: GOOGLE, label: 'Gmail', color: '#f87171', actions: {
            read: { label: 'List unread inbox messages', fields: [] },
            draft: { label: 'Create draft reply', fields: MAIL_FIELDS },
            send: { label: 'Send email', fields: MAIL_FIELDS },
        } },
        sheets: { group: GOOGLE, label: 'Google Sheets', color: '#4ade80', actions: {
            read: { label: 'Read rows', fields: [['sheet', 'Spreadsheet name or link', ''], ['range', 'Tab and range', 'Sheet1!A:F']] },
            append: { label: 'Add a row', fields: [['sheet', 'Spreadsheet name or link', ''], ['range', 'Tab', 'Sheet1'], ['row', 'Row values (comma separated)', '{{agent.summary}}', true]] },
            update: { label: 'Update a row', fields: [['sheet', 'Spreadsheet name or link', ''], ['match', 'Find row where column A equals', '{{agent.id}}'], ['row', 'New values (comma separated)', '', true]] },
        } },
        mmc_tracker: { group: 'Operations', label: 'MMC Tracker (test connector)', color: '#34d399', actions: {
            upsert: { label: 'Upsert masterlist record', fields: [['duplicate_key', 'Duplicate key', 'project_code']] },
        } },
        calendar: { group: GOOGLE, label: 'Google Calendar', color: '#93c5fd', actions: {
            list: { label: 'List upcoming events', fields: [['days', 'Days ahead', '7']] },
            create: { label: 'Create event', fields: [['title', 'Title', '{{agent.subject}}'], ['when', 'Date and time', '{{agent.datetime}}'], ['attendees', 'Attendees', '{{agent.recipient}}']] },
        } },

        /* ---- Team chat ---- */
        slack: { group: CHAT, label: 'Slack', color: '#c084fc', actions: {
            send: { label: 'Send message', fields: [['channel', 'Channel', '#general'], ['message', 'Message', '{{agent.summary}}', true]] },
        } },

        /* ---- Project management ---- */
        trello: { group: PM, label: 'Trello', color: '#38bdf8', actions: {
            card: { label: 'Create card', fields: [['board', 'Board', ''], ['list', 'List', 'To do'], ['title', 'Card title', '{{agent.subject}}'], ['description', 'Description', '{{agent.summary}}', true]] },
        } },
        asana: { group: PM, label: 'Asana', color: '#fb7185', actions: {
            create: { label: 'Create task', fields: [['project', 'Project', ''], ['title', 'Task title', '{{agent.subject}}'], ['assignee', 'Assign to (email)', ''], ['due', 'Due date', ''], ['notes', 'Notes', '{{agent.summary}}', true]] },
        } },
        jira: { group: PM, label: 'Jira', color: '#60a5fa', actions: {
            issue: { label: 'Create issue', fields: [['project', 'Project key', ''], ['type', 'Issue type', 'Task'], ['summary', 'Summary', '{{agent.subject}}'], ['description', 'Description', '{{agent.summary}}', true]] },
            comment: { label: 'Comment on an issue', fields: [['key', 'Issue key', '{{agent.id}}'], ['comment', 'Comment', '{{agent.summary}}', true]] },
        } },
        notion: { group: PM, label: 'Notion', color: '#e5e7eb', actions: {
            create: { label: 'Create page', fields: [['database', 'Database', ''], ['title', 'Title', '{{agent.subject}}'], ['content', 'Content', '{{agent.summary}}', true]] },
        } },

        /* ---- Custom ---- */
        custom_api: { group: CUSTOM, label: 'Custom API', color: '#f472b6', actions: {
            request: { label: 'Call an API', fields: [
                ['method', 'Method', '', false, HTTP_METHODS],
                ['url', 'HTTPS URL', '/api/projects or https://api.example.com/v1/orders'],
                ['query', 'Query parameters (one per line, key=value)', 'status=open', true],
                ['headers', 'Extra headers (one per line, Name: value)', 'Accept: application/json', true],
                ['body', 'JSON body (optional)', '{"title": "{{agent.subject}}"}', true],
                ['response_paths', 'Only keep these values from the response (optional)', 'data.projects, data.deliveries, meta', true],
            ] },
        } },

        /* ---- ERP ---- */
        sap: {
            group: ERP, label: 'SAP', color: '#0ea5e9', variantLabel: 'SAP product',
            variants: {
                b1: { label: 'SAP Business One', note: 'Uses the Business One Service Layer. The saved connection holds the server address, company database and login (or API credentials).', actions: {
                    read: { label: 'Read records', fields: [
                        B1_VERSION,
                        ['resource', 'Resource', 'Orders'],
                        ['filter', 'Filter ($filter, optional)', "CardCode eq 'C20000'"],
                        ['select', 'Fields to return ($select, optional)', 'DocEntry, CardName, DocTotal'],
                        ['top', 'Maximum records', '20'],
                    ] },
                    create: { label: 'Create a record', fields: [
                        B1_VERSION,
                        ['resource', 'Resource', 'Orders'],
                        ['body', 'Record as JSON', '{"CardCode": "{{agent.customer_id}}"}', true],
                    ] },
                    update: { label: 'Update a record', fields: [
                        B1_VERSION,
                        ['resource', 'Resource', 'Orders'],
                        ['record_key', 'Record key (DocEntry or code)', '{{agent.doc_entry}}'],
                        ['body', 'Changed fields as JSON', '{"Comments": "{{agent.summary}}"}', true],
                    ] },
                    close: { label: 'Close or cancel a document', fields: [
                        B1_VERSION,
                        ['resource', 'Document type', 'Orders'],
                        ['record_key', 'DocEntry', '{{agent.doc_entry}}'],
                        ['operation', 'Operation', '', false, [['Close', 'Close'], ['Cancel', 'Cancel']]],
                    ] },
                } },
                s4: { label: 'SAP S/4HANA (OData)', note: 'Uses the S/4HANA OData APIs. The saved connection holds the system address and the API key, OAuth or basic credentials.', actions: {
                    read: { label: 'Read records', fields: [
                        ['service', 'Service path', '/sap/opu/odata/sap/API_BUSINESS_PARTNER'],
                        ['entity', 'Entity set', 'A_BusinessPartner'],
                        ['filter', 'Filter ($filter, optional)', "BusinessPartnerCategory eq '2'"],
                        ['select', 'Fields to return ($select, optional)', 'BusinessPartner, BusinessPartnerName'],
                        ['top', 'Maximum records', '20'],
                    ] },
                    create: { label: 'Create a record', fields: [
                        ['service', 'Service path', '/sap/opu/odata/sap/API_SALES_ORDER_SRV'],
                        ['entity', 'Entity set', 'A_SalesOrder'],
                        ['body', 'Record as JSON', '{"SoldToParty": "{{agent.customer_id}}"}', true],
                    ] },
                    update: { label: 'Update a record', fields: [
                        ['service', 'Service path', '/sap/opu/odata/sap/API_SALES_ORDER_SRV'],
                        ['entity', 'Entity set', 'A_SalesOrder'],
                        ['record_key', 'Record key', "'{{agent.id}}'"],
                        ['body', 'Changed fields as JSON', '{"PurchaseOrderByCustomer": "{{agent.reference}}"}', true],
                    ] },
                } },
                sf: { label: 'SAP SuccessFactors', note: 'Uses the SuccessFactors OData API. The saved connection holds the data-center address, company ID and credentials.', actions: {
                    read: { label: 'Read records', fields: [
                        ['entity', 'Entity', 'EmpJob'],
                        ['filter', 'Filter ($filter, optional)', "userId eq '{{agent.employee_id}}'"],
                        ['select', 'Fields to return ($select, optional)', 'userId, jobTitle, department'],
                        ['top', 'Maximum records', '20'],
                    ] },
                    upsert: { label: 'Create or update a record (upsert)', fields: [
                        ['entity', 'Entity', 'PerPersonal'],
                        ['body', 'Record as JSON', '{"personIdExternal": "{{agent.employee_id}}"}', true],
                    ] },
                } },
            },
        },

        /* ---- CRM ---- */
        hubspot: { group: CRM, label: 'HubSpot', color: '#fb923c', actions: {
            find: { label: 'Find contact', fields: [['email', 'Email', '{{agent.recipient}}']] },
            deal: { label: 'Create deal', fields: [['name', 'Deal name', '{{agent.subject}}'], ['amount', 'Amount', '']] },
        } },
    };

    const CATEGORY_STYLE = {
        trigger: { icon: '↯', cls: 'bg-cyan-300/10 text-cyan-100', edge: '#67e8f9' },
        orchestrator: { icon: '◎', cls: 'bg-violet-300/15 text-violet-100', edge: '#a78bfa' },
        agent: { icon: '✦', cls: 'bg-sky-300/10 text-sky-100', edge: '#7dd3fc' },
        app: { icon: '▣', cls: 'bg-emerald-300/10 text-emerald-100', edge: '#6ee7b7' },
        action: { icon: '✦', cls: 'bg-violet-300/10 text-violet-100', edge: '#c4b5fd' },
        condition: { icon: '◇', cls: 'bg-amber-300/10 text-amber-100', edge: '#fbbf24' },
        transform: { icon: '⇄', cls: 'bg-indigo-300/10 text-indigo-100', edge: '#a5b4fc' },
        output: { icon: '✓', cls: 'bg-teal-300/10 text-teal-100', edge: '#5eead4' },
        validation: { icon: '✓', cls: 'bg-teal-300/10 text-teal-100', edge: '#5eead4' },
        ai_decision: { icon: '◈', cls: 'bg-violet-300/10 text-violet-100', edge: '#c4b5fd' },
        delay: { icon: '◷', cls: 'bg-sky-300/10 text-sky-100', edge: '#7dd3fc' },
        process: { icon: '✧', cls: 'bg-cyan-300/10 text-cyan-100', edge: '#67e8f9' },
    };

    const COL = { trigger: 50, orchestrator: 982, agent: 710, app: 1040 };
    const ROW = 160;
    const NODE_W = 236;
    const PROCESS_NODE_W = 56;
    const PROCESS_NODE_H = 56;

    let catalog = { ...LOCAL_CATALOG };
    let workflows = [];
    let current = null;
    let selectedNodeId = null;
    let selectedEdgeId = null;
    let transform = { x: 80, y: 70, scale: 0.85 };
    let pendingSource = null;
    let gesture = null;
    let autosaveTimer = null;
    let messageTimer = null;
    let workflowDirty = false;
    let saveFailed = false;
    let workflowRevision = 0;
    let savedRevision = 0;
    let workflowEpoch = 0;
    let saveInFlight = null;
    let saveRequested = false;
    let webhookUrl = '';
    let connections = [];
    let aiModels = [];
    let defaultAiModel = '';
    let appCapabilities = {};
    let apiConnections = [];
    let runNodeStates = {};
    let activeRunEdges = new Set();
    let executionPollTimer = null;
    let executionPollInFlight = false;
    let executionPollGeneration = 0;
    let agentStatusSummaries = [];
    let activePaletteDrag = null;
    let activeMoveNodeId = null;
    let dragPreview = null;
    let previewFrame = null;

    const clone = (value) => JSON.parse(JSON.stringify(value));
    const graph = () => current?.workflow_data || { nodes: [], edges: [] };
    const nodeById = (id) => graph().nodes.find((node) => node.id === id);
    const variantOf = (appKey, variant) => {
        const def = APPS[appKey];
        if (!def?.variants) return null;
        return def.variants[variant] ? variant : Object.keys(def.variants)[0];
    };
    const actionsFor = (appKey, variant) => {
        const def = APPS[appKey];
        if (!def) return {};
        return def.variants ? def.variants[variantOf(appKey, variant)].actions : def.actions;
    };
    const initialValues = (appKey, action, variant) => {
        const values = {};
        (actionsFor(appKey, variant)[action]?.fields || []).forEach((f) => { if (f[4]) values[f[0]] = f[4][0][0]; });
        return values;
    };
    const catOf = (node) => node?.type === 'orchestrator' ? 'orchestrator' : node?.type === 'app_action' ? 'app' : (catalog[node?.type]?.category || 'action');
    const childrenOf = (id) => graph().edges.filter((edge) => edge.source === id).map((edge) => nodeById(edge.target)).filter(Boolean);
    const parentOf = (id) => {
        const edge = graph().edges.find((item) => item.target === id);
        return edge ? nodeById(edge.source) : null;
    };
    const appDef = (node) => APPS[node?.config?.app] || Object.values(APPS)[0];
    const isProcessNode = (nodeOrType) => {
        const category = typeof nodeOrType === 'string' ? catalog[nodeOrType]?.category : catOf(nodeOrType);
        return ['condition', 'transform', 'output', 'process', 'validation', 'delay', 'ai_decision'].includes(category);
    };

    function showMessage(message, error = false, autoDismiss = !error) {
        const el = $('wf-message');
        el.textContent = message;
        el.classList.remove('hidden', 'text-red-300', 'text-emerald-200', 'text-white/50');
        el.classList.add(error ? 'text-red-300' : 'text-emerald-200');
        clearTimeout(messageTimer);
        if (autoDismiss) messageTimer = setTimeout(() => el.classList.add('hidden'), 5000);
    }

    async function request(url, options = {}, allowFailure = false) {
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
        if (!allowFailure && (!response.ok || data.success === false)) {
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
            edges: graph().edges.map((edge) => ({
                ...(edge.id ? { id: edge.id } : {}),
                source: edge.source,
                target: edge.target,
                edge_type: edge.edge_type || inferEdgeType(nodeById(edge.source), nodeById(edge.target)),
                config: edge.config || {},
                ...(edge.metadata ? { metadata: edge.metadata } : {}),
                ...(edge.label ? { label: edge.label } : {}),
            })),
            viewport: { x: transform.x, y: transform.y, zoom: transform.scale },
        };
    }

    function setSaveStatus(label, state = '') {
        const element = $('wf-save-status');
        if (!element) return;
        element.textContent = label;
        element.dataset.state = state;
        element.className = `px-1 text-[10px] ${state === 'error' ? 'text-red-300' : state === 'saved' ? 'text-emerald-200/60' : 'text-white/45'}`;
    }

    function refreshSaveStatus() {
        if (saveInFlight) {
            setSaveStatus('Saving...', 'saving');
        } else if (saveFailed && workflowDirty) {
            setSaveStatus('Save failed', 'error');
        } else if (workflowDirty || !current?.id) {
            setSaveStatus('Unsaved changes', 'dirty');
        } else {
            setSaveStatus('Saved', 'saved');
        }
    }

    function markDirty() {
        if (!current || current.status === 'active') return;
        workflowRevision += 1;
        workflowDirty = true;
        saveFailed = false;
        setSaveStatus('Unsaved changes', 'dirty');
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(() => {
            autosaveTimer = null;
            saveWorkflow(true);
        }, 1500);
    }

    function setLoadingWorkflow(loading, message = 'Loading workflow...') {
        const overlay = $('wf-loading');
        if (!overlay) return;
        overlay.textContent = message;
        overlay.classList.toggle('hidden', !loading);
    }

    /* ------------------------------------------------------------ layout */

    function tidyLayout() {
        const nodes = graph().nodes;
        const orchestrator = nodes.find((node) => catOf(node) === 'orchestrator');
        const agents = nodes.filter((node) => catOf(node) === 'agent');
        let rowY = 60;
        agents.forEach((agent) => {
            const apps = childrenOf(agent.id).filter((node) => catOf(node) === 'app');
            const block = Math.max(1, apps.length) * ROW;
            agent.position = { x: COL.agent, y: rowY + (block - ROW) / 2 };
            apps.forEach((app, index) => { app.position = { x: COL.app, y: rowY + index * ROW }; });
            rowY += block + 20;
        });
        if (orchestrator) {
            orchestrator.position = { x: COL.orchestrator, y: agents.length ? (agents[0].position.y + agents[agents.length - 1].position.y) / 2 : 60 };
            parentsOf(orchestrator.id).filter((node) => catOf(node) === 'trigger').forEach((trigger) => {
                trigger.position = { x: COL.trigger, y: orchestrator.position.y };
            });
        }
    }

    function parentsOf(id) {
        return graph().edges.filter((edge) => edge.target === id).map((edge) => nodeById(edge.source)).filter(Boolean);
    }

    /* ------------------------------------------------------------ lists */

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

    function paletteHeading(text) {
        const heading = document.createElement('div');
        heading.className = 'px-2.5 pb-1 pt-4 text-[10px] uppercase tracking-[.18em] text-white/35';
        heading.textContent = text;
        return heading;
    }

    function paletteEntry({ type, preset = '', label, description, category, color }) {
        const style = CATEGORY_STYLE[category] || CATEGORY_STYLE.action;
        const button = document.createElement('button');
        button.type = 'button';
        button.draggable = true;
        button.dataset.nodeType = type;
        button.className = 'group w-full rounded-xl border border-transparent px-2.5 py-2 text-left transition hover:border-white/[.08] hover:bg-white/[.035]';
        const row = document.createElement('div');
        row.className = 'flex items-center gap-2 text-xs text-white/75 group-hover:text-white';
        const icon = document.createElement('span');
        icon.className = `flex h-7 w-7 items-center justify-center rounded-lg text-[10px] ${style.cls}`;
        icon.textContent = category === 'app' ? label[0] : style.icon;
        if (color) { icon.style.background = `${color}26`; icon.style.color = color; }
        const title = document.createElement('span');
        title.textContent = label;
        row.append(icon, title);
        button.append(row);
        if (description) {
            const text = document.createElement('div');
            text.className = 'mt-1 pl-9 text-[9px] leading-4 text-white/30';
            text.textContent = description;
            button.append(text);
        }
        button.addEventListener('click', () => addNode(type, null, preset));
        button.addEventListener('dragstart', (event) => {
            event.dataTransfer.setData('application/x-jc-workflow-node', `${type}:${preset}`);
            event.dataTransfer.effectAllowed = 'copy';
            activePaletteDrag = { type, preset };
        });
        button.addEventListener('dragend', finishPaletteDrag);
        return button;
    }

    function renderPalette() {
        const palette = $('wf-palette');
        palette.replaceChildren();
        const entries = Object.entries(catalog);
        const triggers = entries.filter(([, def]) => def.category === 'trigger');
        const others = entries.filter(([type, def]) => def.category === 'action' && !NEW_TYPES.includes(type));
        const automation = entries.filter(([, def]) => ['condition', 'transform', 'output', 'process', 'validation', 'delay', 'ai_decision'].includes(def.category));

        if (triggers.length) {
            palette.append(paletteHeading('Start'));
            triggers.forEach(([type, def]) => palette.append(paletteEntry({ type, label: def.label, description: def.description, category: 'trigger' })));
        }
        palette.append(paletteHeading('Orchestrator'));
        palette.append(paletteEntry({ type: 'orchestrator', label: catalog.orchestrator.label, description: catalog.orchestrator.description, category: 'orchestrator' }));

        palette.append(paletteHeading('AI agents'));
        Object.entries(SPECS).forEach(([key, spec]) => palette.append(paletteEntry({
            type: 'ai_agent', preset: key, label: `${spec.label} agent`, category: 'agent',
            description: key === 'custom' ? 'Name your own specialization.' : '',
        })));

        const appGroups = {};
        Object.entries(APPS).forEach(([key, app]) => { (appGroups[app.group] ||= []).push([key, app]); });
        Object.entries(appGroups).forEach(([group, items]) => {
            palette.append(paletteHeading(`Apps · ${group}`));
            items.forEach(([key, app]) => palette.append(paletteEntry({
                type: 'app_action', preset: key, label: app.label, category: 'app', color: app.color,
            })));
        });

        if (automation.length) {
            palette.append(paletteHeading('Process steps'));
            automation.forEach(([type, def]) => palette.append(paletteEntry({ type, label: def.label, description: def.description, category: def.category })));
        }

        if (others.length) {
            palette.append(paletteHeading('Other actions'));
            others.forEach(([type, def]) => palette.append(paletteEntry({ type, label: def.label, description: def.description, category: 'action' })));
        }
    }

    function setTransform() {
        const world = $('wf-world');
        world.style.transform = `translate(${transform.x}px, ${transform.y}px) scale(${transform.scale})`;
        world.classList.toggle('wf-zoom-low', transform.scale < 0.72);
        $('wf-zoom-label').textContent = `${Math.round(transform.scale * 100)}%`;
        if (current?.workflow_data) {
            current.workflow_data.viewport = { x: transform.x, y: transform.y, zoom: transform.scale };
        }
    }

    /* ------------------------------------------------------------ nodes */

    function nodeSubtitle(node) {
        switch (catOf(node)) {
            case 'trigger': return node.type === 'manual_trigger' ? 'Manual test trigger' : 'Trigger';
            case 'orchestrator': return 'Orchestrator';
            case 'agent': {
                const config = node.config || {};
                return `AI agent · ${config.specialization === 'custom' ? (config.custom_specialization || 'Custom') : (SPECS[config.specialization]?.label || 'Custom')}`;
            }
            case 'app': {
                const def = appDef(node);
                return def.variants?.[variantOf(node.config?.app, node.config?.variant)]?.label || def.label;
            }
            case 'condition': return 'Condition';
            case 'transform': return 'Transform';
            case 'output': return 'Output';
            default: return 'Action';
        }
    }

    function renderNodes() {
        const layer = $('wf-nodes');
        layer.replaceChildren();
        const nodes = graph().nodes;
        const stepNumbers = executionStepLabels();
        $('wf-drop-hint').classList.toggle('hidden', nodes.length > 0);
        const agentCount = nodes.filter((node) => catOf(node) === 'agent').length;
        const appCount = nodes.filter((node) => catOf(node) === 'app').length;
        $('wf-node-count').textContent = `${nodes.length} ${nodes.length === 1 ? 'step' : 'steps'} · ${agentCount} ${agentCount === 1 ? 'agent' : 'agents'} · ${appCount} ${appCount === 1 ? 'app' : 'apps'}`;

        nodes.forEach((node) => {
            const definition = catalog[node.type] || { label: node.type, category: 'action' };
            const category = catOf(node);
            const style = CATEGORY_STYLE[category] || CATEGORY_STYLE.action;
            const isApp = category === 'app';
            const isProcess = isProcessNode(node);
            const accent = isApp ? appDef(node).color : style.edge;

            const card = document.createElement('article');
            card.className = isProcess
                ? 'wf-node wf-process-node'
                : 'wf-node rounded-xl border border-white/10 bg-[#0c1422]/95 shadow-[0_12px_36px_rgba(0,0,0,.28)] backdrop-blur';
            card.dataset.nodeId = node.id;
            card.dataset.category = category;
            card.dataset.selected = String(selectedNodeId === node.id);
            card.dataset.runStatus = runNodeStates[node.id] || '';
            const displayPosition = visualNodePosition(node);
            card.style.left = `${displayPosition.x}px`;
            card.style.top = `${displayPosition.y}px`;
            let header;
            if (isProcess) {
                const name = node.label || definition.label;
                const warning = processConfigurationWarning(node);
                card.title = [name, processTooltipDetail(node), warning, 'Click to configure.'].filter(Boolean).join('\n');
                card.setAttribute('role', 'group');
                card.setAttribute('aria-label', `${name}. ${processTooltipDetail(node)}${warning ? `. ${warning}` : ''} Click to configure.`);
                card.style.setProperty('--wf-process-accent', accent);

                header = document.createElement('div');
                header.className = 'wf-process-layout wf-node-header';
                const stepNumber = document.createElement('span');
                stepNumber.className = 'wf-process-step-number';
                stepNumber.textContent = stepNumbers[node.id] || '·';
                const icon = document.createElement('button');
                icon.type = 'button';
                icon.className = 'wf-process-icon';
                icon.textContent = style.icon;
                icon.setAttribute('aria-label', `Configure ${name}. ${processTooltipDetail(node)}${warning ? `. ${warning}` : ''}`);
                icon.title = card.title;
                const stateMarker = processStatusMarker(runNodeStates[node.id]);
                if (stateMarker) {
                    const status = document.createElement('span');
                    status.className = `wf-process-status wf-process-status-${runNodeStates[node.id]}`;
                    status.textContent = stateMarker;
                    status.setAttribute('aria-hidden', 'true');
                    icon.append(status);
                }
                if (warning) {
                    const needsSetup = document.createElement('span');
                    needsSetup.className = 'wf-process-warning';
                    needsSetup.textContent = '!';
                    needsSetup.setAttribute('aria-hidden', 'true');
                    icon.append(needsSetup);
                }
                const label = document.createElement('span');
                label.className = 'wf-process-label';
                label.textContent = processNodeLabel(node);
                header.append(stepNumber, icon, label);
                card.append(header);
            } else {
                card.style.borderLeft = `3px solid ${accent}`;
                header = document.createElement('div');
                header.className = 'wf-node-header flex cursor-grab items-center gap-2 border-b border-white/[.07] px-3 py-2.5 active:cursor-grabbing';
                const icon = document.createElement('span');
                icon.className = `flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-[10px] ${style.cls}`;
                icon.textContent = isApp ? appDef(node).label[0] : style.icon;
                if (isApp) { icon.style.background = `${accent}26`; icon.style.color = accent; }
                const stepNumber = document.createElement('span');
                stepNumber.className = 'shrink-0 rounded-md border border-white/[.08] px-1.5 py-1 font-mono text-[8px] text-white/35';
                stepNumber.textContent = stepNumbers[node.id] || '·';
                const titleWrap = document.createElement('div');
                titleWrap.className = 'min-w-0 flex-1';
                const title = document.createElement('div');
                title.className = 'truncate text-[11px] font-medium text-white/85';
                title.textContent = node.label || (isApp ? appActionLabel(node) : definition.label);
                const subtitle = document.createElement('div');
                subtitle.className = 'mt-0.5 text-[8px] uppercase tracking-[.14em] text-white/30';
                subtitle.textContent = nodeSubtitle(node);
                titleWrap.append(title, subtitle);
                if (runNodeStates[node.id]) {
                    const runState = document.createElement('div');
                    runState.className = `wf-run-status mt-1 text-[8px] uppercase tracking-[.14em] ${runNodeStates[node.id] === 'failed' ? 'text-red-200/80' : runNodeStates[node.id] === 'success' ? 'text-emerald-200/80' : 'text-cyan-100/50'}`;
                    runState.textContent = runNodeStates[node.id];
                    titleWrap.append(runState);
                }
                header.append(icon, stepNumber, titleWrap);
                card.append(header);

                const preview = document.createElement('div');
                preview.className = 'wf-node-preview px-3 py-3 text-[10px] leading-4 text-white/40';
                preview.textContent = nodePreview(node);
                card.append(preview);
            }

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
                const source = nodeById(pendingSource);
                if (!nodeById(node.id) || !canLink(source, node)) {
                    showMessage(linkHint(), true);
                    pendingSource = null;
                    return;
                }
                const result = createConnection(source, node, 'manual');
                const opensAppConnection = catOf(source) === 'app' && catOf(node) === 'app';
                selectedEdgeId = opensAppConnection ? result.edge?.id || null : null;
                selectedNodeId = opensAppConnection ? null : node.id;
                pendingSource = null;
                if (!result.created) return;
                markDirty();
                renderAll();
                if (result.replacedExisting) showMessage(`Connection changed to ${source.label || catalog[source.type]?.label || 'the selected step'}.`);
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
                selectedEdgeId = null;
                selectedNodeId = node.id;
                renderAll();
            });
            header.addEventListener('pointerdown', (event) => {
                if (event.button !== 0) return;
                if (current?.status === 'active') return;
                event.preventDefault();
                event.stopPropagation();
                selectedEdgeId = null;
                selectedNodeId = node.id;
                gesture = {
                    kind: 'node', nodeId: node.id,
                    x: event.clientX, y: event.clientY,
                    startX: visualNodePosition(node).x, startY: visualNodePosition(node).y,
                };
                activeMoveNodeId = isProcessNode(node) ? node.id : null;
                card.setPointerCapture?.(event.pointerId);
            });
            layer.append(card);
        });
        renderEdges();
        setTransform();
    }

    function appActionLabel(node) {
        const def = appDef(node);
        const variant = def.variants?.[variantOf(node.config?.app, node.config?.variant)];
        const actions = actionsFor(node.config?.app, node.config?.variant);
        return `${variant ? variant.label : def.label}: ${actions[node.config?.action]?.label || 'Choose an action'}`;
    }

    function processConfigurationWarning(node) {
        const config = node.config || {};
        if (node.type === 'transform' && !Object.keys(config.mappings || {}).length) {
            return 'Transform requires field mapping.';
        }
        if (node.type === 'condition') {
            if (!String(config.field || '').trim()) return 'Condition needs a field to check.';
            if (['equals', 'not_equals', 'greater_than'].includes(config.operator) && String(config.value ?? '') === '') {
                return 'Condition needs a comparison value.';
            }
        }
        if (node.type === 'ai_decision' && !String(config.instruction || '').trim()) {
            return 'AI Decision needs instructions.';
        }
        if (node.type === 'delay' && !(Number(config.duration) > 0)) {
            return 'Delay needs a duration.';
        }
        if (node.type === 'validation' && !Object.keys(config.rules || {}).length) {
            return 'Validation needs at least one rule.';
        }
        return '';
    }

    function processTooltipDetail(node) {
        if (node.type === 'transform') {
            const count = Object.keys(node.config?.mappings || {}).length;
            return `${count} mapped ${count === 1 ? 'field' : 'fields'}`;
        }
        return nodePreview(node) || processNodeLabel(node);
    }

    function processNodeLabel(node) {
        const config = node.config || {};
        if (node.type === 'transform') {
            const count = Object.keys(config.mappings || {}).length;
            return `${count} ${count === 1 ? 'field' : 'fields'}`;
        }
        if (node.type === 'condition') {
            const operators = { greater_than: '>', equals: '=', not_equals: '≠', exists: 'exists', not_empty: 'not empty' };
            const fieldName = String(config.field || '').split('.').at(-1);
            return fieldName ? `${fieldName} ${operators[config.operator || 'not_empty'] || ''} ${config.value ?? ''}`.trim() : 'Condition';
        }
        if (node.type === 'output') return 'Output';
        if (node.type === 'delay') return config.duration ? `${config.duration} ${config.unit || 'min'}` : 'Delay';
        return (node.label || catalog[node.type]?.label || 'Process step').slice(0, 28);
    }

    function processStatusMarker(status) {
        return ({ success: '✓', simulated: '✓', failed: '!', running: '•', retrying: '↻' })[status] || '';
    }

    function nodePreview(node) {
        const config = node.config || {};
        switch (node.type) {
            case 'email_trigger': return config.subject_contains ? `Subject contains “${config.subject_contains}”` : 'When a new email arrives';
            case 'schedule_trigger': return `${config.frequency || 'daily'} · ${config.time || '09:00'} ${config.timezone || ''}`.trim();
            case 'webhook_trigger': return 'Waits for a signed webhook event';
            case 'manual_trigger': return 'Sample source row for Test workflow';
            case 'condition': {
                const operators = { greater_than: '>', equals: '=', not_equals: '≠', exists: 'exists', not_empty: 'is not empty' };
                return `${config.field || 'Choose a field'} ${operators[config.operator || 'not_empty']} ${config.value ?? ''}`.trim();
            }
            case 'transform': return `${Object.keys(config.mappings || {}).length} mapped fields`;
            case 'output': return 'Record the result';
            case 'validation': return `${Object.keys(config.rules || {}).length} validation rules`;
            case 'ai_decision': return config.instruction || node.label || 'Add decision instructions';
            case 'delay': return config.duration ? `${config.duration} ${config.unit || 'min'}` : 'Choose a delay duration';
            case 'jarvis_ai': return config.instruction || 'Add an instruction in step settings';
            case 'send_email': return config.to ? `To ${config.to}` : 'Configure recipient and message';
            case 'webhook_action': return config.url || 'Configure an HTTPS endpoint';
            case 'orchestrator': {
                const count = childrenOf(node.id).filter((child) => catOf(child) === 'agent').length;
                return `Routes to ${count} ${count === 1 ? 'agent' : 'agents'} · ${ROUTING[config.routing || 'auto']}`;
            }
            case 'ai_agent': {
                const apps = childrenOf(node.id).filter((child) => catOf(child) === 'app').length;
                const snippet = (config.instruction || '').trim();
                return `${apps ? `${apps} ${apps === 1 ? 'app' : 'apps'}` : 'No apps yet'} · ${snippet ? snippet.slice(0, 70) + (snippet.length > 70 ? '…' : '') : 'Add instructions'}`;
            }
            case 'app_action': {
                const action = actionsFor(config.app, config.variant)[config.action]?.label || 'Choose an action';
                const entity = config.values?.entity || config.values?.resource;
                const connection = (config.app === 'custom_api' ? apiConnections : connections).find((item) => String(item.id) === String(config.values?.connection_id || config.values?.connection));
                const details = [config.app === 'custom_api' && config.values?.url ? `${config.values.method || 'GET'} ${config.values.url}` : action];
                if (entity) details.push(entity);
                if (connection) details.push(connection.name);
                return details.join(' · ');
            }
            default: return '';
        }
    }

    function renderEdges() {
        const svg = $('wf-edges');
        svg.replaceChildren();
        const ns = 'http://www.w3.org/2000/svg';
        const defs = document.createElementNS(ns, 'defs');
        const marker = document.createElementNS(ns, 'marker');
        marker.setAttribute('id', 'wf-arrow');
        marker.setAttribute('viewBox', '0 0 10 10');
        marker.setAttribute('refX', '8');
        marker.setAttribute('refY', '5');
        marker.setAttribute('markerWidth', '5');
        marker.setAttribute('markerHeight', '5');
        marker.setAttribute('orient', 'auto-start-reverse');
        const arrow = document.createElementNS(ns, 'path');
        arrow.setAttribute('d', 'M 0 0 L 10 5 L 0 10 z');
        arrow.setAttribute('fill', '#67e8f9');
        marker.append(arrow);
        defs.append(marker);
        svg.append(defs);
        $('wf-nodes').querySelectorAll('.wf-drop-target').forEach((node) => node.classList.remove('wf-drop-target'));
        graph().edges.forEach((edge) => {
            const source = nodeById(edge.source);
            const target = nodeById(edge.target);
            if (!source || !target) return;
            const active = selectedEdgeId === edge.id || (selectedNodeId && (edge.source === selectedNodeId || edge.target === selectedNodeId));
            const path = document.createElementNS(ns, 'path');
            path.setAttribute('d', edgePath(source, visualNodePosition(target), cardHeight(target)));
            const onRunPath = activeRunEdges.has(`${edge.source}>${edge.target}`);
            const insertTarget = dragPreview?.edgeId === edge.id;
            path.setAttribute('class', `wf-line wf-line-selectable${active ? ' wf-line-active' : ''}${onRunPath ? ' wf-line-run-success' : ''}${insertTarget ? ' wf-line-insert-target' : ''}`);
            path.setAttribute('marker-end', 'url(#wf-arrow)');
            path.setAttribute('tabindex', '0');
            path.setAttribute('role', 'button');
            path.setAttribute('aria-label', `${nodeDisplayName(source)} to ${nodeDisplayName(target)} connection settings`);
            path.dataset.source = edge.source;
            path.dataset.target = edge.target;
            path.dataset.edgeId = edge.id || '';
            path.addEventListener('click', (event) => {
                event.stopPropagation();
                selectedEdgeId = edge.id || `${edge.source}>${edge.target}`;
                selectedNodeId = null;
                renderAll();
            });
            path.addEventListener('keydown', (event) => {
                if (!['Enter', ' '].includes(event.key)) return;
                event.preventDefault();
                selectedEdgeId = edge.id || `${edge.source}>${edge.target}`;
                selectedNodeId = null;
                renderAll();
            });
            svg.append(path);
            const label = edgeLabel(edge);
            if (label) {
                const text = document.createElementNS(ns, 'text');
                const midpoint = edgeMidpoint(source, target);
                text.setAttribute('x', midpoint.x);
                text.setAttribute('y', midpoint.y - 7);
                text.setAttribute('class', 'wf-edge-label');
                text.textContent = label;
                svg.append(text);
            }
        });

        if (dragPreview?.edgeId) {
            const edge = graph().edges.find((candidate) => candidate.id === dragPreview.edgeId);
            const source = edge && nodeById(edge.source);
            const target = edge && nodeById(edge.target);
            if (source && target) {
                const midpoint = edgeMidpoint(source, target);
                const group = document.createElementNS(ns, 'g');
                group.setAttribute('class', 'wf-insert-indicator');
                group.setAttribute('transform', `translate(${midpoint.x} ${midpoint.y})`);
                group.setAttribute('pointer-events', 'none');
                const plate = document.createElementNS(ns, 'rect');
                plate.setAttribute('x', '-68');
                plate.setAttribute('y', '-12');
                plate.setAttribute('width', '136');
                plate.setAttribute('height', '24');
                plate.setAttribute('rx', '12');
                plate.setAttribute('fill', '#10251f');
                plate.setAttribute('stroke', '#6ee7b7');
                plate.setAttribute('stroke-opacity', '.8');
                const label = document.createElementNS(ns, 'text');
                label.setAttribute('text-anchor', 'middle');
                label.setAttribute('dominant-baseline', 'central');
                label.setAttribute('fill', '#d1fae5');
                label.setAttribute('font-size', '9');
                label.textContent = `+ INSERT ${catalog[dragPreview.type]?.label || 'STEP'}`;
                group.append(plate, label);
                svg.append(group);
            }
        } else if (dragPreview) {
            const source = nodeById(dragPreview.sourceId);
            const targetCard = $('wf-nodes').querySelector(`[data-node-id="${CSS.escape(dragPreview.highlightNodeId)}"]`);
            targetCard?.classList.add('wf-drop-target');
            if (source) {
                const path = document.createElementNS(ns, 'path');
                path.setAttribute('d', edgePath(source, dragPreview.position, 112));
                path.setAttribute('class', 'wf-line wf-line-preview');
                path.setAttribute('marker-end', 'url(#wf-arrow)');
                svg.append(path);
            }
        }
    }

    function cardHeight(node) {
        return $('wf-nodes').querySelector(`[data-node-id="${CSS.escape(node.id)}"]`)?.offsetHeight || 112;
    }

    function nodeWidth(node) {
        return $('wf-nodes').querySelector(`[data-node-id="${CSS.escape(node.id)}"]`)?.offsetWidth || (isProcessNode(node) ? PROCESS_NODE_W : NODE_W);
    }

    function visualNodePosition(node) {
        const position = { x: Number(node.position?.x || 0), y: Number(node.position?.y || 0) };
        if (!isProcessNode(node) || node.presentation?.process_icon_position === true) return position;
        return { x: position.x + 67, y: position.y + 6 };
    }

    function edgePath(source, targetPosition, targetHeight = 112) {
        const sourcePosition = visualNodePosition(source);
        const x1 = sourcePosition.x + nodeWidth(source);
        const y1 = sourcePosition.y + cardHeight(source) / 2;
        const x2 = Number(targetPosition?.x || 0);
        const y2 = Number(targetPosition?.y || 0) + targetHeight / 2;
        const bend = Math.max(50, Math.abs(x2 - x1) * 0.48);
        return `M ${x1} ${y1} C ${x1 + bend} ${y1}, ${x2 - bend} ${y2}, ${x2} ${y2}`;
    }

    function edgeMidpoint(source, target) {
        const from = nodeCenter(source);
        const to = nodeCenter(target);
        return { x: (from.x + to.x) / 2, y: (from.y + to.y) / 2 };
    }

    function eligibleInsertionEdge(edge, processType, excludedNodeId = null) {
        if (!edge || edge.source === excludedNodeId || edge.target === excludedNodeId) return false;
        const source = nodeById(edge.source);
        const target = nodeById(edge.target);
        if (!source || !target || ['trigger', 'orchestrator', 'agent'].includes(catOf(source))
            || ['trigger', 'orchestrator', 'agent'].includes(catOf(target))) return false;
        const edgeType = edge.edge_type || inferEdgeType(source, target);
        if (!['data_flow', 'control_flow', 'conditional_flow'].includes(edgeType)) return false;
        const process = { id: '__insert_candidate__', type: processType, config: {} };
        return isProcessNode(process) && canLink(source, process) && canLink(process, target);
    }

    function nearestInsertionEdge(position, processType, excludedNodeId = null) {
        const threshold = 52 / transform.scale;
        let nearest = null;
        graph().edges.forEach((edge) => {
            if (!eligibleInsertionEdge(edge, processType, excludedNodeId)) return;
            const source = nodeById(edge.source);
            const target = nodeById(edge.target);
            const sourcePosition = visualNodePosition(source);
            const targetPosition = visualNodePosition(target);
            const from = { x: sourcePosition.x + nodeWidth(source), y: sourcePosition.y + cardHeight(source) / 2 };
            const to = { x: targetPosition.x, y: targetPosition.y + cardHeight(target) / 2 };
            const bend = Math.max(50, Math.abs(to.x - from.x) * 0.48);
            let distance = Infinity;
            for (let step = 0; step <= 24; step++) {
                const t = step / 24;
                const inverse = 1 - t;
                const x = inverse ** 3 * from.x + 3 * inverse ** 2 * t * (from.x + bend)
                    + 3 * inverse * t ** 2 * (to.x - bend) + t ** 3 * to.x;
                const y = inverse ** 3 * from.y + 3 * inverse ** 2 * t * from.y
                    + 3 * inverse * t ** 2 * to.y + t ** 3 * to.y;
                distance = Math.min(distance, Math.hypot(position.x - x, position.y - y));
            }
            if (distance <= threshold && (!nearest || distance < nearest.distance)) nearest = { edge, distance };
        });
        return nearest?.edge || null;
    }

    function edgeLabel(edge) {
        const config = edge.config || {};
        if (config.branch) return `if ${config.branch}`;
        if (config.trigger && config.trigger !== 'manual') return config.trigger.replaceAll('_', ' ');
        if (config.mode) return config.mode;
        return '';
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
        status.className = `rounded-full border px-2.5 py-1 text-[10px] uppercase tracking-wider ${current.status === 'active' ? 'border-emerald-300/20 bg-emerald-300/[.06] text-emerald-200' : ['error', 'invalid'].includes(current.status) ? 'border-red-300/20 bg-red-300/[.06] text-red-200' : 'border-white/10 bg-white/[.03] text-white/45'}`;
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

    /* ------------------------------------------------------------ adding steps */

    function defaultsFor(type, preset = '') {
        const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
        switch (type) {
            case 'manual_trigger': return {};
            case 'condition': return { field: 'quantity', operator: 'greater_than', value: '0' };
            case 'transform': return { mappings: {} };
            case 'output': return {};
            case 'email_trigger': return { from: '', subject_contains: '' };
            case 'schedule_trigger': return { frequency: 'daily', time: '09:00', day: 'monday', timezone: timeZone };
            case 'webhook_trigger': return {};
            case 'jarvis_ai': return { instruction: '', input: '', fields: ['summary'] };
            case 'send_email': return { to: '', subject: '', body: '' };
            case 'webhook_action': return { url: '', method: 'POST', payload: '' };
            case 'orchestrator': return {
                model: '',
                instruction: 'Read each incoming request, decide which specialist agent should handle it, and pass along the full context. If no agent fits, stop and ask me.',
                routing: 'auto',
            };
            case 'ai_agent': {
                const key = SPECS[preset] ? preset : 'custom';
                return {
                    specialization: key, custom_specialization: '', handles: SPECS[key].routes,
                    instruction: SPECS[key].prompt, require_approval: true, fields: ['summary'],
                    model: '', permissions: { read: true, analyze: true, report: true, draft: true, write: false, send: false, delete: false },
                    execution_mode: 'ai_assisted', task_type: 'workflow_execution', priority: 50,
                };
            }
            case 'app_action': {
                const key = APPS[preset] ? preset : 'gmail';
                const variant = variantOf(key);
                const action = Object.keys(actionsFor(key, variant))[0];
                return { app: key, ...(variant ? { variant } : {}), action, values: initialValues(key, action, variant) };
            }
            default: return {};
        }
    }

    function loadTestFixture() {
        if (!current || current.status === 'active') {
            showMessage('Pause the active workflow before loading a test fixture.', true);
            return;
        }
        if (graph().nodes.length && !window.confirm('Replace the current canvas with the Operations test fixture?')) return;

        const makeNode = (type, label, x, y, config = {}) => ({ id: newNodeId(), type, label, position: { x, y }, config });
        const trigger = makeNode('manual_trigger', 'Operation Masterlist', 50, 260);
        const agent = makeNode('ai_agent', 'Operations Agent', 350, 260, {
            ...defaultsFor('ai_agent', 'operations'), execution_mode: 'deterministic',
        });
        const condition = makeNode('condition', 'Required fields valid?', 660, 260, {
            field: 'result.quantity', operator: 'greater_than', value: '0',
        });
        const transform = makeNode('transform', 'Map masterlist fields', 960, 170, {
            mappings: {
                project_code: 'data.result.project_code',
                item_code: 'data.result.item_code',
                quantity: 'data.result.quantity',
                school_id: 'data.result.school_id',
                dr_number: 'data.result.dr_number',
            },
        });
        const tracker = makeNode('app_action', 'MMC Tracker', 1280, 120, {
            app: 'mmc_tracker', action: 'upsert', values: { duplicate_key: 'project_code' }, test_behavior: 'success',
        });
        const sap = makeNode('app_action', 'SAP Business One', 1280, 390, {
            app: 'sap', variant: 'b1', action: 'create', values: { resource: 'Deliveries' }, test_behavior: 'success',
        });
        const output = makeNode('output', 'Record result', 1600, 260);
        const makeEdge = (source, target, type, config = {}) => ({
            id: newEdgeId(), source: source.id, target: target.id, edge_type: type, config,
        });

        current.name = 'Operations masterlist test';
        current.description = 'Safe sample flow: validate and map one source row into parallel mock app branches.';
        current.workflow_data = {
            nodes: [trigger, agent, condition, transform, tracker, sap, output],
            edges: [
                makeEdge(trigger, agent, 'trigger_flow'),
                makeEdge(agent, condition, 'agent_capability'),
                makeEdge(condition, transform, 'conditional_flow', { branch: 'true' }),
                makeEdge(condition, output, 'conditional_flow', { branch: 'false' }),
                makeEdge(transform, tracker, 'data_flow', { mode: 'upsert', on_error: 'continue', mapping: {} }),
                makeEdge(transform, sap, 'data_flow', { mode: 'upsert', on_error: 'continue', mapping: {} }),
                makeEdge(tracker, output, 'data_flow', { on_error: 'continue', mapping: {} }),
                makeEdge(sap, output, 'data_flow', { on_error: 'continue', mapping: {} }),
            ],
            viewport: null,
        };
        selectedNodeId = null;
        selectedEdgeId = null;
        markDirty();
        renderAll();
        requestAnimationFrame(fitCanvas);
        showMessage('Operations test fixture loaded. Save it, then run Test workflow.');
    }

    function nextPosition() {
        const nodes = graph().nodes;
        if (!nodes.length) return { x: COL.orchestrator, y: 844 };
        const last = nodes[nodes.length - 1];
        return { x: Math.min(Number(last.position?.x || 240) + 330, 1500), y: Number(last.position?.y || 220) };
    }

    function addNode(type, position = null, preset = '', autoConnect = false) {
        if (!current) newWorkflow();
        if (current.status === 'active') {
            showMessage('Pause this workflow before editing its steps.', true);
            return;
        }
        const definition = catalog[type];
        if (!definition) return;
        const category = definition.category;
        const nodes = graph().nodes;

        if (category === 'trigger' && nodes.some((node) => catOf(node) === 'trigger')) {
            showMessage('A workflow can have one trigger. Remove the current trigger before adding another.', true);
            return;
        }
        if (category === 'orchestrator' && nodes.some((node) => catOf(node) === 'orchestrator')) {
            showMessage('A workflow has one orchestrator. Add more AI agents instead.', true);
            return;
        }

        const config = defaultsFor(type, preset);
        const node = {
            id: newNodeId(),
            type,
            label: type === 'ai_agent' ? `${SPECS[config.specialization].label} agent` : '',
            position: position || nextPosition(),
            config,
            ...(isProcessNode(type) ? { presentation: { process_icon_position: true } } : {}),
        };
        nodes.push(node);
        const insertionEdge = isProcessNode(node) && dragPreview?.edgeId
            ? graph().edges.find((edge) => edge.id === dragPreview.edgeId)
            : null;
        const inserted = insertionEdge ? splitEdgeWithNode(node, insertionEdge) : false;
        const connectionResult = autoConnect && !inserted ? autoConnectDroppedNode(node) : null;
        selectedEdgeId = null;
        selectedNodeId = node.id;
        markDirty();
        renderAll();
        if (inserted) {
            showMessage(`${catalog[type]?.label || 'Process step'} inserted into the connection. Configure it in Step settings.`);
        } else if (connectionResult?.created) {
            const source = nodeById(connectionResult.edge.source);
            const target = nodeById(connectionResult.edge.target);
            showMessage(`${nodeDisplayName(source)} → ${nodeDisplayName(target)} connected.`);
        } else if (connectionResult?.reason === 'no_compatible_source') {
            showMessage(`${nodeDisplayName(node)} added without an automatic connection. Connect it using the ports.`, true, true);
        } else if (connectionResult?.reason === 'no_orchestrator') {
            showMessage(`${nodeDisplayName(node)} added, but no Orchestrator is available.`, true, true);
        }
    }

    function newNodeId() {
        let id;
        do {
            id = `step_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
        } while (graph().nodes.some((node) => node.id === id));
        return id;
    }

    function newEdgeId() {
        let id;
        do {
            const randomId = globalThis.crypto?.randomUUID?.() || `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;
            id = `edge_${randomId}`;
        } while (graph().edges.some((edge) => edge.id === id));
        return id;
    }

    function splitEdgeWithNode(node, edge, moving = false) {
        const currentEdge = graph().edges.find((candidate) => candidate.id === edge?.id);
        if (!currentEdge || !eligibleInsertionEdge(currentEdge, node.type, moving ? node.id : null)) return false;

        if (moving) {
            const incoming = graph().edges.filter((candidate) => candidate.target === node.id);
            const outgoing = graph().edges.filter((candidate) => candidate.source === node.id);
            if (incoming.length !== 1 || outgoing.length !== 1) {
                showMessage('Move this step only after it has one incoming and one outgoing connection.', true);
                return false;
            }
            const previous = incoming[0];
            const next = outgoing[0];
            const previousNode = nodeById(previous.source);
            const nextNode = nodeById(next.target);
            const alreadyReconnected = graph().edges.some((candidate) => candidate.source === previous.source && candidate.target === next.target);
            if (!previousNode || !nextNode || (!canLink(previousNode, nextNode) && !alreadyReconnected)) {
                showMessage('This step cannot be moved because its current path cannot be reconnected.', true);
                return false;
            }
            graph().edges = graph().edges.filter((candidate) => candidate !== previous && candidate !== next);
            if (!alreadyReconnected) {
                graph().edges.push({
                    id: newEdgeId(), source: previousNode.id, target: nextNode.id,
                    edge_type: inferEdgeType(previousNode, nextNode),
                    config: clone(previous.config || {}), metadata: { created_by: 'move' },
                });
            }
        }

        const source = nodeById(currentEdge.source);
        const target = nodeById(currentEdge.target);
        if (!source || !target || !canLink(source, node) || !canLink(node, target)) return false;
        const midpoint = edgeMidpoint(source, target);
        node.position = {
            x: Math.round(midpoint.x - (isProcessNode(node) ? PROCESS_NODE_W / 2 : NODE_W / 2)),
            y: Math.round(midpoint.y - (isProcessNode(node) ? PROCESS_NODE_H / 2 : 56)),
        };
        if (isProcessNode(node)) node.presentation = { ...(node.presentation || {}), process_icon_position: true };

        graph().edges = graph().edges.filter((candidate) => candidate !== currentEdge);
        const upstreamType = inferEdgeType(source, node);
        const downstreamType = inferEdgeType(node, target);
        const branch = currentEdge.config?.branch;
        const upstreamConfig = branch ? { branch, ...(currentEdge.config?.on_error ? { on_error: currentEdge.config.on_error } : {}) } : {};
        const downstreamConfig = clone(currentEdge.config || {});
        delete downstreamConfig.branch;
        if (downstreamType === 'conditional_flow') {
            downstreamConfig.branch = 'true';
            downstreamConfig.on_error ||= 'stop_branch';
        }
        graph().edges.push(
            { id: newEdgeId(), source: source.id, target: node.id, edge_type: upstreamType, config: upstreamConfig, metadata: { created_by: 'insert' } },
            { id: newEdgeId(), source: node.id, target: target.id, edge_type: downstreamType, config: downstreamConfig, metadata: { created_by: 'insert' } },
        );
        return true;
    }

    function defaultWorkflowData() {
        const orchestratorId = newNodeId();
        const agentId = newNodeId();
        const gmailId = newNodeId();

        return {
            nodes: [
                {
                    id: orchestratorId,
                    type: 'orchestrator',
                    label: '',
                    position: { x: 100, y: 240 },
                    config: defaultsFor('orchestrator'),
                },
                {
                    id: agentId,
                    type: 'ai_agent',
                    label: 'Secretary agent',
                    position: { x: 430, y: 240 },
                    config: defaultsFor('ai_agent', 'secretary'),
                },
                {
                    id: gmailId,
                    type: 'app_action',
                    label: 'Gmail',
                    position: { x: 760, y: 240 },
                    config: defaultsFor('app_action', 'gmail'),
                },
            ],
            edges: [
                { id: newEdgeId(), source: orchestratorId, target: agentId },
                { id: newEdgeId(), source: agentId, target: gmailId },
            ],
            viewport: null,
        };
    }

    function newWorkflow() {
        clearTimeout(autosaveTimer);
        autosaveTimer = null;
        workflowEpoch += 1;
        workflowDirty = false;
        saveFailed = false;
        workflowRevision = 0;
        savedRevision = 0;
        current = { id: null, name: 'Untitled workflow', description: '', status: 'draft', workflow_data: defaultWorkflowData() };
        selectedNodeId = null;
        webhookUrl = '';
        $('wf-runs').textContent = 'Save this workflow to see its run history.';
        transform = { x: 80, y: 70, scale: 0.85 };
        renderAll();
        requestAnimationFrame(fitCanvas);
    }

    async function loadWorkflow(id) {
        if (current?.id === id) return;
        if ((workflowDirty || saveInFlight) && !(await saveWorkflow(true))) return;
        clearTimeout(autosaveTimer);
        autosaveTimer = null;
        const epoch = ++workflowEpoch;
        setLoadingWorkflow(true);
        try {
            const data = await request(`${api.collection}/${id}/use`, { method: 'POST', body: '{}' });
            if (epoch !== workflowEpoch) return;
            current = data.workflow;
            current.workflow_data = current.workflow_data || { nodes: [], edges: [] };
            current.workflow_data.nodes = Array.isArray(current.workflow_data.nodes) ? current.workflow_data.nodes : [];
            current.workflow_data.edges = Array.isArray(current.workflow_data.edges) ? current.workflow_data.edges : [];
            workflowDirty = false;
            saveFailed = false;
            workflowRevision = 0;
            savedRevision = 0;
            const viewport = current.workflow_data.viewport;
            transform = viewport && Number.isFinite(Number(viewport.x)) && Number.isFinite(Number(viewport.y))
                && Number.isFinite(Number(viewport.zoom)) && Number(viewport.zoom) > 0
                ? { x: Number(viewport.x), y: Number(viewport.y), scale: Number(viewport.zoom) }
                : { x: 80, y: 70, scale: 0.85 };
            webhookUrl = '';
            selectedNodeId = null;
            renderAll();
            await loadExecutions();
            showMessage(`Loaded “${current.name}”.`);
        } catch (error) { showMessage(error.message, true); }
        finally { if (epoch === workflowEpoch) setLoadingWorkflow(false); }
    }

    async function loadWorkflowList() {
        const data = await request(api.list);
        workflows = data.workflows || [];
        renderWorkflowList();
        if (!current && workflows.length) await loadWorkflow(workflows[0].id);
    }

    async function saveWorkflow(silent = false) {
        if (!current) return false;
        if (current.status === 'active') {
            showMessage('Pause this workflow before saving changes.', true);
            return false;
        }
        clearTimeout(autosaveTimer);
        autosaveTimer = null;
        if (saveInFlight) {
            saveRequested = true;
            try {
                await saveInFlight;
            } catch {
                return false;
            }
            return !workflowDirty;
        }

        const epoch = workflowEpoch;
        const revision = workflowRevision;
        const workflowId = current.id;
        const payload = {
            name: current.name || 'Untitled workflow',
            description: current.description || null,
            workflow_data: workflowData(),
        };

        setSaveStatus('Saving...', 'saving');
        const requestPromise = request(workflowId ? `${api.collection}/${workflowId}` : api.collection, {
            method: workflowId ? 'PUT' : 'POST',
            body: JSON.stringify(payload),
        });
        saveInFlight = requestPromise;
        let succeeded = false;
        try {
            const data = await requestPromise;
            succeeded = true;
            if (epoch === workflowEpoch && current) {
                // Keep the live graph: edits may have happened while this snapshot was in flight.
                current.id = data.workflow.id;
                current.name = data.workflow.name;
                current.description = data.workflow.description || '';
                current.status = data.workflow.status;
                savedRevision = revision;
                if (workflowRevision === revision) {
                    workflowDirty = false;
                    saveFailed = false;
                    if (!silent) showMessage('Workflow saved.');
                } else {
                    workflowDirty = true;
                    saveRequested = true;
                }
            }
        } catch (error) {
            if (epoch === workflowEpoch) {
                workflowDirty = true;
                saveFailed = true;
                setSaveStatus('Save failed', 'error');
                showMessage(error.message, true);
            }
        } finally {
            saveInFlight = null;
            if (epoch === workflowEpoch) {
                refreshSaveStatus();
                if (succeeded) {
                    try { await loadWorkflowList(); } catch { /* The saved graph remains available if the list refresh fails. */ }
                }
            }
        }

        const shouldSaveAgain = succeeded && epoch === workflowEpoch && workflowDirty && (saveRequested || savedRevision !== workflowRevision);
        saveRequested = false;
        if (shouldSaveAgain) return saveWorkflow(silent);
        return succeeded;
    }

    /* ------------------------------------------------------------ inspector */

    function field(label, key, options = {}) {
        const node = nodeById(selectedNodeId);
        const source = options.group ? (node?.config?.[options.group] || {}) : (node?.config || {});
        const wrap = document.createElement('label');
        wrap.className = options.checkbox ? 'flex items-start gap-2.5' : 'block space-y-1.5';
        const title = document.createElement('span');
        title.className = options.checkbox ? 'text-[11px] leading-5 text-white/60' : 'block text-[10px] uppercase tracking-[.13em] text-white/35';
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
            input.type = options.checkbox ? 'checkbox' : (options.type || 'text');
            if (options.placeholder) input.placeholder = options.placeholder;
        }
        if (options.checkbox) {
            input.checked = Boolean(source[key] ?? options.default ?? false);
            input.style.width = 'auto';
            input.style.marginTop = '4px';
        } else {
            const value = source[key] ?? options.default ?? '';
            if (options.group === 'values' && ['query', 'headers'].includes(key) && value && typeof value === 'object' && !Array.isArray(value)) {
                input.value = Object.entries(value).map(([name, item]) => `${name}${key === 'query' ? '=' : ': '}${item}`).join('\n');
            } else if (options.group === 'values' && key === 'body' && value && typeof value === 'object') {
                input.value = JSON.stringify(value, null, 2);
            } else if (key === 'response_paths' && Array.isArray(value)) {
                input.value = value.join('\n');
            } else {
                input.value = escapeText(value);
            }
        }
        if (options.placeholder && input.tagName === 'TEXTAREA') input.placeholder = options.placeholder;
        if (options.min !== undefined) input.min = String(options.min);
        if (options.max !== undefined) input.max = String(options.max);
        if (!key.startsWith('__') && key !== 'fields_text') {
            const read = () => (options.checkbox ? input.checked : input.value);
            input.addEventListener('input', () => updateNodeConfig(key, read(), options.group));
            input.addEventListener('change', () => updateNodeConfig(key, read(), options.group));
        }
        if (options.checkbox) wrap.append(input, title);
        else wrap.append(title, input);
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
        const outputNodes = graph().nodes.filter((node) => ancestors.has(node.id));
        outputNodes.forEach((node) => {
            let outputs = catalog[node.type]?.outputs?.length
                ? catalog[node.type].outputs
                : (node.config?.fields || []);
            if (node.config?.app === 'custom_api') {
                const configured = node.config.values?.response_paths || node.config.values?.response_fields || [];
                const paths = Array.isArray(configured) ? configured : configured.split(/[,\r\n]+/).map((path) => path.trim()).filter(Boolean);
                outputs = ['status', 'response', ...paths.map((path) => `response.${path}`)];
            }
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

    function updateNodeConfig(key, value, group = null) {
        const node = nodeById(selectedNodeId);
        if (!node) return;
        node.config = node.config || {};

        if (group) {
            node.config[group] = node.config[group] || {};
            node.config[group][key] = value;
            if (KEY_APPS.includes(node.config.app) && ['headers', 'query', 'url', 'service', 'filter'].includes(key)
                && /authorization|x-api-key|api[-_]?key|apikey|bearer|secret|token|password/i.test(value)) {
                showMessage('Do not paste keys or tokens here. Choose a saved connection so the secret stays on the server.', true);
            }
            renderNodes();
            markDirty();
            if (key === 'connection_id') {
                delete node.config.values.helper_action;
                renderInspector();
            }
            return;
        }

        if (node.type === 'ai_agent' && key === 'specialization' && SPECS[value]) {
            const previous = SPECS[node.config.specialization] || SPECS.custom;
            const next = SPECS[value];
            if (!String(node.config.instruction || '').trim() || node.config.instruction === previous.prompt) node.config.instruction = next.prompt;
            if (!String(node.config.handles || '').trim() || node.config.handles === previous.routes) node.config.handles = next.routes;
            if (!node.label || node.label === `${previous.label} agent`) node.label = `${next.label} agent`;
        }
        if (node.type === 'app_action' && key === 'app' && APPS[value]) {
            const variant = variantOf(value);
            if (variant) node.config.variant = variant; else delete node.config.variant;
            node.config.action = Object.keys(actionsFor(value, variant))[0];
            node.config.values = initialValues(value, node.config.action, variant);
        }
        if (node.type === 'app_action' && key === 'variant') {
            node.config.action = Object.keys(actionsFor(node.config.app, value))[0];
            node.config.values = initialValues(node.config.app, node.config.action, value);
        }
        if (node.type === 'app_action' && key === 'action') node.config.values = initialValues(node.config.app, value, node.config.variant);

        node.config[key] = value;
        renderNodes();
        markDirty();
        renderStatus();
        if (['frequency', 'specialization', 'execution_mode', 'app', 'variant', 'action', 'operator'].includes(key)) renderInspector();
    }

    function linkHint() {
        return 'Steps connect in this order: trigger → orchestrator → AI agent → app.';
    }

    function canLink(source, target) {
        if (!source || !target || source.id === target.id) return false;
        const from = catOf(source);
        const to = catOf(target);
        if (to === 'trigger') return false;
        if (from === 'trigger') return true;
        if (from === 'orchestrator') return ['agent', 'action'].includes(to);
        const processNodes = ['app', 'integration', 'action', 'condition', 'transform', 'output'];
        if (from === 'agent') return processNodes.includes(to);
        return processNodes.includes(from) && processNodes.includes(to);
    }

    function inferEdgeType(source, target) {
        const from = catOf(source);
        const to = catOf(target);
        if (from === 'orchestrator' && to === 'agent') return 'routing';
        if (from === 'agent') return 'agent_capability';
        if (from === 'trigger') return 'trigger_flow';
        if (from === 'condition') return 'conditional_flow';
        if (['app', 'integration', 'action', 'transform'].includes(from) && ['app', 'integration', 'action', 'transform', 'output', 'condition'].includes(to)) return 'data_flow';
        return 'control_flow';
    }

    function isOrchestrator(node) {
        return catOf(node) === 'orchestrator';
    }

    function isAgentNode(node) {
        return catOf(node) === 'agent';
    }

    function isAppActionIntegration(node) {
        return ['app', 'action', 'integration'].includes(catOf(node));
    }

    function canAgentUseNode(agent, node) {
        if (!isAgentNode(agent) || !isAppActionIntegration(node)) return false;

        const required = node.capabilities_required || catalog[node.type]?.capabilities_required;
        const supported = agent.capabilities || agent.config?.capabilities || catalog[agent.type]?.capabilities;
        if (!Array.isArray(required) || required.length === 0 || !Array.isArray(supported)) return true;
        return required.every((capability) => supported.includes(capability));
    }

    function nodeCenter(node) {
        const card = $('wf-nodes').querySelector(`[data-node-id="${CSS.escape(node.id)}"]`);
        const height = card?.offsetHeight || 112;
        const position = visualNodePosition(node);
        return {
            x: position.x + nodeWidth(node) / 2,
            y: position.y + height / 2,
        };
    }

    function findNearestCompatibleAgent(node, nodes = graph().nodes) {
        const targetCenter = nodeCenter(node);
        return nodes.filter((candidate) => canAgentUseNode(candidate, node)).reduce((nearest, candidate) => {
            const center = nodeCenter(candidate);
            const distance = (center.x - targetCenter.x) ** 2 + (center.y - targetCenter.y) ** 2;
            return !nearest || distance < nearest.distance ? { node: candidate, distance } : nearest;
        }, null)?.node || null;
    }

    function getAutoConnectionForNode(node) {
        const nodes = graph().nodes;
        if (isAgentNode(node)) {
            const orchestrator = nodes.find(isOrchestrator);
            return orchestrator && canLink(orchestrator, node)
                ? { source: orchestrator, target: node }
                : null;
        }
        if (isAppActionIntegration(node)) {
            const agent = findNearestCompatibleAgent(node, nodes);
            return agent && canLink(agent, node) ? { source: agent, target: node } : null;
        }
        return null;
    }

    function createConnection(source, target, createdBy = 'manual') {
        const nodes = graph().nodes;
        if (!nodes.some((node) => node.id === source?.id) || !nodes.some((node) => node.id === target?.id)
            || !canLink(source, target)) {
            return { created: false, reason: 'invalid' };
        }
        if (graph().edges.some((edge) => edge.source === source.id && edge.target === target.id)) {
            return { created: false, reason: 'duplicate' };
        }

        const edgeType = inferEdgeType(source, target);
        const edge = {
            id: newEdgeId(),
            source: source.id,
            target: target.id,
            edge_type: edgeType,
            config: edgeType === 'data_flow' ? {
                trigger: 'row_created_or_updated',
                mode: 'upsert',
                duplicate_key: 'row_id',
                on_error: 'stop_branch',
                mapping: {},
            } : edgeType === 'conditional_flow' ? {
                branch: graph().edges.filter((candidate) => candidate.source === source.id && candidate.edge_type === 'conditional_flow').length === 0 ? 'true' : 'false',
                on_error: 'stop_branch',
            } : {},
            metadata: { created_by: createdBy },
        };
        graph().edges.push(edge);
        return { created: true, edge, replacedExisting: false };
    }

    function autoConnectDroppedNode(node) {
        const connection = getAutoConnectionForNode(node);
        if (!connection) {
            if (isAppActionIntegration(node)) {
                return { created: false, reason: 'no_compatible_source' };
            }
            return { created: false, reason: 'no_orchestrator' };
        }
        return createConnection(connection.source, connection.target, 'auto');
    }

    function nodeDisplayName(node) {
        if (!node) return 'Step';
        if (node.label) return node.label;
        if (catOf(node) === 'app') return appActionLabel(node);
        return catalog[node.type]?.label || node.type;
    }

    function agentAppsSection(agent) {
        const box = document.createElement('div');
        box.className = 'space-y-2 border-t border-white/[.07] pt-4';
        const heading = document.createElement('div');
        heading.className = 'text-[10px] uppercase tracking-[.13em] text-white/35';
        heading.textContent = 'Apps this agent can use';
        box.append(heading);

        const apps = childrenOf(agent.id).filter((child) => catOf(child) === 'app');
        if (!apps.length) {
            const empty = document.createElement('p');
            empty.className = 'text-[10px] leading-5 text-white/30';
            empty.textContent = 'No apps yet. Without one, this agent can only produce text.';
            box.append(empty);
        }
        apps.forEach((app) => {
            const row = document.createElement('div');
            row.className = 'flex gap-1.5';
            const saved = apiConnections.find((connection) => String(connection.id) === String(app.config.values?.connection_id || app.config.values?.connection));
            const open = actionButton(saved?.name || appDef(app).label, 'flex-1 border border-white/10 text-left text-white/80 hover:text-white');
            const description = document.createElement('span');
            description.className = 'mt-1 block text-[10px] leading-5 text-white/45';
            description.textContent = saved?.description || appDef(app).group;
            open.append(description);
            const capabilities = document.createElement('span');
            capabilities.className = 'mt-2 block text-[10px] leading-5 text-white/60';
            capabilities.textContent = (saved?.capabilities || Object.entries(actionsFor(app.config.app, app.config.variant)).filter(([key]) => (appCapabilities[app.config.app]?.actions || []).includes(key)).map(([, action]) => action.label)).join(' · ') || 'This app is not connected for execution yet.';
            open.append(capabilities);
            open.addEventListener('click', () => { selectedNodeId = app.id; renderAll(); });
            const remove = actionButton('×', 'border border-white/10 px-2.5 text-white/45 hover:text-white');
            remove.title = 'Remove app';
            remove.setAttribute('aria-label', `Remove ${appDef(app).label}`);
            remove.addEventListener('click', () => deleteNode(app.id, agent.id));
            row.append(open, remove);
            box.append(row);
        });

        const add = document.createElement('div');
        add.className = 'flex gap-1.5 pt-1';
        const select = document.createElement('select');
        select.className = 'min-w-0 flex-1';
        const selectGroups = {};
        Object.entries(APPS).forEach(([key, app]) => { (selectGroups[app.group] ||= []).push([key, app]); });
        Object.entries(selectGroups).forEach(([group, items]) => {
            const optgroup = document.createElement('optgroup');
            optgroup.label = group;
            items.forEach(([key, app]) => {
                const option = document.createElement('option');
                option.value = key;
                option.textContent = app.label;
                optgroup.append(option);
            });
            select.append(optgroup);
        });
        const button = actionButton('Add app', 'border border-emerald-300/20 bg-emerald-300/[.06] text-emerald-100/80 hover:bg-emerald-300/10');
        button.addEventListener('click', () => attachAgentApp(agent, select.value));
        add.append(select, button);
        box.append(add);
        if (apiConnections.length) {
            const savedSelect = document.createElement('select');
            savedSelect.setAttribute('aria-label', 'Connected company app');
            savedSelect.className = 'min-w-0 flex-1';
            apiConnections.forEach((connection) => savedSelect.add(new Option(connection.name, String(connection.id))));
            const attach = actionButton('+ Add connected app', 'border border-cyan-300/20 text-cyan-100/80');
            attach.addEventListener('click', () => {
                const connection = apiConnections.find((item) => String(item.id) === savedSelect.value);
                if (!connection) return;
                const isOutlook = new URL(connection.base_url).hostname === 'graph.microsoft.com';
                const app = attachAgentApp(agent, isOutlook ? 'outlook' : 'custom_api');
                if (!app) return;
                app.label = connection.name;
                app.config.values = { connection_id: connection.id, ...(isOutlook ? {} : { method: 'GET', url: connection.integration === 'mmc_tracker' ? '/api/jarvis/projects/delivery-progress' : connection.integration === 'metabase' ? '/api/search' : '/', query: connection.integration === 'mmc_tracker' ? { compact: 1, per_page: 20, page: 1 } : {}, response_paths: [] }) };
                if (isOutlook) app.config.action = 'draft';
                markDirty(); renderAll();
            });
            const savedRow = document.createElement('div');
            savedRow.className = 'flex gap-1.5 pt-2';
            savedRow.append(savedSelect, attach);
            box.append(savedRow);
        }
        return box;
    }

    function attachAgentApp(agent, appKey) {
        addNode('app_action', null, appKey);
        const app = nodeById(selectedNodeId);
        if (!app || app.type !== 'app_action' || app.id === agent.id) return null;
        graph().edges.push({ id: newEdgeId(), source: agent.id, target: app.id, edge_type: 'agent_capability', config: {} });
        selectedNodeId = agent.id;
        markDirty(); renderAll();
        return app;
    }

    function modelField(label, node) {
        const choices = [['', 'Use JARVIS Default'], ...aiModels.map((model) => [model, model])];
        if (node.config.model && !aiModels.includes(node.config.model)) choices.push([node.config.model, `${node.config.model} (unavailable; uses default)`]);
        return field(label, 'model', { select: choices, help: defaultAiModel ? `JARVIS default: ${defaultAiModel}. Uses the central JARVIS AI connection.` : 'Uses the central JARVIS AI connection.' });
    }

    function renderInspector() {
        const inspector = $('wf-inspector');
        inspector.replaceChildren();
        const node = nodeById(selectedNodeId);
        const selectedEdge = graph().edges.find((edge) => (edge.id || `${edge.source}>${edge.target}`) === selectedEdgeId);
        $('wf-inspector-title').textContent = selectedEdge ? 'Connection settings' : node ? (node.label || catalog[node.type]?.label || node.type) : 'Workflow settings';

        if (!current) {
            inspector.textContent = 'Create or choose a workflow to edit its steps.';
            return;
        }

        if (selectedEdge) {
            renderEdgeInspector(inspector, selectedEdge);
            return;
        }

        if (!node) {
            const nameField = field('Workflow name', '__name', { placeholder: 'Customer email summary' });
            const descField = field('Description', '__description', { multiline: true, placeholder: 'What this automation does' });
            inspector.append(nameField, descField);
            const nameInput = nameField.querySelector('input');
            const descInput = descField.querySelector('textarea');
            nameInput.value = current.name || '';
            descInput.value = current.description || '';
            nameInput.addEventListener('input', () => { current.name = nameInput.value; $('wf-current-name').textContent = current.description || current.name; renderWorkflowList(); markDirty(); });
            descInput.addEventListener('input', () => { current.description = descInput.value; $('wf-current-name').textContent = current.description || current.name; markDirty(); });
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
                    const nameOf = (item) => item?.label || (catOf(item) === 'app' ? appActionLabel(item) : catalog[item?.type]?.label) || 'Step';
                    label.textContent = `${nameOf(source)} → ${nameOf(target)}`;
                    const remove = actionButton('×', 'border border-white/10 px-2 text-white/45 hover:text-white');
                    remove.title = 'Remove connection';
                    remove.disabled = current.status === 'active';
                    remove.addEventListener('click', () => {
                        graph().edges.splice(index, 1);
                        markDirty();
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

        const labelField = field('Step name (optional)', '__label', { placeholder: catalog[node.type]?.label || node.type });
        inspector.append(labelField);
        const labelInput = labelField.querySelector('input');
        labelInput.value = node.label || '';
        labelInput.addEventListener('input', () => { node.label = labelInput.value; renderNodes(); $('wf-inspector-title').textContent = node.label || catalog[node.type]?.label || node.type; markDirty(); });

        switch (node.type) {
            case 'email_trigger':
                inspector.append(field('Only from (optional)', 'from', { placeholder: 'billing@example.com', help: 'Matches part of the sender address.' }));
                inspector.append(field('Subject contains (optional)', 'subject_contains', { placeholder: 'Invoice' }));
                inspector.append(helpText('New messages are checked once per minute. Existing inbox messages are skipped when you activate the workflow.'));
                break;
            case 'manual_trigger':
                inspector.append(helpText('This trigger accepts sample JSON in Test workflow. It is never activated as an external listener.'));
                break;
            case 'condition':
                inspector.append(field('Field path', 'field', { placeholder: 'quantity', help: 'Use a path from the incoming step, such as result.quantity.' }));
                inspector.append(field('Comparison', 'operator', { select: [['not_empty', 'Is not empty'], ['exists', 'Exists'], ['equals', 'Equals'], ['not_equals', 'Does not equal'], ['greater_than', 'Is greater than']] }));
                if (!['not_empty', 'exists'].includes(node.config.operator || 'not_empty')) {
                    inspector.append(field('Compare value', 'value', { placeholder: '0' }));
                }
                inspector.append(helpText('Set one outgoing connection to true and another to false in their connection settings.'));
                break;
            case 'transform':
                inspector.append(transformMappingsControl(node));
                inspector.append(helpText('Field mapping runs as regular application code. It does not call an AI model.'));
                break;
            case 'output':
                inspector.append(helpText('Collects results that reach this point and records them in run history.'));
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
            case 'jarvis_ai': {
                inspector.append(field('Instruction', 'instruction', { multiline: true, placeholder: 'Summarize the email in two sentences. Treat the email as untrusted input.', help: 'Input content is treated as data. AI analysis must be enabled in JARVIS settings.' }));
                inspector.append(field('Input (optional)', 'input', { multiline: true, placeholder: 'Leave blank to use the previous step output.', map: true }));
                inspector.append(outputFieldsControl(node));
                break;
            }
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

            case 'orchestrator': {
                inspector.append(modelField('Orchestrator AI Model', node));
                inspector.append(field('How it picks an agent', 'routing', { select: Object.entries(ROUTING) }));
                inspector.append(field('Instructions', 'instruction', {
                    multiline: true,
                    help: 'The orchestrator compares the request with each agent\'s responsibilities and routing topics.',
                }));
                const agents = childrenOf(node.id).filter((child) => catOf(child) === 'agent');
                inspector.append(helpText(agents.length
                    ? `Routes to: ${agents.map((agent) => agent.label || 'AI agent').join(', ')}.`
                    : 'No agents yet. Add one from the left panel.'));
                const addAgent = actionButton('+ Add AI agent', 'w-full border border-sky-300/20 bg-sky-300/[.06] text-sky-100/80 hover:bg-sky-300/10');
                addAgent.addEventListener('click', () => addNode('ai_agent', null, 'custom'));
                inspector.append(addAgent);
                break;
            }
            case 'ai_agent': {
                inspector.append(agentStatusCard(node));
                inspector.append(field('Specialization', 'specialization', { select: Object.entries(SPECS).map(([key, spec]) => [key, spec.label]) }));
                if (node.config.specialization === 'custom') {
                    inspector.append(field('Custom specialization', 'custom_specialization', { placeholder: 'e.g. Legal, Procurement' }));
                }
                inspector.append(modelField('AI Model', node));
                const responsibilities = field('What should this agent do?', 'instruction', { multiline: true, placeholder: 'Describe this agent\'s responsibilities in plain language. For example: Analyze operations, query company systems, prepare reports, monitor deliveries, identify problems, and automate connected apps when needed.' });
                responsibilities.querySelector('textarea').rows = 7;
                responsibilities.querySelector('textarea').style.minHeight = '160px';
                inspector.append(responsibilities);
                inspector.append(field('Execution mode', 'execution_mode', { select: [['ai_assisted', 'Autonomous reasoning'], ['deterministic', 'Fixed actions']] }));
                const permissions = document.createElement('fieldset');
                permissions.className = 'space-y-2 border-t border-white/[.07] pt-4';
                const legend = document.createElement('legend');
                legend.className = 'text-[10px] uppercase tracking-[.13em] text-white/40';
                legend.textContent = 'Agent permissions';
                permissions.append(legend);
                [['read', 'Read/search connected apps', true], ['analyze', 'Analyze and compare data', true], ['report', 'Create reports', true], ['draft', 'Create drafts', true], ['write', 'Create/update records', false], ['send', 'Send messages', false], ['delete', 'Delete records', false]].forEach(([key, label, enabled]) => permissions.append(field(label, key, { group: 'permissions', checkbox: true, default: enabled })));
                inspector.append(permissions);
                inspector.append(field('Ask me before it sends or changes anything', 'require_approval', { checkbox: true, default: true }));
                inspector.append(agentAppsSection(node));
                const advanced = document.createElement('details');
                advanced.className = 'space-y-3 border-t border-white/[.07] pt-4';
                const summary = document.createElement('summary');
                summary.className = 'cursor-pointer text-xs text-white/60 focus-visible:outline-2 focus-visible:outline-cyan-300';
                summary.textContent = 'Advanced';
                advanced.append(summary, field('Routing topics', 'handles', { multiline: true, help: 'Optional topics that help the orchestrator choose this agent.' }), field('Task type', 'task_type', { placeholder: 'workflow_execution' }), field('Task priority (1–100)', 'priority', { type: 'number', min: 1, max: 100, default: 50 }), outputFieldsControl(node));
                inspector.append(advanced);
                break;
            }
            case 'app_action': {
                const def = appDef(node);
                inspector.append(field('App', 'app', { select: Object.entries(APPS).map(([key, app]) => [key, app.label]) }));
                if (def.variants) {
                    inspector.append(field(def.variantLabel || 'Version', 'variant', {
                        select: Object.entries(def.variants).map(([key, item]) => [key, item.label]),
                    }));
                }
                const actionList = actionsFor(node.config.app, node.config.variant);
                inspector.append(field('What should it do?', 'action', { select: Object.entries(actionList).map(([key, action]) => [key, action.label]) }));
                if (KEY_APPS.includes(node.config.app) || node.config.app === 'outlook') inspector.append(connectionField());
                if (node.config.app === 'custom_api') apiHelperControls(node).forEach((control) => inspector.append(control));
                (actionList[node.config.action]?.fields || []).forEach(([key, label, placeholder, multiline, choices]) => {
                    inspector.append(field(label, key, choices
                        ? { group: 'values', select: choices }
                        : { group: 'values', placeholder, multiline: Boolean(multiline), map: true }));
                });
                if (['sap', 'mmc_tracker'].includes(node.config.app)) {
                    inspector.append(field('Test behavior', 'test_behavior', { select: [['success', 'Simulate success'], ['fail', 'Simulate failure']] }));
                    inspector.append(helpText('This setting only affects Test workflow. A live connector is not configured for this app yet.'));
                }
                if (node.config.app === 'sap') {
                    const note = def.variants[variantOf('sap', node.config.variant)].note;
                    inspector.append(helpText(`${note} Secrets are stored on the server, never in this workflow.`));
                }
                if (node.config.app === 'gmail') {
                    inspector.append(helpText('Reads unread messages through the selected JARVIS mailbox connection. This app node currently supports read only.'));
                }
                if (node.config.app === 'custom_api') {
                    const manage = document.createElement('a');
                    manage.href = '/jarvis/settings/connections';
                    manage.textContent = 'Manage saved connections in JARVIS Settings';
                    manage.className = 'block text-xs text-cyan-300';
                    inspector.append(manage);
                    inspector.append(helpText('Only public HTTPS addresses are allowed. Redirects and private network addresses are blocked, and calls time out. Secrets are never saved in the workflow. Pick a saved connection for authentication.'));
                    inspector.append(helpText('Use a relative path with a saved Base URL. Response paths accept commas or new lines. Empty returns the complete response; sensitive values are redacted.'));
                    const test = actionButton('Test Step', 'border border-cyan-300/20 text-cyan-200');
                    const result = document.createElement('pre');
                    result.className = 'whitespace-pre-wrap break-all text-xs text-white/70';
                    test.addEventListener('click', async () => {
                        test.disabled = true;
                        result.textContent = 'Calling the API…';
                        try {
                            let context = {};
                            const mapped = JSON.stringify(node.config).includes('{{');
                            if (mapped) {
                                const sample = window.prompt('Enter sample previous-step outputs as JSON keyed by step ID (for example {"operations_agent":{"project_id":5}}):', '{}');
                                if (sample === null) { result.textContent = 'Test cancelled.'; return; }
                                context = JSON.parse(sample);
                            }
                            const data = await request('/jarvis/workflows/test-step', { method: 'POST', body: JSON.stringify({ node, context }) }, true);
                            result.textContent = apiTestSummary(data);
                        } catch (error) {
                            result.textContent = error.message;
                        } finally { test.disabled = false; }
                    });
                    inspector.append(helpText('Test Step sends a real request using the configured method. POST, PUT, PATCH and DELETE may change remote data.'), test, result);
                }
                const owner = parentOf(node.id);
                inspector.append(helpText(owner
                    ? `Used by ${owner.label || 'an AI agent'}. The ${def.label} account must be connected in your JARVIS settings.`
                    : 'This app is not attached to an agent. Connect it from an agent\'s output port.'));
                break;
            }
        }

        const actions = document.createElement('div');
        actions.className = 'flex gap-2 border-t border-white/[.07] pt-4';
        if (catOf(node) !== 'orchestrator') {
            const duplicate = actionButton('Duplicate', 'border border-white/10 text-white/55 hover:text-white');
            duplicate.addEventListener('click', () => duplicateNode(node));
            actions.append(duplicate);
        }
        const remove = actionButton(catOf(node) === 'agent' ? 'Delete agent and its apps' : 'Delete step', 'border border-red-300/15 text-red-200/70 hover:text-red-100');
        remove.addEventListener('click', () => deleteNode(node.id));
        actions.append(remove);
        inspector.append(actions);

        if (current.status === 'active') {
            inspector.querySelectorAll('input, textarea, select, button').forEach((control) => { control.disabled = true; });
            inspector.prepend(helpText('Pause this workflow to change step settings.'));
        }
    }

    function transformMappingsControl(node) {
        node.config.mappings = node.config.mappings && typeof node.config.mappings === 'object' ? node.config.mappings : {};
        const section = document.createElement('div');
        section.className = 'space-y-2';
        const title = document.createElement('div');
        title.className = 'text-[10px] uppercase tracking-[.13em] text-white/35';
        title.textContent = 'Field mapping';
        const rows = document.createElement('div');
        rows.className = 'space-y-2';
        const renderRows = () => {
            rows.replaceChildren();
            Object.entries(node.config.mappings).forEach(([target, source]) => {
                const row = document.createElement('div');
                row.className = 'space-y-1 rounded-lg border border-white/[.06] p-2';
                const targetInput = document.createElement('input');
                const sourceInput = document.createElement('input');
                [targetInput, sourceInput].forEach((input) => input.className = 'w-full rounded-md border border-white/10 bg-white/[.03] px-2 py-1.5 text-[10px] text-white');
                targetInput.value = target;
                targetInput.setAttribute('aria-label', 'Output field');
                targetInput.placeholder = 'Destination field';
                sourceInput.value = source;
                sourceInput.setAttribute('aria-label', 'Source field path');
                sourceInput.placeholder = 'Source field path';
                sourceInput.addEventListener('input', () => { node.config.mappings[target] = sourceInput.value; markDirty(); });
                targetInput.addEventListener('change', () => {
                    const next = targetInput.value.trim();
                    if (!next || next === target) return;
                    delete node.config.mappings[target];
                    node.config.mappings[next] = sourceInput.value;
                    renderRows();
                    markDirty();
                });
                const remove = actionButton('Remove field', 'border border-white/10 px-2 py-1 text-white/45 hover:text-white');
                remove.addEventListener('click', () => { delete node.config.mappings[target]; renderRows(); markDirty(); });
                row.append(targetInput, sourceInput, remove);
                rows.append(row);
            });
        };
        renderRows();
        const add = actionButton('+ Add field', 'border border-white/10 px-2 py-1 text-white/60 hover:text-white');
        add.addEventListener('click', () => {
            const target = `field_${Object.keys(node.config.mappings).length + 1}`;
            node.config.mappings[target] = 'result';
            renderRows();
            markDirty();
        });
        section.append(title, rows, add);
        return section;
    }

    function renderEdgeInspector(inspector, edge) {
        const source = nodeById(edge.source);
        const target = nodeById(edge.target);
        if (!source || !target) return;
        edge.config = edge.config && typeof edge.config === 'object' ? edge.config : {};
        edge.config.mapping = edge.config.mapping && typeof edge.config.mapping === 'object' ? edge.config.mapping : {};
        const details = document.createElement('div');
        details.className = 'rounded-lg border border-white/[.07] bg-white/[.02] p-3 text-[11px] leading-5 text-white/65';
        details.textContent = `${nodeDisplayName(source)} → ${nodeDisplayName(target)} · ${edge.edge_type || inferEdgeType(source, target)}`;
        inspector.append(details);

        const edgeSelect = (label, key, options) => {
            const wrap = document.createElement('label');
            wrap.className = 'block space-y-1.5';
            const title = document.createElement('span');
            title.className = 'block text-[10px] uppercase tracking-[.13em] text-white/35';
            title.textContent = label;
            const select = document.createElement('select');
            options.forEach(([value, text]) => select.add(new Option(text, value)));
            select.value = edge.config[key] || options[0][0];
            select.addEventListener('change', () => { edge.config[key] = select.value; markDirty(); renderEdges(); });
            wrap.append(title, select);
            return wrap;
        };
        if ((edge.edge_type || inferEdgeType(source, target)) === 'conditional_flow') {
            inspector.append(edgeSelect('Condition route', 'branch', [['true', 'True'], ['false', 'False']]));
            inspector.append(helpText('This connection runs only when the condition selects this route.'));
        }
        inspector.append(edgeSelect('Trigger', 'trigger', [['row_created_or_updated', 'Row created or updated'], ['row_created', 'Row created'], ['row_updated', 'Row updated'], ['manual', 'Manual']]));
        inspector.append(edgeSelect('Mode', 'mode', [['upsert', 'Create or update (upsert)'], ['create', 'Create'], ['update', 'Update'], ['skip', 'Skip existing']]));
        inspector.append(edgeSelect('On error', 'on_error', [['stop_branch', 'Stop branch'], ['stop_workflow', 'Stop workflow'], ['continue', 'Continue'], ['retry', 'Retry']]));

        const duplicate = field('Duplicate key', '__edge_duplicate_key', { placeholder: 'source_record_id' });
        const duplicateInput = duplicate.querySelector('input');
        duplicateInput.value = edge.config.duplicate_key || '';
        duplicateInput.addEventListener('input', () => { edge.config.duplicate_key = duplicateInput.value; markDirty(); });
        inspector.append(duplicate);

        const mappings = document.createElement('div');
        mappings.className = 'space-y-2 border-t border-white/[.07] pt-3';
        const heading = document.createElement('div');
        heading.className = 'text-[10px] uppercase tracking-[.13em] text-white/35';
        heading.textContent = 'Field mapping';
        mappings.append(heading);
        const sources = edgeSourceFields(source);
        const targets = edgeTargetFields(target);
        const renderRows = () => {
            mappings.querySelectorAll('.wf-mapping-row').forEach((row) => row.remove());
            Object.entries(edge.config.mapping).forEach(([targetField, sourceField]) => {
                const row = document.createElement('div');
                row.className = 'wf-mapping-row grid grid-cols-[1fr_1fr_auto] gap-1';
                const from = document.createElement('select');
                const to = document.createElement('select');
                [from, to].forEach((select) => select.className = 'min-w-0 rounded-lg border border-white/10 bg-white/[.03] px-1 py-2 text-[10px] text-white');
                sources.forEach((name) => from.add(new Option(name, name)));
                targets.forEach((name) => to.add(new Option(name, name)));
                if (!sources.includes(sourceField)) from.add(new Option(sourceField, sourceField));
                if (!targets.includes(targetField)) to.add(new Option(targetField, targetField));
                from.value = sourceField;
                to.value = targetField;
                from.addEventListener('change', () => { edge.config.mapping[targetField] = from.value; markDirty(); });
                to.addEventListener('change', () => {
                    delete edge.config.mapping[targetField];
                    edge.config.mapping[to.value] = from.value;
                    renderRows(); markDirty();
                });
                const remove = actionButton('×', 'border border-white/10 px-2 text-white/45 hover:text-white');
                remove.addEventListener('click', () => { delete edge.config.mapping[targetField]; renderRows(); markDirty(); });
                row.append(from, to, remove);
                mappings.append(row);
            });
        };
        renderRows();
        const addMapping = actionButton('+ Add mapping', 'border border-white/10 px-2 py-1.5 text-[10px] text-white/60 hover:text-white');
        addMapping.addEventListener('click', () => {
            const targetField = targets.find((name) => !(name in edge.config.mapping)) || `field_${Object.keys(edge.config.mapping).length + 1}`;
            edge.config.mapping[targetField] = sources[0] || 'value';
            renderRows(); markDirty();
        });
        mappings.append(addMapping);
        inspector.append(mappings, helpText('Mappings belong to this connection. A destination needs a supported action before the workflow can execute it.'));
        const remove = actionButton('Delete connection', 'w-full border border-red-300/15 text-red-200/70 hover:text-red-100');
        remove.addEventListener('click', () => {
            graph().edges = graph().edges.filter((candidate) => candidate !== edge);
            selectedEdgeId = null;
            markDirty(); renderAll();
        });
        inspector.append(remove);
        if (current.status === 'active') {
            inspector.querySelectorAll('input, select, button').forEach((control) => { control.disabled = true; });
            inspector.prepend(helpText('Pause this workflow to edit connections.'));
        }
    }

    function edgeSourceFields(node) {
        if (node.type === 'app_action' && node.config?.app === 'sheets') {
            return ['row_id', 'project_code', 'school_id', 'item_code', 'quantity', 'dr_number', 'status', 'created_at'];
        }
        return catalog[node.type]?.outputs?.length ? catalog[node.type].outputs : ['result', 'status'];
    }

    function edgeTargetFields(node) {
        const label = `${node.label || ''} ${node.config?.app || ''}`.toLowerCase();
        if (label.includes('tracker')) return ['project_id', 'school_id', 'item_id', 'qty', 'dr_number', 'status'];
        if (node.config?.app === 'sap') return ['ProjectCode', 'ItemCode', 'Quantity', 'U_SchoolCode', 'DocEntry'];
        return ['value', 'name', 'status', 'record_id'];
    }

    function agentStatusCard(node) {
        const summary = agentStatusSummaries.find((item) => item.workflow_id === current?.id && item.agent_node_id === node.id);
        const card = document.createElement('div');
        card.className = 'rounded-lg border border-white/[.07] bg-white/[.02] p-3';
        const heading = document.createElement('div');
        heading.className = 'flex items-center justify-between text-[10px] uppercase tracking-[.13em] text-white/40';
        const title = document.createElement('span');
        title.textContent = 'Agent status';
        const refresh = document.createElement('button');
        refresh.type = 'button';
        refresh.className = 'normal-case tracking-normal text-cyan-100/60 hover:text-cyan-100';
        refresh.textContent = 'Refresh';
        refresh.addEventListener('click', loadAgentStatuses);
        const state = document.createElement('span');
        state.className = 'text-cyan-100/75';
        state.textContent = summary?.status || 'idle';
        const statusWrap = document.createElement('span');
        statusWrap.className = 'flex items-center gap-2';
        statusWrap.append(refresh, state);
        heading.append(title, statusWrap);
        const counts = document.createElement('div');
        counts.className = 'mt-2 text-[10px] text-white/55';
        counts.textContent = `Today · ${summary?.today?.processed || 0} processed · ${summary?.today?.successful || 0} successful · ${summary?.today?.failed || 0} failed`;
        const currentTask = document.createElement('div');
        currentTask.className = 'mt-1 text-[10px] leading-4 text-white/35';
        currentTask.textContent = summary?.current_task ? `Current task: ${summary.current_task.type}` : summary?.last_error || 'No active task';
        card.append(heading, counts, currentTask);
        return card;
    }

    async function loadAgentStatuses() {
        try {
            const data = await request(api.agentStatus);
            agentStatusSummaries = data.agents || [];
            if (nodeById(selectedNodeId)?.type === 'ai_agent') renderInspector();
        } catch {
            agentStatusSummaries = [];
        }
    }

    function apiHelperControls(node) {
        const connection = apiConnections.find((item) => String(item.id) === String(node.config.values?.connection_id));
        const presets = API_PRESETS[connection?.integration];
        if (!presets) return [];
        const wrap = document.createElement('label');
        wrap.className = 'block space-y-2 text-xs text-white/70';
        wrap.textContent = 'Action (optional helper)';
        const select = document.createElement('select');
        select.className = 'w-full rounded-lg border border-white/10 bg-slate-900 p-2';
        select.add(new Option('Custom API request', ''));
        Object.entries(presets).forEach(([key, preset]) => select.add(new Option(preset.label, key)));
        select.value = node.config.values.helper_action || '';
        select.addEventListener('change', () => {
            node.config.values.helper_action = select.value;
            const values = apiPresetValues(connection.integration, select.value);
            if (values) Object.assign(node.config.values, values);
            markDirty(); renderNodes(); renderInspector();
        });
        wrap.append(select);
        const controls = [wrap];
        (presets[select.value]?.ids || []).forEach((key) => {
            const label = document.createElement('label');
            label.className = 'block text-xs text-white/70';
            label.textContent = key.replaceAll('_', ' ');
            const input = document.createElement('input');
            input.className = 'mt-1 w-full rounded-lg border border-white/10 bg-white/[.03] p-2';
            input.placeholder = `Enter ${key} or use the URL field to insert a previous-step value`;
            input.addEventListener('change', () => {
                if (!input.value.trim()) return;
                node.config.values.url = node.config.values.url.replace(`{${key}}`, input.value.trim());
                markDirty(); renderNodes(); renderInspector();
            });
            label.append(input); controls.push(label);
        });
        controls.push(helpText('Helpers prepare an ordinary HTTP request. You can edit every field below. Fill in the IDs before testing.'));
        if (connection.integration === 'mmc_tracker') controls.push(helpText('Use delivery-progress for project summaries. Detailed records default to per_page=20; keep AI calls at 50 or fewer. Add only filters supported by the endpoint (for example project_id or year).'));
        if (select.value === 'dashboard_data') controls.push(helpText('Dashboard data runs one question in its dashboard context. Supply dashboard, dashcard and card IDs and any required parameters.'));
        return controls;
    }

    function connectionField() {
        const isEmailMailbox = nodeById(selectedNodeId)?.config?.app === 'gmail';
        if (nodeById(selectedNodeId)?.config?.app === 'outlook') {
            return field('Saved Microsoft Graph connection', 'connection_id', { group: 'values', select: [['', 'Choose a saved connection'], ...apiConnections.filter((item) => { try { return new URL(item.base_url).hostname === 'graph.microsoft.com'; } catch { return false; } }).map((item) => [String(item.id), item.name])], help: 'Drafts are prepared for review without sending. Reading and sending use your saved Microsoft Graph connection.' });
        }
        if (nodeById(selectedNodeId)?.config?.app === 'custom_api') {
            const node = nodeById(selectedNodeId);
            node.config.values ||= {};
            if (node.config.values.connection && !node.config.values.connection_id) {
                node.config.values.connection_id = node.config.values.connection;
                delete node.config.values.connection;
            }
            if (node.config.values.response_fields && !node.config.values.response_paths) {
                node.config.values.response_paths = node.config.values.response_fields;
                delete node.config.values.response_fields;
            }
            return field('Saved connection', 'connection_id', {
                group: 'values', select: [['', '<select saved connection>'], ...apiConnections.map((item) => [String(item.id), item.name])],
                help: 'Only the saved connection ID is stored in this workflow. A Base URL allows relative paths.',
            });
        }
        if (connections.length) {
            return field(isEmailMailbox ? 'Saved email mailbox' : 'Saved connection (authentication)', 'connection', {
                group: 'values',
                select: [
                    ['', isEmailMailbox ? 'Use default mailbox' : 'No authentication'],
                    ...connections.map((item) => [String(item.id), isEmailMailbox ? `${item.name} · ${item.email}` : item.name]),
                ],
                help: isEmailMailbox
                    ? 'Uses a mailbox configured in JARVIS Settings. Credentials remain on the server.'
                    : 'Manage API keys and tokens in JARVIS settings. Only the connection name is stored here.',
            });
        }
        return field(isEmailMailbox ? 'Email mailbox ID' : 'Saved connection name', 'connection', {
            group: 'values',
            placeholder: isEmailMailbox ? 'Connect a mailbox in JARVIS Settings' : 'e.g. SAP production',
            help: isEmailMailbox
                ? 'Select an active mailbox after connecting it in JARVIS Settings. Credentials remain on the server.'
                : 'The API key or token must be stored on the server under this name. Never paste it here.',
        });
    }

    function executionStepLabels() {
        const nodes = graph().nodes;
        const edges = graph().edges;
        const pending = new Map(nodes.map((node) => [node.id, edges.filter((edge) => edge.target === node.id).length]));
        const levels = new Map(nodes.map((node) => [node.id, 1]));
        const queue = nodes.filter((node) => pending.get(node.id) === 0).map((node) => node.id);
        while (queue.length) {
            const source = queue.shift();
            edges.filter((edge) => edge.source === source).forEach((edge) => {
                levels.set(edge.target, Math.max(levels.get(edge.target) || 1, (levels.get(source) || 1) + 1));
                pending.set(edge.target, pending.get(edge.target) - 1);
                if (pending.get(edge.target) === 0) queue.push(edge.target);
            });
        }
        const groups = new Map();
        nodes.forEach((node) => {
            const level = levels.get(node.id) || 1;
            groups.set(level, [...(groups.get(level) || []), node]);
        });
        const labels = {};
        groups.forEach((items, level) => items.forEach((node, index) => {
            labels[node.id] = items.length > 1 ? `${level}${String.fromCharCode(65 + index)}` : String(level);
        }));
        return labels;
    }

    function outputFieldsControl(node) {
        const wrap = field('Output fields', 'fields_text', { placeholder: 'summary, urgency', help: 'Comma-separated names the next step can map.' });
        const input = wrap.querySelector('input');
        input.value = (node.config.fields || []).join(', ');
        input.addEventListener('input', () => {
            node.config.fields = input.value.split(',').map((part) => part.trim()).filter(Boolean).slice(0, 12);
            renderNodes();
            markDirty();
        });
        return wrap;
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
        if (catOf(node) === 'orchestrator') {
            showMessage('A workflow has one orchestrator.', true);
            return;
        }
        const copy = clone(node);
        copy.id = newNodeId();
        copy.label = node.label ? `${node.label} copy` : '';
        copy.position = { x: Math.min(Number(node.position.x) + 70, 1500), y: Number(node.position.y) + 130 };
        graph().nodes.push(copy);
        selectedNodeId = copy.id;
        markDirty();
        renderAll();
    }

    function deleteNode(id, reselect = null) {
        const node = nodeById(id);
        if (!node) return;
        if (catOf(node) === 'orchestrator') {
            showMessage('Every workflow must keep its Orchestrator.', true);
            return;
        }
        let reconnect = null;
        if (isProcessNode(node)) {
            const incoming = graph().edges.filter((edge) => edge.target === id);
            const outgoing = graph().edges.filter((edge) => edge.source === id);
            if (incoming.length === 1 && outgoing.length === 1) {
                const source = nodeById(incoming[0].source);
                const target = nodeById(outgoing[0].target);
                if (source && target && canLink(source, target)
                    && !graph().edges.some((edge) => edge.source === source.id && edge.target === target.id)) {
                    if (window.confirm(`Reconnect ${nodeDisplayName(source)} to ${nodeDisplayName(target)} after removing this step?`)) {
                        const reconnectEdge = incoming[0].config?.branch ? incoming[0] : outgoing[0];
                        const config = clone(reconnectEdge.config || {});
                        if (catOf(source) !== 'condition') delete config.branch;
                        reconnect = {
                            id: newEdgeId(), source: source.id, target: target.id,
                            edge_type: inferEdgeType(source, target), config, metadata: { created_by: 'remove_process' },
                        };
                    }
                }
            }
        }
        const doomed = new Set([id]);
        if (catOf(node) === 'agent') childrenOf(id).filter((child) => catOf(child) === 'app').forEach((child) => doomed.add(child.id));
        graph().nodes = graph().nodes.filter((item) => !doomed.has(item.id));
        graph().edges = graph().edges.filter((edge) => !doomed.has(edge.source) && !doomed.has(edge.target));
        if (reconnect) graph().edges.push(reconnect);
        selectedNodeId = reselect;
        pendingSource = null;
        markDirty();
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

    /* ------------------------------------------------------------ runs and lifecycle */

    async function loadExecutions(render = true) {
        if (!current?.id || executionPollInFlight) return;
        const generation = executionPollGeneration;
        executionPollInFlight = true;
        try {
            const data = await request(`${api.collection}/${current.id}/executions`);
            if (generation !== executionPollGeneration) return;
            const executions = data.executions || [];
            if (render) renderExecutions(executions);
            const latest = executions[0];
            if (latest?.status === 'running' && Array.isArray(latest.steps) && latest.steps.length) {
                applyExecutionState(latest.steps.map((step) => ({
                    node_id: step.node_id,
                    status: step.status,
                    output: step.output_data || {},
                })));
            } else if (latest?.status !== 'running') {
                applyExecutionState(latest?.output_data?.nodes || []);
                if (!render && latest) stopExecutionPolling();
            }
        } catch (error) { $('wf-runs').textContent = error.message; }
        finally { executionPollInFlight = false; }
    }

    function stopExecutionPolling() {
        clearInterval(executionPollTimer);
        executionPollTimer = null;
        executionPollGeneration += 1;
    }

    function renderExecutions(executions) {
        const container = $('wf-runs');
        container.replaceChildren();
        if (!executions.length) {
            container.textContent = 'No runs yet. Use Test workflow to preview the steps.';
            return;
        }
        executions.forEach((run) => {
            const details = document.createElement('details');
            details.className = 'rounded-lg border border-white/[.07] bg-white/[.02]';
            const summary = document.createElement('summary');
            summary.className = 'flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5';
            const left = document.createElement('span');
            left.className = 'min-w-0 truncate text-[11px] text-white/70';
            const stepResults = run.steps || run.results || run.output_data?.nodes || [];
            const processed = stepResults.filter((step) => step.status !== 'skipped').length;
            const succeeded = stepResults.filter((step) => ['success', 'simulated'].includes(step.status)).length;
            const failed = stepResults.filter((step) => step.status === 'failed').length;
            const owner = graph().nodes.find((node) => node.type === 'ai_agent')?.label || 'Workflow';
            const elapsed = run.started_at && run.finished_at ? Math.max(0, new Date(run.finished_at) - new Date(run.started_at)) : 0;
            left.textContent = `${owner} · ${run.trigger_type} · ${new Date(run.started_at).toLocaleString()} · ${processed} steps, ${succeeded} succeeded, ${failed} failed${elapsed ? ` · ${elapsed} ms` : ''}`;
            const status = document.createElement('span');
            status.className = `text-[9px] uppercase tracking-wider ${run.status === 'success' ? 'text-emerald-300' : run.status === 'success_with_errors' ? 'text-amber-200' : run.status === 'failed' ? 'text-red-300' : 'text-white/40'}`;
            status.textContent = run.status === 'success_with_errors' ? 'completed with errors' : run.status;
            summary.append(left, status);
            const content = document.createElement('pre');
            content.className = 'max-h-44 overflow-auto border-t border-white/[.06] p-3 text-[9px] leading-4 text-white/40';
            content.textContent = JSON.stringify({ error: run.error_message, nodes: stepResults }, null, 2);
            details.append(summary, content);
            const trace = run.output_data?.final?.trace || stepResults.map((step) => step.output_data?.trace || step.output?.trace).find(Boolean);
            if (trace) details.append(agentTraceCard(trace));
            if (run.output_data?.final?.result?.pending_action && !run.output_data?.approval && !run.input_data?.dry_run) {
                const proposal = run.output_data.final.result.pending_action;
                const preview = document.createElement('pre');
                preview.className = 'max-h-64 overflow-auto whitespace-pre-wrap break-words border-t border-amber-300/20 p-3 text-xs text-amber-100/80';
                preview.textContent = `Approval required: ${proposal.tool_id} · ${proposal.action}\n${JSON.stringify(proposal.arguments, null, 2)}`;
                details.append(preview);
                const approve = actionButton('Approve and run this action', 'm-3 border border-amber-300/30 text-amber-100');
                approve.addEventListener('click', async () => {
                    approve.disabled = true;
                    try {
                        await request(`/jarvis/workflows/${current.id}/executions/${run.id}/approve`, { method: 'POST', body: '{}' }, true);
                        await loadExecutions();
                    } catch (error) { showMessage(error.message, true); }
                });
                details.append(approve);
            }
            if (run.output_data?.approval) details.append(helpText(`Reviewed action: ${run.output_data.approval.status}. ${JSON.stringify(run.output_data.approval.result || {})}`));
            container.append(details);
        });
    }

    function agentTraceCard(trace) {
        const card = document.createElement('div');
        card.className = 'space-y-2 border-t border-white/[.07] p-3 text-xs text-white/65';
        const heading = document.createElement('p');
        heading.className = 'font-medium text-cyan-100';
        heading.textContent = `${trace.selected_agent} · ${trace.model}`;
        card.append(heading);
        if (trace.model_fallback) card.append(helpText(trace.model_fallback));
        (trace.available_tools || []).forEach((tool) => {
            const line = document.createElement('p');
            line.textContent = `${tool.name} — ${tool.status.replaceAll('_', ' ')}`;
            card.append(line);
        });
        (trace.decisions || []).forEach((decision) => {
            const line = document.createElement('p');
            line.className = 'text-white/45';
            line.textContent = `${decision.tool_id} · ${decision.action}: ${decision.reason || ''} (${decision.status.replaceAll('_', ' ')})`;
            card.append(line);
        });
        return card;
    }

    function applyExecutionState(results) {
        activeRunEdges = new Set();
        const nodeResults = Array.isArray(results) ? results : [];
        runNodeStates = nodeResults.length
            ? Object.fromEntries(graph().nodes.map((node) => [node.id, 'waiting']))
            : {};
        const outputByNode = Object.fromEntries(nodeResults.map((result) => [result.node_id, result.output || {}]));

        nodeResults.forEach((result) => {
            if (!runNodeStates[result.node_id]) return;
            runNodeStates[result.node_id] = result.status === 'simulated' ? 'success' : result.status;
            const selectedAgentId = result.output?.selected_agent_id;
            const selectedAppId = result.output?.selected_app_id;
            if (selectedAgentId) activeRunEdges.add(`${result.node_id}>${selectedAgentId}`);
            if (selectedAppId) activeRunEdges.add(`${result.node_id}>${selectedAppId}`);
        });
        graph().edges.forEach((edge) => {
            const sourceStatus = runNodeStates[edge.source];
            const targetStatus = runNodeStates[edge.target];
            const branch = edge.config?.branch;
            const branchMismatch = ['true', 'yes', 'false', 'no'].includes(branch)
                && typeof outputByNode[edge.source]?.matched === 'boolean'
                && (['true', 'yes'].includes(branch) !== outputByNode[edge.source].matched);
            if (sourceStatus && targetStatus && sourceStatus !== 'skipped' && targetStatus !== 'waiting' && targetStatus !== 'skipped' && !branchMismatch) {
                activeRunEdges.add(`${edge.source}>${edge.target}`);
            }
        });

        graph().nodes.forEach((node) => {
            const card = $('wf-nodes').querySelector(`[data-node-id="${CSS.escape(node.id)}"]`);
            if (!card) return;
            card.dataset.runStatus = runNodeStates[node.id] || '';
            card.querySelector('.wf-run-status')?.remove();
            if (isProcessNode(node)) {
                card.querySelector('.wf-process-status')?.remove();
                const marker = processStatusMarker(runNodeStates[node.id]);
                if (marker) {
                    const status = document.createElement('span');
                    status.className = `wf-process-status wf-process-status-${runNodeStates[node.id]}`;
                    status.textContent = marker;
                    status.setAttribute('aria-hidden', 'true');
                    card.querySelector('.wf-process-icon')?.append(status);
                }
            } else if (runNodeStates[node.id]) {
                const runState = document.createElement('div');
                runState.className = `wf-run-status mt-1 text-[8px] uppercase tracking-[.14em] ${runNodeStates[node.id] === 'failed' ? 'text-red-200/80' : runNodeStates[node.id] === 'success' ? 'text-emerald-200/80' : 'text-cyan-100/50'}`;
                runState.textContent = runNodeStates[node.id];
                card.querySelector('.wf-node-header .min-w-0.flex-1')?.append(runState);
            }
        });
        $('wf-edges').querySelectorAll('.wf-line').forEach((path) => {
            path.classList.toggle('wf-line-run-success', activeRunEdges.has(`${path.dataset.source}>${path.dataset.target}`));
        });
    }

    function openTestDialog() {
        if (!current) return;
        $('wf-test-input').value = graph().nodes.some((node) => node.type === 'manual_trigger')
            ? JSON.stringify({ project_code: 'PRJ-001', item_code: 'ITEM-001', quantity: 100, school_id: 'SCH-001', dr_number: 'DR-001' }, null, 2)
            : 'Check my unread emails.';
        $('wf-test-dialog').showModal();
        $('wf-test-input').focus();
    }

    async function testWorkflow(requestText) {
        if (!current || !requestText.trim()) return;
        if ((workflowDirty || saveInFlight || !current.id) && !await saveWorkflow(true)) return;
        $('wf-test').disabled = true;
        const orchestrator = graph().nodes.find((node) => node.type === 'orchestrator');
        if (orchestrator) applyExecutionState([{ node_id: orchestrator.id, status: 'running' }]);
        showMessage('Running a safe test with mock data. External apps will not be contacted.');
        stopExecutionPolling();
        executionPollTimer = setInterval(() => { void loadExecutions(false); }, 800);
        try {
            const data = await request(`${api.collection}/${current.id}/test`, { method: 'POST', body: JSON.stringify({ request: requestText.trim() }) });
            stopExecutionPolling();
            applyExecutionState(data.execution.results || []);
            const history = await request(`${api.collection}/${current.id}/executions`);
            renderExecutions([data.execution, ...(history.executions || []).filter((run) => run.id !== data.execution.id)]);
            await loadAgentStatuses();
            showMessage(data.message || 'Test finished.');
        } catch (error) {
            stopExecutionPolling();
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
        if ((workflowDirty || saveInFlight) && !(await saveWorkflow(true))) return;
        $('wf-ai-propose').disabled = true;
        showMessage('JARVIS is drafting a workflow…');
        try {
            const data = await request(api.propose, { method: 'POST', body: JSON.stringify({ prompt }) });
            clearTimeout(autosaveTimer);
            autosaveTimer = null;
            workflowEpoch += 1;
            workflowDirty = false;
            saveFailed = false;
            workflowRevision = 0;
            savedRevision = 0;
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
            requestAnimationFrame(() => $('wf-fit').click());
            showMessage('Draft created. Review the steps and settings, then save or test it. JARVIS has not activated it.');
        } catch (error) { showMessage(error.message, true); }
        finally { $('wf-ai-propose').disabled = false; }
    }

    /* ------------------------------------------------------------ canvas */

    function worldPoint(event) {
        const rect = $('wf-viewport').getBoundingClientRect();
        return { x: (event.clientX - rect.left - transform.x) / transform.scale, y: (event.clientY - rect.top - transform.y) / transform.scale };
    }

    function updateDropPreview(type, position) {
        if (isProcessNode(type)) {
            const insertionEdge = nearestInsertionEdge(position, type, activeMoveNodeId);
            if (insertionEdge) {
                dragPreview = { edgeId: insertionEdge.id, type, position };
                if (previewFrame !== null) return;
                previewFrame = requestAnimationFrame(() => {
                    previewFrame = null;
                    renderEdges();
                });
                return;
            }
        }
        const candidate = { id: '__workflow_drop_preview__', type, position };
        const connection = getAutoConnectionForNode(candidate);
        dragPreview = connection
            ? { sourceId: connection.source.id, highlightNodeId: connection.source.id, position, type }
            : null;
        if (previewFrame !== null) return;
        previewFrame = requestAnimationFrame(() => {
            previewFrame = null;
            renderEdges();
        });
    }

    function clearDropPreview() {
        dragPreview = null;
        if (previewFrame !== null) {
            cancelAnimationFrame(previewFrame);
            previewFrame = null;
        }
        renderEdges();
    }

    function finishPaletteDrag() {
        activePaletteDrag = null;
        clearDropPreview();
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
            gesture.moved = true;
            setTransform();
        } else {
            const node = nodeById(gesture.nodeId);
            if (!node) return;
            node.position.x = Math.round(gesture.startX + (event.clientX - gesture.x) / transform.scale);
            node.position.y = Math.round(gesture.startY + (event.clientY - gesture.y) / transform.scale);
            if (isProcessNode(node)) node.presentation = { ...(node.presentation || {}), process_icon_position: true };
            gesture.moved = true;
            const card = $('wf-nodes').querySelector(`[data-node-id="${CSS.escape(gesture.nodeId)}"]`);
            if (card) { card.style.left = `${node.position.x}px`; card.style.top = `${node.position.y}px`; }
            if (isProcessNode(node)) updateDropPreview(node.type, worldPoint(event));
            else dragPreview = null;
            renderEdges();
        }
    });
    const finishGesture = () => {
        const movedNode = gesture?.kind === 'node' ? nodeById(gesture.nodeId) : null;
        const insertionEdge = movedNode && gesture?.moved && isProcessNode(movedNode) && dragPreview?.edgeId
            ? graph().edges.find((edge) => edge.id === dragPreview.edgeId)
            : null;
        if (movedNode && insertionEdge && splitEdgeWithNode(movedNode, insertionEdge, true)) {
            selectedNodeId = movedNode.id;
            selectedEdgeId = null;
            renderAll();
            markDirty();
            showMessage(`${catalog[movedNode.type]?.label || 'Process step'} moved to the selected connection.`);
        } else {
            if (gesture?.kind === 'node') renderEdges();
            if (gesture?.moved) markDirty();
        }
        gesture = null;
        activeMoveNodeId = null;
        clearDropPreview();
    };
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
        markDirty();
    }, { passive: false });
    $('wf-viewport').addEventListener('dragover', (event) => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
        if (activePaletteDrag) updateDropPreview(activePaletteDrag.type, worldPoint(event));
    });
    $('wf-viewport').addEventListener('dragleave', (event) => {
        const rect = $('wf-viewport').getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) {
            clearDropPreview();
        }
    });
    $('wf-viewport').addEventListener('drop', (event) => {
        event.preventDefault();
        const payload = event.dataTransfer.getData('application/x-jc-workflow-node');
        if (payload) {
            const [type, preset = ''] = payload.split(':');
            updateDropPreview(type, worldPoint(event));
            addNode(type, worldPoint(event), preset, true);
        }
        clearDropPreview();
        activePaletteDrag = null;
    });
    $('wf-zoom-in').addEventListener('click', () => { transform.scale = Math.min(1.6, transform.scale * 1.12); setTransform(); markDirty(); });
    $('wf-zoom-out').addEventListener('click', () => { transform.scale = Math.max(0.45, transform.scale / 1.12); setTransform(); markDirty(); });
    function fitCanvas() {
        const nodes = graph().nodes;
        const viewport = $('wf-viewport').getBoundingClientRect();
        if (!nodes.length || !viewport.width || !viewport.height) {
            transform = { x: 80, y: 70, scale: 0.85 };
        } else {
            const bounds = nodes.map((node) => {
                const position = visualNodePosition(node);
                const process = isProcessNode(node);
                return {
                    left: position.x - (process ? 15 : 0),
                    top: position.y - (process ? 8 : 0),
                    right: position.x + nodeWidth(node) + (process ? 15 : 0),
                    bottom: position.y + (process ? 72 : cardHeight(node)),
                };
            });
            const minX = Math.min(...bounds.map((bound) => bound.left));
            const minY = Math.min(...bounds.map((bound) => bound.top));
            const maxX = Math.max(...bounds.map((bound) => bound.right));
            const maxY = Math.max(...bounds.map((bound) => bound.bottom));
            const scale = Math.min(1.35, Math.max(0.45, Math.min((viewport.width - 100) / (maxX - minX + 80), (viewport.height - 100) / (maxY - minY + 80))));
            transform = {
                scale,
                x: (viewport.width - (maxX - minX) * scale) / 2 - minX * scale,
                y: (viewport.height - (maxY - minY) * scale) / 2 - minY * scale,
            };
        }
        setTransform();
    }
    $('wf-fit').addEventListener('click', () => { fitCanvas(); markDirty(); });
    $('wf-tidy')?.addEventListener('click', () => {
        if (!current || current.status === 'active') return;
        tidyLayout();
        renderNodes();
        fitCanvas();
        markDirty();
    });
    $('wf-new').addEventListener('click', async () => {
        if ((workflowDirty || saveInFlight) && !(await saveWorkflow(true))) return;
        newWorkflow();
    });
    $('wf-workflow-settings').addEventListener('click', () => { selectedEdgeId = null; selectedNodeId = null; renderAll(); });
    $('wf-save').addEventListener('click', () => saveWorkflow());
    $('wf-test').addEventListener('click', openTestDialog);
    $('wf-load-test-fixture').addEventListener('click', loadTestFixture);
    $('wf-test-cancel').addEventListener('click', () => $('wf-test-dialog').close());
    $('wf-test-form').addEventListener('submit', (event) => {
        event.preventDefault();
        $('wf-test-dialog').close();
        void testWorkflow($('wf-test-input').value);
    });
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

    window.addEventListener('beforeunload', (event) => {
        if (!workflowDirty && !saveInFlight && !autosaveTimer) return;
        event.preventDefault();
        event.returnValue = '';
    });

    async function start() {
        try {
            const data = await request(api.catalog);
            // Server entries win; the three new node types fall back to the local definitions.
            catalog = { ...LOCAL_CATALOG, ...(data.nodes || {}) };
            renderPalette();
            request(api.connections).then((d) => { connections = d.connections || []; apiConnections = d.api_connections || []; aiModels = d.ai_models || []; defaultAiModel = d.default_ai_model || ''; appCapabilities = d.app_capabilities || {}; renderInspector(); }).catch(() => {});
            await loadWorkflowList();
            await loadAgentStatuses();
            if (!current && workflows.length) {
                setLoadingWorkflow(false, 'Could not restore the saved workflow. Refresh to try again.');
                $('wf-loading').classList.remove('hidden');
                return;
            }
            if (!current) newWorkflow();
            setLoadingWorkflow(false);
        } catch (error) {
            showMessage(error.message, true);
            renderPalette();
            setLoadingWorkflow(false, 'Could not load workflows. Refresh to try again.');
            $('wf-loading').classList.remove('hidden');
        }
    }

    start();
})();
