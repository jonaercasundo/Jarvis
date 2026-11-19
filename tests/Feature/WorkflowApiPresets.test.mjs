import assert from 'node:assert/strict';
import test from 'node:test';
import { apiPresetValues, apiTestSummary } from '../../resources/js/workflow-api-presets.js';

test('Metabase dashboard data uses the documented dashboard card query endpoint', () => {
    const values = apiPresetValues('metabase', 'dashboard_data', { dashboard_id: 1, dashcard_id: 2, card_id: 3 });
    assert.equal(values.method, 'POST');
    assert.equal(values.url, '/api/dashboard/1/dashcard/2/card/3/query');
    assert.equal(values.response_paths, 'data.rows\ndata.cols');
    assert.deepEqual(JSON.parse(values.body), { parameters: [] });
});

test('helpers preserve workflow mapping syntax in URLs and leave manual requests alone', () => {
    assert.equal(apiPresetValues('metabase', 'execute_card', { card_id: '{{ops.result.card_id}}' }).url, '/api/card/{{ops.result.card_id}}/query');
    assert.equal(apiPresetValues('generic', 'request'), null);
    assert.equal(apiPresetValues('metabase', ''), null);
});

test('Tracker presets use existing summary and paginated detail endpoints', () => {
    assert.equal(apiPresetValues('mmc_tracker', 'progress').url, '/api/jarvis/projects/delivery-progress');
    assert.equal(apiPresetValues('mmc_tracker', 'deliveries').query, 'project_id=\npage=1\nper_page=20');
    assert.equal(apiPresetValues('mmc_tracker', 'items').url, '/api/jarvis/operation-masterlist');
});

test('Test Step displays safe diagnostics, pagination and response-size suggestions', () => {
    const text = apiTestSummary({ success: false, error: 'response_too_large', status: 200,
        diagnostic: { connection: 'Metabase', method: 'POST', endpoint: '/api/card/123/query', elapsed_ms: 12, response_bytes: 60000 },
        suggestions: ['Select response fields.'], pagination: { current_page: 1 } });
    for (const value of ['Metabase', 'POST /api/card/123/query', '200', '12 ms', '60000 bytes', 'Select response fields.', 'current_page']) assert.ok(text.includes(value));
});
