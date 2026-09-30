<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>JARVIS Automations</title>
    @vite(['resources/css/app.css', 'resources/js/jarvis-workflows.js'])
    <style>
        :root { color-scheme: dark; }
        html, body { min-height: 100%; margin: 0; background: #070b13; color: #e5eef8; font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
        .wf-grid { background-color:#080d17; background-image:radial-gradient(rgba(108,211,237,.16) .8px,transparent .8px); background-size:22px 22px; }
        .wf-node { position:absolute; width:236px; min-height:112px; user-select:none; touch-action:none; }
        .wf-port { position:absolute; top:50%; width:12px; height:12px; border:2px solid #6ee7f9; border-radius:999px; background:#08121f; transform:translateY(-50%); cursor:crosshair; }
        .wf-port-in { left:-7px; }
        .wf-port-out { right:-7px; }
        .wf-node[data-category="trigger"] .wf-port-in { display:none; }
        .wf-node[data-selected="true"] { outline:1px solid rgba(103,232,249,.8); box-shadow:0 0 0 4px rgba(34,211,238,.08),0 18px 60px rgba(0,0,0,.38); }
        .wf-line { fill:none; stroke:rgba(103,232,249,.75); stroke-width:2; }
        .wf-line-active { stroke:#9af3ff; filter:drop-shadow(0 0 5px rgba(103,232,249,.65)); }
        .wf-inspector input,.wf-inspector textarea,.wf-inspector select { width:100%; box-sizing:border-box; border:1px solid rgba(255,255,255,.1); border-radius:10px; background:rgba(255,255,255,.035); color:#e5eef8; padding:10px 11px; font-size:12px; outline:none; }
        .wf-inspector input:focus,.wf-inspector textarea:focus,.wf-inspector select:focus { border-color:rgba(103,232,249,.55); }
        .wf-inspector textarea { min-height:76px; resize:vertical; line-height:1.55; }
        .wf-scroll::-webkit-scrollbar { width:7px; height:7px; }
        .wf-scroll::-webkit-scrollbar-thumb { background:rgba(255,255,255,.13); border-radius:99px; }
        @media(max-width:900px) { .wf-main-grid { grid-template-columns:190px minmax(0,1fr); } .wf-inspector-column { grid-column:1/-1; height:300px; } }
        @media(max-width:640px) { .wf-main-grid { grid-template-columns:minmax(0,1fr); } .wf-library-column { display:none; } .wf-inspector-column { grid-column:auto; } }
    </style>
</head>
<body>
<div id="jc-workflow-builder" class="flex min-h-screen flex-col">
    <header class="flex min-h-[68px] items-center justify-between gap-4 border-b border-white/10 bg-[#090e18]/95 px-4 sm:px-7">
        <div class="flex min-w-0 items-center gap-4">
            <a href="{{ url('/jarvis') }}" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/[.04] text-white/60 transition hover:text-white" aria-label="Back to JARVIS">←</a>
            <div class="min-w-0">
                <div class="flex items-center gap-2 text-sm font-medium"><span class="h-2 w-2 rounded-full bg-cyan-300 shadow-[0_0_12px_rgba(103,232,249,.8)]"></span>Automation studio</div>
                <div id="wf-current-name" class="mt-1 truncate text-[11px] text-white/35">Choose a workflow or create one</div>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <span id="wf-status" class="hidden rounded-full border px-2.5 py-1 text-[10px] uppercase tracking-wider"></span>
            <button id="wf-test" type="button" class="rounded-lg border border-white/10 bg-white/[.04] px-3 py-2 text-xs text-white/70 hover:bg-white/[.08] disabled:opacity-40">Test run</button>
            <button id="wf-activate" type="button" class="rounded-lg border border-emerald-300/20 bg-emerald-300/10 px-3 py-2 text-xs font-medium text-emerald-200 hover:bg-emerald-300/15 disabled:opacity-40">Activate</button>
            <button id="wf-save" type="button" class="rounded-lg bg-cyan-300 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-200 disabled:opacity-40">Save</button>
        </div>
    </header>

    <div class="border-b border-white/10 bg-[#0a101a] px-4 py-3 sm:px-7">
        <div class="mx-auto flex max-w-[1600px] items-center gap-2">
            <span class="hidden text-[10px] uppercase tracking-[.18em] text-cyan-100/45 sm:inline">Ask JARVIS</span>
            <input id="wf-ai-prompt" type="text" maxlength="5000" placeholder="Describe an automation, e.g. summarize new customer emails and send me a digest" class="h-10 min-w-0 flex-1 rounded-xl border border-white/10 bg-white/[.03] px-3 text-xs text-white outline-none placeholder:text-white/25 focus:border-cyan-300/40">
            <button id="wf-ai-propose" type="button" class="h-10 rounded-xl border border-violet-300/20 bg-violet-300/10 px-4 text-xs text-violet-100 transition hover:bg-violet-300/15 disabled:opacity-40">Create draft with AI</button>
        </div>
        <p id="wf-message" class="mx-auto mt-2 hidden max-w-[1600px] text-xs text-white/50" role="status" aria-live="polite"></p>
    </div>

    <div class="wf-main-grid grid min-h-0 flex-1 grid-cols-[230px_minmax(0,1fr)_320px]">
        <aside class="wf-library-column wf-scroll flex min-h-0 flex-col overflow-y-auto border-r border-white/10 bg-[#090e17]">
            <div class="flex items-center justify-between border-b border-white/[.07] px-4 py-4">
                <div class="text-[10px] font-medium uppercase tracking-[.18em] text-white/40">My workflows</div>
                <button id="wf-new" type="button" class="rounded-md border border-cyan-200/20 bg-cyan-200/[.06] px-2 py-1 text-[10px] text-cyan-100/80 hover:bg-cyan-200/[.12]">+ New</button>
            </div>
            <div id="wf-list" class="border-b border-white/[.07] p-2"></div>
            <div class="px-4 pb-3 pt-5 text-[10px] font-medium uppercase tracking-[.18em] text-white/35">Add a step</div>
            <div id="wf-palette" class="space-y-1 px-2 pb-5"></div>
            <div class="mt-auto border-t border-white/[.07] p-4 text-[10px] leading-5 text-white/30">Drag a step onto the canvas or click to add it. Connect steps using the small side ports.</div>
        </aside>

        <main class="flex min-h-[460px] min-w-0 flex-col bg-[#070b13]">
            <div class="flex h-11 shrink-0 items-center justify-between border-b border-white/[.07] px-3">
                <div class="flex items-center gap-2">
                    <button id="wf-workflow-settings" type="button" class="rounded-md px-2 py-1 text-[10px] text-white/40 hover:bg-white/[.05] hover:text-white/70">Workflow</button>
                    <button id="wf-zoom-out" type="button" class="h-7 w-7 rounded-md border border-white/10 text-sm text-white/50 hover:text-white" aria-label="Zoom out">−</button>
                    <span id="wf-zoom-label" class="w-10 text-center text-[10px] text-white/40">100%</span>
                    <button id="wf-zoom-in" type="button" class="h-7 w-7 rounded-md border border-white/10 text-sm text-white/50 hover:text-white" aria-label="Zoom in">+</button>
                    <button id="wf-fit" type="button" class="ml-1 rounded-md px-2 py-1 text-[10px] text-white/40 hover:bg-white/[.05] hover:text-white/70">Fit</button>
                </div>
                <div class="text-[10px] text-white/25">Drafts are saved privately to your JARVIS account</div>
            </div>
            <div id="wf-viewport" class="wf-grid relative min-h-0 flex-1 overflow-hidden" aria-label="Workflow canvas">
                <div id="wf-world" class="absolute left-0 top-0 h-[1200px] w-[1800px] origin-top-left">
                    <svg id="wf-edges" class="pointer-events-none absolute inset-0 h-full w-full overflow-visible"></svg>
                    <div id="wf-nodes" class="absolute inset-0"></div>
                    <div id="wf-drop-hint" class="pointer-events-none absolute left-1/2 top-1/2 max-w-[260px] -translate-x-1/2 -translate-y-1/2 text-center text-xs leading-6 text-white/25">Start with a trigger from the left panel. Add actions and connect the ports.</div>
                </div>
            </div>
            <div class="flex h-9 shrink-0 items-center justify-between border-t border-white/[.07] px-3 text-[10px] text-white/25">
                <span>Scroll to zoom · drag empty canvas to pan · drag node header to move</span>
                <span id="wf-node-count">0 steps</span>
            </div>
            <section class="wf-scroll max-h-[220px] shrink-0 overflow-y-auto border-t border-white/10 bg-[#090e17]">
                <div class="flex items-center justify-between px-4 py-3">
                    <div class="text-[10px] font-medium uppercase tracking-[.18em] text-white/40">Recent runs</div>
                    <button id="wf-refresh-runs" type="button" class="text-[10px] text-white/35 hover:text-white/70">Refresh</button>
                </div>
                <div id="wf-runs" class="space-y-2 px-4 pb-4 text-xs text-white/35">Select a workflow to see its run history.</div>
            </section>
        </main>

        <aside class="wf-inspector-column wf-scroll min-h-0 overflow-y-auto border-l border-white/10 bg-[#090e17]">
            <div class="border-b border-white/[.07] px-4 py-4">
                <div class="text-[10px] font-medium uppercase tracking-[.18em] text-white/40">Step settings</div>
                <div id="wf-inspector-title" class="mt-1 text-sm text-white/75">Select a step</div>
            </div>
            <div id="wf-inspector" class="wf-inspector space-y-4 p-4">
                <div class="text-xs leading-6 text-white/35">Add or select a step to configure it. Use <code class="text-cyan-100/60">&#123;&#123;trigger.subject&#125;&#125;</code> style tokens to map data from earlier steps.</div>
            </div>
            <div id="wf-webhook-card" class="hidden border-t border-white/[.07] p-4">
                <div class="text-[10px] font-medium uppercase tracking-[.18em] text-white/40">Webhook endpoint</div>
                <p class="mt-2 text-[10px] leading-5 text-white/35">This URL contains a private signing token. Keep it in the source app only.</p>
                <div id="wf-webhook-url" class="mt-2 break-all rounded-lg border border-white/10 bg-black/20 p-2 text-[10px] leading-4 text-cyan-100/70"></div>
                <button id="wf-copy-webhook" type="button" class="mt-2 rounded-lg border border-white/10 px-3 py-2 text-[10px] text-white/60 hover:text-white">Copy URL</button>
                <button id="wf-rotate-webhook" type="button" class="ml-2 rounded-lg border border-orange-300/10 px-3 py-2 text-[10px] text-orange-100/60 hover:text-orange-100">Rotate URL</button>
            </div>
        </aside>
    </div>
</div>
</body>
</html>
