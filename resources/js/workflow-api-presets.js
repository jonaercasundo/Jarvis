export const API_PRESETS = {
    metabase: {
        dashboard: { label: 'Get dashboard', method: 'GET', path: '/api/dashboard/{dashboard_id}', ids: ['dashboard_id'] },
        dashboard_data: { label: 'Get dashboard data', method: 'POST', path: '/api/dashboard/{dashboard_id}/dashcard/{dashcard_id}/card/{card_id}/query', ids: ['dashboard_id', 'dashcard_id', 'card_id'], body: '{"parameters":[]}', response_paths: 'data.rows\ndata.cols' },
        card: { label: 'Get question/card', method: 'GET', path: '/api/card/{card_id}', ids: ['card_id'] },
        execute_card: { label: 'Execute question', method: 'POST', path: '/api/card/{card_id}/query', ids: ['card_id'], body: '{"parameters":[]}', response_paths: 'data.rows\ndata.cols' },
        dashboards: { label: 'List dashboards', method: 'GET', path: '/api/dashboard' },
        collections: { label: 'List collections', method: 'GET', path: '/api/collection' },
    },
    mmc_tracker: {
        progress: { label: 'Delivery progress', method: 'GET', path: '/api/jarvis/projects/delivery-progress', query: 'project_id=', response_paths: 'data.projects\nmeta.filters\nmeta.definitions' },
        deliveries: { label: 'Search deliveries', method: 'GET', path: '/api/jarvis/deliveries', query: 'project_id=\npage=1\nper_page=20' },
        project: { label: 'Project details', method: 'GET', path: '/api/jarvis/projects/{project_id}', ids: ['project_id'] },
        projects: { label: 'Projects', method: 'GET', path: '/api/jarvis/projects', query: 'page=1\nper_page=20' },
        lots: { label: 'Lots', method: 'GET', path: '/api/jarvis/lots', query: 'page=1\nper_page=20' },
        items: { label: 'Items', method: 'GET', path: '/api/jarvis/operation-masterlist', query: 'page=1\nper_page=20' },
        packages: { label: 'Packages', method: 'GET', path: '/api/jarvis/packages', query: 'page=1\nper_page=20' },
    },
};

export function apiPresetValues(integration, action, identifiers = {}) {
    const preset = API_PRESETS[integration]?.[action];
    if (!preset) return null;
    return {
        method: preset.method,
        url: preset.path.replace(/\{(\w+)\}/g, (match, key) => String(identifiers[key] || match)),
        query: preset.query || '', body: preset.body || '', response_paths: preset.response_paths || '',
    };
}

export function apiTestSummary(data) {
    const diagnostic = data.diagnostic || {};
    return [
        `Connection: ${diagnostic.connection || 'Direct HTTPS request'}`,
        `${diagnostic.method || ''} ${diagnostic.endpoint || diagnostic.url || ''}`,
        `HTTP status: ${data.status ?? 'Unavailable'}`,
        `Response time: ${diagnostic.elapsed_ms ?? 'Unavailable'} ms`,
        `Response size: ${diagnostic.response_bytes ?? 'Unavailable'} bytes`,
        ...(data.success === false ? [data.message || data.error, ...(data.suggestions || [])] : []),
        `Pagination: ${JSON.stringify(data.pagination ?? null)}`,
        JSON.stringify(data.response ?? null, null, 2),
    ].join('\n');
}
