import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/jarvis-workflows.js', import.meta.url), 'utf8');

function functionSource(name) {
    const start = source.indexOf(`    function ${name}(`);
    assert.ok(start >= 0);
    return source.slice(start, source.indexOf('\n    function ', start + 20));
}

class Element {
    constructor(tag) { this.tagName = tag.toUpperCase(); this.children = []; this.style = {}; this.handlers = {}; }
    append(...children) { this.children.push(...children); }
    addEventListener(name, callback) { this.handlers[name] = callback; }
    set innerHTML(_) { throw new Error('Agent settings must render plain text safely.'); }
}

test('Agent permission checkbox changes save a boolean in the permissions group', () => {
    const node = { type: 'ai_agent', config: {} };
    let saved = 0;
    const context = { document: { createElement: (tag) => new Element(tag) }, selectedNodeId: 'agent', nodeById: () => node, KEY_APPS: [], renderNodes() {}, markDirty: () => saved++, escapeText: String };
    vm.createContext(context);
    vm.runInContext(`${functionSource('field')}\n${functionSource('updateNodeConfig')}`, context);
    const permission = context.field('Send messages', 'send', { group: 'permissions', checkbox: true, default: false });
    const checkbox = permission.children[0];
    assert.equal(checkbox.checked, false);
    checkbox.checked = true;
    checkbox.handlers.input();
    assert.equal(node.config.permissions.send, true);
    assert.equal(typeof node.config.permissions.send, 'boolean');
    checkbox.checked = false;
    checkbox.handlers.change();
    assert.equal(node.config.permissions.send, false);
    assert.equal(saved, 2);
});

test('Add app attaches a capability to the selected Agent and preserves existing connections', () => {
    const graph = { nodes: [{ id: 'agent', type: 'ai_agent' }, { id: 'other', type: 'ai_agent' }], edges: [{ source: 'router', target: 'agent', edge_type: 'routing' }] };
    const context = { selectedNodeId: 'agent', graph: () => graph, newEdgeId: () => 'new-edge', nodeById: (id) => graph.nodes.find((node) => node.id === id), markDirty() {}, renderAll() {} };
    context.addNode = () => { graph.nodes.push({ id: 'app', type: 'app_action' }); context.selectedNodeId = 'app'; };
    vm.createContext(context);
    vm.runInContext(functionSource('attachAgentApp'), context);
    const added = context.attachAgentApp(graph.nodes[0], 'custom_api');
    assert.equal(added.id, 'app');
    assert.equal(graph.edges.length, 2);
    assert.equal(graph.edges[1].source, 'agent');
    assert.equal(graph.edges[1].target, 'app');
    assert.equal(graph.edges[1].edge_type, 'agent_capability');
    assert.equal(context.selectedNodeId, 'agent');
});

test('Execution trace renders tool states and decision explanations as plain text', () => {
    const context = { document: { createElement: (tag) => new Element(tag) }, helpText: (text) => Object.assign(new Element('p'), { textContent: text }) };
    vm.createContext(context);
    vm.runInContext(functionSource('agentTraceCard'), context);
    const card = context.agentTraceCard({ selected_agent: 'Operations Agent', model: 'central-model', available_tools: [{ name: 'Tracker', status: 'Not needed' }, { name: '<img src=x onerror=alert(1)>', status: 'authentication_failed' }], decisions: [{ tool_id: 'metabase', action: 'search', reason: 'Financial reports required', status: 'authentication_failed' }] });
    assert.equal(card.children[1].textContent, 'Tracker — Not needed');
    assert.equal(card.children[2].textContent, '<img src=x onerror=alert(1)> — authentication failed');
    assert.match(card.children[3].textContent, /Financial reports required/);
});
