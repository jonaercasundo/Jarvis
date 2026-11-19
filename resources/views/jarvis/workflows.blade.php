<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#070b13">
    <title>JARVIS Automations</title>
    @vite(['resources/css/app.css', 'resources/js/jarvis-workflows.js'])
    <style>
        :root { color-scheme: dark; }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; background: #070b13; color: #e5eef8; font-family: Inter, ui-sans-serif, system-ui, sans-serif; -webkit-text-size-adjust: 100%; }
        body { min-height: 100%; }
        button { font-family: inherit; cursor: pointer; }
        button:disabled { cursor: not-allowed; }
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible, [tabindex]:focus-visible { outline: 2px solid rgba(103,232,249,.8); outline-offset: 2px; }

        .wf-grid { background-color:#080d17; background-image:radial-gradient(rgba(108,211,237,.16) .8px,transparent .8px); background-size:22px 22px; }
        .wf-node { position:absolute; width:236px; min-height:112px; user-select:none; -webkit-user-select:none; touch-action:none; }
        .wf-node.wf-process-node { width:56px; height:56px; min-height:56px; overflow:visible; border:0; border-radius:0; background:transparent; box-shadow:none; backdrop-filter:none; }
        .wf-process-layout { position:relative; width:56px; height:56px; cursor:grab; touch-action:none; }
        .wf-process-layout:active { cursor:grabbing; }
        .wf-process-icon { position:absolute; left:3px; top:3px; display:flex; width:50px; height:50px; align-items:center; justify-content:center; border:1px solid color-mix(in srgb, var(--wf-process-accent) 45%, transparent); border-radius:15px; background:color-mix(in srgb, var(--wf-process-accent) 11%, #0c1422); color:var(--wf-process-accent); box-shadow:0 5px 18px rgba(0,0,0,.25),0 0 10px color-mix(in srgb, var(--wf-process-accent) 8%, transparent); font-size:22px; line-height:1; transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease; }
        button.wf-process-icon { padding:0; font-family:inherit; cursor:grab; }
        .wf-process-node:hover .wf-process-icon { transform:translateY(-1px); border-color:color-mix(in srgb, var(--wf-process-accent) 75%, transparent); box-shadow:0 7px 20px rgba(0,0,0,.3),0 0 16px color-mix(in srgb, var(--wf-process-accent) 16%, transparent); }
        .wf-node.wf-process-node[data-selected="true"] { outline:0; box-shadow:none; }
        .wf-node.wf-process-node[data-selected="true"] .wf-process-icon { border-color:var(--wf-process-accent); box-shadow:0 0 0 3px color-mix(in srgb, var(--wf-process-accent) 17%, transparent),0 0 21px color-mix(in srgb, var(--wf-process-accent) 30%, transparent); }
        .wf-process-step-number { position:absolute; z-index:2; left:50%; top:-5px; transform:translateX(-50%); min-width:19px; border:1px solid rgba(255,255,255,.12); border-radius:5px; background:#0a111c; padding:1px 4px; color:rgba(226,232,240,.72); font:8px/1.3 ui-monospace,monospace; text-align:center; }
        .wf-process-label { position:absolute; left:50%; top:57px; max-width:86px; transform:translateX(-50%); overflow:hidden; color:rgba(226,232,240,.67); font-size:8px; line-height:12px; text-align:center; text-overflow:ellipsis; white-space:nowrap; pointer-events:none; }
        #wf-world.wf-zoom-low .wf-process-label { visibility:hidden; opacity:0; }
        .wf-process-warning, .wf-process-status { position:absolute; z-index:3; display:flex; width:16px; height:16px; align-items:center; justify-content:center; border:1px solid #0c1422; border-radius:50%; background:#342815; color:#fcd34d; font:10px/1 ui-sans-serif,system-ui,sans-serif; }
        .wf-process-warning { top:-2px; right:-3px; }
        .wf-process-status { right:-3px; bottom:-2px; background:#10221d; color:#a7f3d0; }
        .wf-process-status-failed { background:#321b1b; color:#fecaca; }
        .wf-process-status-running, .wf-process-status-retrying { background:#102330; color:#a5f3fc; animation:wf-run-pulse 1.2s ease-in-out infinite alternate; }
        .wf-node.wf-process-node[data-run-status] { border-color:transparent; box-shadow:none; animation:none; }
        .wf-process-node[data-run-status="success"] .wf-process-icon { border-color:rgba(110,231,183,.75); }
        .wf-process-node[data-run-status="failed"] .wf-process-icon { border-color:rgba(252,165,165,.85); box-shadow:0 0 0 2px rgba(248,113,113,.12),0 0 18px rgba(248,113,113,.2); }
        .wf-process-node[data-run-status="running"] .wf-process-icon { border-color:rgba(103,232,249,.8); }
        .wf-process-node[data-run-status="waiting"] .wf-process-icon { opacity:.7; }
        .wf-port { position:absolute; top:50%; width:12px; height:12px; padding:0; border:2px solid #6ee7f9; border-radius:999px; background:#08121f; transform:translateY(-50%); cursor:crosshair; }
        /* Larger invisible hit area so ports are usable on touch screens */
        .wf-port::after { content:""; position:absolute; inset:-9px; border-radius:999px; }
        .wf-port:hover:not(:disabled) { background:#6ee7f9; }
        .wf-port-in { left:-7px; }
        .wf-port-out { right:-7px; }
        .wf-process-node .wf-port { top:50%; width:8px; height:8px; border-width:1px; }
        .wf-process-node .wf-port::after { inset:-7px; }
        .wf-process-node .wf-port-in { left:-4px; }
        .wf-process-node .wf-port-out { right:-4px; }
        /* Process and app nodes can emit and receive directed connections. */
        .wf-node[data-category="trigger"] .wf-port-in { display:none; }
        .wf-node[data-selected="true"] { outline:1px solid rgba(103,232,249,.8); box-shadow:0 0 0 4px rgba(34,211,238,.08),0 18px 60px rgba(0,0,0,.38); }
        .wf-node.wf-drop-target { outline:1px solid rgba(167,243,208,.8); box-shadow:0 0 0 4px rgba(110,231,183,.1),0 18px 60px rgba(0,0,0,.38); }
        .wf-node[data-run-status="running"] { border-color:rgba(103,232,249,.85); box-shadow:0 0 0 3px rgba(34,211,238,.12),0 0 24px rgba(34,211,238,.18); animation:wf-run-pulse 1.2s ease-in-out infinite alternate; }
        .wf-node[data-run-status="success"] { border-color:rgba(110,231,183,.55); box-shadow:0 0 0 2px rgba(110,231,183,.08),0 0 18px rgba(110,231,183,.12); }
        .wf-node[data-run-status="failed"] { border-color:rgba(252,165,165,.8); box-shadow:0 0 0 3px rgba(248,113,113,.12),0 0 22px rgba(248,113,113,.15); }
        .wf-node[data-run-status="waiting"] { opacity:.62; }
        .wf-process-node[data-run-status="skipped"] .wf-process-icon { opacity:.42; }
        .wf-line { fill:none; stroke:rgba(103,232,249,.55); stroke-width:2; stroke-linecap:round; }
        .wf-line-selectable { pointer-events:stroke; cursor:pointer; }
        .wf-line-active { stroke:#9af3ff; filter:drop-shadow(0 0 5px rgba(103,232,249,.65)); }
        .wf-line-insert-target { stroke:#a7f3d0; stroke-width:4; filter:drop-shadow(0 0 7px rgba(110,231,183,.72)); }
        .wf-line-run-success { stroke:#6ee7b7; stroke-width:3; filter:drop-shadow(0 0 6px rgba(110,231,183,.7)); }
        .wf-line-preview { stroke:rgba(110,231,183,.8); stroke-dasharray:6 7; opacity:.85; }
        .wf-edge-label { fill:rgba(207,250,254,.72); font-size:9px; text-anchor:middle; paint-order:stroke; stroke:#080d17; stroke-width:4px; stroke-linejoin:round; pointer-events:none; }
        @keyframes wf-run-pulse { from { filter:brightness(1); } to { filter:brightness(1.35); } }

        .wf-inspector input, .wf-inspector textarea, .wf-inspector select { width:100%; border:1px solid rgba(255,255,255,.12); border-radius:10px; background:rgba(255,255,255,.035); color:#e5eef8; padding:10px 11px; font-size:12px; font-family:inherit; }
        .wf-inspector input:focus-visible, .wf-inspector textarea:focus-visible, .wf-inspector select:focus-visible { border-color:rgba(103,232,249,.55); outline-offset:0; }
        .wf-inspector select option { background:#0d1522; color:#e5eef8; }
        .wf-inspector input[type="checkbox"] { width:auto; padding:0; accent-color:#67e8f9; }
        .wf-inspector textarea { min-height:76px; resize:vertical; line-height:1.55; }

        .wf-scroll { scrollbar-width:thin; scrollbar-color:rgba(255,255,255,.18) transparent; }
        .wf-scroll::-webkit-scrollbar { width:7px; height:7px; }
        .wf-scroll::-webkit-scrollbar-thumb { background:rgba(255,255,255,.18); border-radius:99px; }

        #wf-viewport { cursor:grab; touch-action:none; }
        #wf-viewport:active { cursor:grabbing; }

        /* Desktop: fix the app to the window height so each column scrolls on its own */
        @media (min-width:901px) {
            #jc-workflow-builder { height:100vh; height:100dvh; min-height:0; overflow:hidden; }
            .wf-main-grid { grid-template-rows:minmax(0,1fr); min-height:0; overflow:hidden; }
            .wf-main-grid > main { min-height:0; overflow:hidden; }
            .wf-library-column, .wf-inspector-column { height:100%; max-height:100%; overflow-y:auto; overscroll-behavior:contain; }
        }
        /* Tablet and below: stack the layout and give the canvas a guaranteed height */
        @media (max-width:900px) {
            .wf-main-grid { grid-template-columns:190px minmax(0,1fr); }
            .wf-inspector-column { grid-column:1/-1; height:340px; border-left:0; border-top:1px solid rgba(255,255,255,.1); }
            .wf-main-grid > main { min-height:0; }
            #wf-viewport { flex:none; height:60vh; height:60dvh; min-height:340px; }
        }
        @media (max-width:640px) {
            .wf-main-grid { grid-template-columns:minmax(0,1fr); }
            .wf-library-column { max-height:260px; border-right:0; border-bottom:1px solid rgba(255,255,255,.1); }
            .wf-inspector-column { grid-column:auto; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { transition-duration:.01ms !important; animation-duration:.01ms !important; scroll-behavior:auto !important; }
        }
    </style>
</head>
<body>
<noscript>
    <p style="margin:0;padding:16px;background:#1b1020;color:#fbcfe8;font-size:13px;">The automation studio needs JavaScript. Enable it and reload this page.</p>
</noscript>

<div id="jc-workflow-builder" class="flex min-h-screen flex-col">
    <header class="flex min-h-[68px] flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-white/10 bg-[#090e18]/95 px-4 py-2 sm:px-7">
        <div class="flex min-w-0 items-center gap-4">
            <a href="{{ url('/jarvis') }}" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/[.04] text-white/70 transition hover:text-white" aria-label="Back to JARVIS"><span aria-hidden="true">←</span></a>
            <div class="min-w-0">
                <h1 class="m-0 flex items-center gap-2 text-sm font-medium"><span class="h-2 w-2 rounded-full bg-cyan-300 shadow-[0_0_12px_rgba(103,232,249,.8)]" aria-hidden="true"></span>Automation studio</h1>
                <div id="wf-current-name" class="mt-1 truncate text-[11px] text-white/55">Choose a workflow or create one</div>
            </div>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <span id="wf-status" class="hidden rounded-full border px-2.5 py-1 text-[10px] uppercase tracking-wider" role="status"></span>
            <button id="wf-test" type="button" class="rounded-lg border border-white/10 bg-white/[.04] px-3 py-2 text-xs text-white/75 hover:bg-white/[.08] disabled:opacity-40">Test workflow</button>
            <button id="wf-load-test-fixture" type="button" class="rounded-lg border border-cyan-200/15 bg-cyan-200/[.05] px-3 py-2 text-xs text-cyan-100/75 hover:bg-cyan-200/10">Load test fixture</button>
            <button id="wf-activate" type="button" class="rounded-lg border border-emerald-300/20 bg-emerald-300/10 px-3 py-2 text-xs font-medium text-emerald-200 hover:bg-emerald-300/15 disabled:opacity-40">Activate</button>
            <span id="wf-save-status" class="px-1 text-[10px] text-white/45" role="status" aria-live="polite">Loading...</span>
            <button id="wf-save" type="button" class="rounded-lg bg-cyan-300 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-200 disabled:opacity-40">Save</button>
        </div>
    </header>

    <div class="border-b border-white/10 bg-[#0a101a] px-4 py-3 sm:px-7">
        <div class="mx-auto flex max-w-[1600px] flex-wrap items-center gap-2 sm:flex-nowrap">
            <label for="wf-ai-prompt" class="hidden text-[10px] uppercase tracking-[.18em] text-cyan-100/60 sm:inline">Ask JARVIS</label>
            <input id="wf-ai-prompt" type="text" maxlength="5000" autocomplete="off" aria-label="Describe the automation you want JARVIS to draft" placeholder="Describe an automation, e.g. summarize new customer emails and send me a digest" class="h-10 min-w-0 flex-1 basis-full rounded-xl border border-white/10 bg-white/[.03] px-3 text-xs text-white outline-none placeholder:text-white/40 focus:border-cyan-300/40 sm:basis-auto">
            <button id="wf-ai-propose" type="button" class="h-10 shrink-0 rounded-xl border border-violet-300/20 bg-violet-300/10 px-4 text-xs text-violet-100 transition hover:bg-violet-300/15 disabled:opacity-40">Create draft with AI</button>
        </div>
        <p id="wf-message" class="mx-auto mt-2 hidden max-w-[1600px] text-xs text-white/70" role="status" aria-live="polite"></p>
    </div>

    <div class="wf-main-grid grid min-h-0 flex-1 grid-cols-[230px_minmax(0,1fr)_330px]">
        <aside class="wf-library-column wf-scroll flex min-h-0 flex-col overflow-y-auto border-r border-white/10 bg-[#090e17]" aria-label="Workflow library and step palette">
            <div class="flex items-center justify-between border-b border-white/[.07] px-4 py-4">
                <h2 class="m-0 text-[10px] font-medium uppercase tracking-[.18em] text-white/55">My workflows</h2>
                <button id="wf-new" type="button" class="rounded-md border border-cyan-200/20 bg-cyan-200/[.06] px-2 py-1 text-[10px] text-cyan-100/90 hover:bg-cyan-200/[.12]">+ New</button>
            </div>
            <div id="wf-list" class="border-b border-white/[.07] p-2"></div>
            <div id="wf-palette" class="space-y-1 px-2 pb-5"></div>
            <div class="mt-auto border-t border-white/[.07] p-4 text-[11px] leading-5 text-white/50">Drag apps onto the canvas to add them. Drop a process step on a connection to insert it into that path. Click a line to configure its data flow.</div>
        </aside>

        <main class="flex min-h-[460px] min-w-0 flex-col bg-[#070b13]">
            <div class="flex min-h-11 shrink-0 flex-wrap items-center justify-between gap-2 border-b border-white/[.07] px-3 py-1">
                <div class="flex flex-wrap items-center gap-2">
                    <button id="wf-workflow-settings" type="button" class="rounded-md px-2 py-1 text-[11px] text-white/60 hover:bg-white/[.05] hover:text-white">Workflow</button>
                    <button id="wf-zoom-out" type="button" class="h-7 w-7 rounded-md border border-white/10 text-sm text-white/60 hover:text-white" aria-label="Zoom out">−</button>
                    <span id="wf-zoom-label" class="w-10 text-center text-[11px] text-white/55" aria-live="polite">100%</span>
                    <button id="wf-zoom-in" type="button" class="h-7 w-7 rounded-md border border-white/10 text-sm text-white/60 hover:text-white" aria-label="Zoom in">+</button>
                    <button id="wf-fit" type="button" class="ml-1 rounded-md px-2 py-1 text-[11px] text-white/60 hover:bg-white/[.05] hover:text-white">Fit</button>
                    <button id="wf-tidy" type="button" class="rounded-md px-2 py-1 text-[11px] text-white/60 hover:bg-white/[.05] hover:text-white">Tidy up</button>
                </div>
                <div class="hidden text-[11px] text-white/45 md:block">Drafts are saved privately to your JARVIS account</div>
            </div>
            <div id="wf-viewport" class="wf-grid relative min-h-0 flex-1 overflow-hidden" role="region" aria-label="Workflow canvas">
                <div id="wf-loading" class="absolute inset-0 z-20 flex items-center justify-center bg-[#070b13]/95 text-xs text-white/55" role="status" aria-live="polite">Loading workflow...</div>
                <div id="wf-world" class="absolute left-0 top-0 h-[1800px] w-[2200px] origin-top-left">
                    <svg id="wf-edges" class="pointer-events-none absolute inset-0 h-full w-full overflow-visible" aria-label="Workflow connections"></svg>
                    <div id="wf-nodes" class="absolute inset-0"></div>
                    <div id="wf-drop-hint" class="pointer-events-none absolute left-1/2 top-1/2 max-w-[260px] -translate-x-1/2 -translate-y-1/2 text-center text-xs leading-6 text-white/45">Start with an app, then add another app or agent. Drop Condition or Transform onto a connection to add a step between them.</div>
                </div>
            </div>
            <div class="flex min-h-9 shrink-0 flex-wrap items-center justify-between gap-x-4 border-t border-white/[.07] px-3 py-1 text-[11px] text-white/45">
                <span>Scroll to zoom · drag empty canvas to pan · drag node header to move</span>
                <span id="wf-node-count" aria-live="polite">0 steps</span>
            </div>
            <section class="wf-scroll max-h-[220px] shrink-0 overflow-y-auto border-t border-white/10 bg-[#090e17]" aria-labelledby="wf-runs-title">
                <div class="flex items-center justify-between px-4 py-3">
                    <h2 id="wf-runs-title" class="m-0 text-[10px] font-medium uppercase tracking-[.18em] text-white/55">Recent runs</h2>
                    <button id="wf-refresh-runs" type="button" class="text-[11px] text-white/55 hover:text-white">Refresh</button>
                </div>
                <div id="wf-runs" class="space-y-2 px-4 pb-4 text-xs text-white/55">Select a workflow to see its run history.</div>
            </section>
        </main>

        <aside class="wf-inspector-column wf-scroll min-h-0 overflow-y-auto border-l border-white/10 bg-[#090e17]" aria-label="Step settings">
            <div class="border-b border-white/[.07] px-4 py-4">
                <h2 class="m-0 text-[10px] font-medium uppercase tracking-[.18em] text-white/55">Step settings</h2>
                <div id="wf-inspector-title" class="mt-1 text-sm text-white/80">Select a step</div>
            </div>
            <div id="wf-inspector" class="wf-inspector space-y-4 p-4">
                <div class="text-xs leading-6 text-white/55">Add or select a step to configure it. Use <code class="text-cyan-100/80">&#123;&#123;trigger.subject&#125;&#125;</code> style tokens to map data from earlier steps.</div>
            </div>
            <div id="wf-webhook-card" class="hidden border-t border-white/[.07] p-4">
                <h3 class="m-0 text-[10px] font-medium uppercase tracking-[.18em] text-white/55">Webhook endpoint</h3>
                <p class="mt-2 text-[11px] leading-5 text-white/55">This URL contains a private signing token. Keep it in the source app only.</p>
                <div id="wf-webhook-url" class="mt-2 break-all rounded-lg border border-white/10 bg-black/20 p-2 text-[11px] leading-4 text-cyan-100/80"></div>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button id="wf-copy-webhook" type="button" class="rounded-lg border border-white/10 px-3 py-2 text-[11px] text-white/70 hover:text-white">Copy URL</button>
                    <button id="wf-rotate-webhook" type="button" class="rounded-lg border border-orange-300/20 px-3 py-2 text-[11px] text-orange-100/80 hover:text-orange-100">Rotate URL</button>
                </div>
            </div>
        </aside>
    </div>
</div>
<dialog id="wf-test-dialog" class="w-[min(92vw,480px)] rounded-2xl border border-cyan-200/15 bg-[#0c1420] p-0 text-white shadow-2xl backdrop:bg-black/70">
    <form id="wf-test-form" method="dialog" class="space-y-4 p-5">
        <div>
            <h2 class="m-0 text-sm font-medium">Test workflow</h2>
            <p class="mb-0 mt-1 text-xs leading-5 text-white/50">Enter sample input. Test runs use mock data and do not contact external apps.</p>
        </div>
        <label class="block space-y-2 text-xs text-white/70" for="wf-test-input">
            <span>Request</span>
            <textarea id="wf-test-input" rows="4" maxlength="10000" required class="w-full resize-y rounded-xl border border-white/10 bg-black/20 px-3 py-2.5 text-xs leading-5 text-white outline-none placeholder:text-white/30 focus:border-cyan-200/40">{"project_code":"PRJ-001","item_code":"ITEM-001","quantity":100,"school_id":"SCH-001","dr_number":"DR-001"}</textarea>
        </label>
        <div class="flex justify-end gap-2">
            <button id="wf-test-cancel" type="button" class="rounded-lg border border-white/10 px-3 py-2 text-xs text-white/65 hover:text-white">Cancel</button>
            <button id="wf-test-submit" type="submit" class="rounded-lg bg-cyan-200 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-100">Run test</button>
        </div>
    </form>
</dialog>
</body>
</html>
