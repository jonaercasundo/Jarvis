<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title data-ai-name>{{ $aiName }}</title>

    {{-- PERF: make sure `npm run build` has been run and public/hot does NOT exist in production. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/jarvis-avatar-3d.js'])

    {{-- PERF: warm up the CDN connections used for Three.js and the display font --}}
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@200;300;400;500;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --jc-cyan: 110, 231, 255;
            --jc-violet: 167, 139, 250;
            --jc-emerald: 52, 211, 153;
            --jc-holo-intensity: 1;
        }

        html,
        body {
            height: 100%;
            margin: 0;
            overflow: hidden;
        }

        body {
            background:
                radial-gradient(ellipse at 50% 31%, rgba(17, 92, 142, calc(0.34 * var(--jc-holo-intensity))), transparent 46%),
                radial-gradient(ellipse at 18% 68%, rgba(18, 89, 132, calc(0.18 * var(--jc-holo-intensity))), transparent 42%),
                radial-gradient(ellipse at 84% 74%, rgba(79, 59, 139, calc(0.12 * var(--jc-holo-intensity))), transparent 40%),
                linear-gradient(180deg, #07111f 0%, #081a2c 52%, #0b2440 100%);
            color: #e5e7eb;
            font-family: 'Sora', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        }

        /* ---------- Ambient particle network (page background) ---------- */
        #jc-network-bg {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: none;
            opacity: calc(0.36 * var(--jc-holo-intensity));
        }

        /* Perspective floor grid, fades out toward the horizon */
        #jc-grid-bg {
            position: fixed;
            left: -25%;
            right: -25%;
            bottom: 0;
            height: 46vh;
            z-index: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(var(--jc-cyan), calc(0.15 * var(--jc-holo-intensity))) 1px, transparent 1px),
                linear-gradient(90deg, rgba(var(--jc-cyan), calc(0.15 * var(--jc-holo-intensity))) 1px, transparent 1px);
            background-size: 72px 72px;
            transform: perspective(520px) rotateX(62deg);
            transform-origin: 50% 100%;
            -webkit-mask-image: linear-gradient(to top, #000 0%, transparent 85%);
            mask-image: linear-gradient(to top, #000 0%, transparent 85%);
            opacity: 0.24;
            animation: jc-grid-breathe 9s ease-in-out infinite;
        }

        #jc-grid-bg::before {
            content: "";
            position: absolute;
            inset: -10% 0 35%;
            background: radial-gradient(ellipse at 50% 100%, rgba(29, 186, 241, calc(0.16 * var(--jc-holo-intensity))), transparent 68%);
            pointer-events: none;
        }

        #jc-app {
            position: relative;
            z-index: 4;
        }

        #jc-workspace {
            --jc-workspace-width: 48%;
            position: absolute;
            z-index: 8;
            top: 4rem;
            right: 0;
            bottom: 3rem;
            display: flex;
            width: var(--jc-workspace-width);
            min-width: 320px;
            flex-direction: column;
            border-left: 1px solid rgba(110, 231, 255, 0.18);
            background: linear-gradient(150deg, rgba(7, 20, 35, 0.96), rgba(8, 24, 43, 0.92));
            box-shadow: -24px 0 70px rgba(0, 0, 0, 0.24);
            transition: width 180ms ease;
        }

        #jc-workspace[hidden] { display: none; }

        #jc-workspace-stage.has-artifacts {
            width: calc(100% - var(--jc-workspace-width, 48%));
            flex: 0 0 auto;
            transition: width 180ms ease;
        }

        #jc-workspace-stage.has-artifacts > div { max-width: 52rem; }

        #jc-workspace.is-collapsed #jc-workspace-artifacts,
        #jc-workspace.is-collapsed #jc-workspace-minimized,
        #jc-workspace.is-collapsed [data-workspace-splitter] { display: none; }

        #jc-workspace.is-collapsed { width: 3.5rem; min-width: 3.5rem; }
        #jc-workspace.is-collapsed .jc-workspace-header { justify-content: center; padding: 0.5rem; }
        #jc-workspace.is-collapsed .jc-workspace-header > div:first-child,
        #jc-workspace.is-collapsed .jc-workspace-header-actions button:not([data-workspace-action="collapse"]) { display: none; }

        @media (max-width: 760px) {
            #jc-workspace {
                top: 4rem;
                bottom: 3rem;
                width: 100%;
                min-width: 0;
                border-left: 0;
            }

            #jc-workspace-stage.has-artifacts { width: 100%; }
            #jc-app.jc-workspace-active:not(.jc-mobile-conversation) #jc-workspace-stage { visibility: hidden; }
            #jc-app.jc-mobile-conversation #jc-workspace { display: none; }
        }

        /* ---------- Glass panels (PERF: blur radius kept low; it sits above animating canvases) ---------- */
        .jc-glass {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.012));
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        .jc-glass-strong {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.055), rgba(255, 255, 255, 0.02));
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 0 0 1px rgba(var(--jc-cyan), 0.07), 0 24px 70px -24px rgba(59, 130, 246, 0.32);
        }

        /* ---------- Shimmer wordmark ---------- */
        .jc-shimmer-text {
            background: linear-gradient(110deg, #6ee7ff 20%, #c4b5fd 45%, #6ee7ff 70%);
            background-size: 220% auto;
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            animation: jc-shimmer 7s linear infinite;
        }

        @keyframes jc-shimmer {
            to { background-position: -220% center; }
        }

        /* ---------- JC: a luminous human presence ---------- */
        #jarvis-avatar-wrap {
            position: fixed;
            inset: 0;
            z-index: 2;
            pointer-events: none;
            overflow: hidden;
        }

        #jarvis-avatar-wrap::before {
            content: "";
            position: absolute;
            z-index: 0;
            width: min(88vw, 920px);
            height: min(92vh, 940px);
            left: 50%;
            top: 1%;
            transform: translateX(-50%);
            border-radius: 50%;
            background:
                radial-gradient(ellipse 31% 53% at 50% 43%, rgba(36, 196, 242, 0.19), transparent 100%),
                radial-gradient(ellipse 58% 46% at 50% 38%, rgba(24, 102, 176, 0.22), transparent 72%),
                radial-gradient(ellipse 70% 64% at 50% 42%, rgba(var(--jc-accent), 0.10), transparent 72%);
            opacity: calc(0.88 * var(--jc-holo-intensity));
            filter: blur(18px);
            animation: jc-holo-breathe 8s ease-in-out infinite;
            transition: opacity 0.45s ease, background 0.45s ease;
            pointer-events: none;
        }

        #jarvis-avatar-wrap::after {
            content: "";
            position: absolute;
            z-index: 1;
            inset: -18%;
            background:
                repeating-radial-gradient(ellipse at 50% 40%, transparent 0 225px, rgba(97, 219, 255, calc(0.105 * var(--jc-holo-intensity))) 226px 227px, transparent 228px 292px),
                radial-gradient(ellipse at 50% 40%, transparent 0 33%, rgba(67, 175, 238, calc(0.07 * var(--jc-holo-intensity))) 33.1% 33.2%, transparent 33.4% 48%, rgba(151, 129, 255, calc(0.06 * var(--jc-holo-intensity))) 48.1% 48.2%, transparent 48.4%);
            opacity: calc(0.72 * var(--jc-holo-intensity));
            -webkit-mask-image: radial-gradient(ellipse at center, #000 24%, transparent 76%);
            mask-image: radial-gradient(ellipse at center, #000 24%, transparent 76%);
            animation: jc-hud-orbit 54s linear infinite;
            pointer-events: none;
        }

        #jc-avatar-3d {
            position: absolute;
            inset: 0;
            z-index: 2;
            display: none;
            width: 100%;
            height: 100%;
            pointer-events: none;
        }

        #jarvis-avatar-wrap.jc-particles-ready #jc-avatar-3d,
        #jarvis-avatar-wrap.jc-vrm-loading #jc-avatar-3d,
        #jarvis-avatar-wrap.jc-vrm-ready #jc-avatar-3d { display: block; }

        #jc-avatar-portrait {
            position: absolute;
            left: 50%;
            bottom: -5vh;
            width: min(118vw, 1120px);
            height: auto;
            max-height: 112vh;
            transform: translateX(-50%);
            overflow: visible;
            opacity: 0.94;
            filter: drop-shadow(0 0 28px rgba(var(--jc-accent), 0.22));
            transition: filter 0.45s ease, opacity 0.45s ease;
            visibility: hidden;
        }

        #jc-avatar-portrait .jc-avatar-head {
            transform-box: view-box;
            transform-origin: 450px 270px;
            transition: filter 0.35s ease;
            filter: drop-shadow(0 0 14px rgba(var(--jc-accent), 0.16));
        }

        #jc-avatar-portrait .jc-avatar-bust {
            transform-box: view-box;
            transform-origin: 450px 610px;
            animation: jc-bust-glow 5.6s ease-in-out infinite;
        }

        #jc-avatar-portrait .jc-face-highlight {
            mix-blend-mode: screen;
            opacity: 0.52;
        }

        #jc-avatar-portrait .jc-avatar-orbit {
            transform-box: view-box;
            transform-origin: 450px 330px;
            animation: jc-orbit-drift 72s linear infinite;
        }

        #jc-avatar-portrait .jc-avatar-node {
            transform-box: fill-box;
            transform-origin: center;
            animation: jc-node-glint 3.8s ease-in-out infinite;
        }

        #jc-avatar-portrait .jc-avatar-node:nth-of-type(2) {
            animation-delay: -1.3s;
        }

        #jc-avatar-portrait .jc-avatar-node:nth-of-type(3) {
            animation-delay: -2.5s;
        }

        #jc-avatar-portrait .jc-holo-core {
            transform-box: fill-box;
            transform-origin: center;
            filter: drop-shadow(0 0 12px rgba(var(--jc-accent), 0.9));
            animation: jc-core-pulse 2.8s ease-in-out infinite;
        }

        #jc-avatar-portrait .jc-holo-node {
            transform-box: fill-box;
            transform-origin: center;
            animation: jc-node-glint 2.8s ease-in-out infinite;
        }

        #jc-avatar-portrait .jc-hud-line {
            stroke-dasharray: 8 14;
            animation: jc-circuit-charge 9s linear infinite;
        }

        body:has(.ai-listening) #jc-avatar-portrait .jc-hud-line {
            animation-duration: 2.4s;
        }

        body:has(.ai-speaking) #jc-avatar-portrait .jc-holo-core {
            animation-duration: 0.48s;
        }

        body:has(.ai-listening) #jc-avatar-portrait .jc-holo-node {
            animation-duration: 0.9s;
        }

        body:has(.ai-thinking) #jc-avatar-portrait .jc-holo-node {
            animation-duration: 1.2s;
        }

        #jc-avatar-portrait .jc-avatar-eyes {
            transform-box: fill-box;
            transform-origin: center;
            animation: jc-avatar-blink 6.2s ease-in-out infinite;
        }

        #jc-avatar-portrait .jc-avatar-listen-gesture {
            opacity: 0;
            transform: scale(0.88);
            transform-box: view-box;
            transform-origin: 450px 300px;
            transition: opacity 0.3s ease, transform 0.55s cubic-bezier(0.2, 0.8, 0.2, 1);
        }

        #jc-avatar-portrait .jc-mouth-aperture {
            transform-box: fill-box;
            transform-origin: center;
            transform: scaleY(0.12);
            transition: transform 0.12s ease;
        }

        #jc-avatar-portrait .jc-avatar-scan {
            opacity: 0.18;
            transform: translateY(-310px);
            transition: opacity 0.35s ease;
        }

        body:has(.ai-listening) #jarvis-avatar-wrap::before {
            opacity: 1;
            transform: translateX(-50%) scale(1.08);
        }

        body:has(.ai-thinking) #jarvis-avatar-wrap::before {
            opacity: 0.78;
        }

        body:has(.ai-speaking) #jarvis-avatar-wrap::before {
            opacity: 0.95;
            transform: translateX(-50%) scale(1.04);
        }

        body:has(.ai-idle) #jc-avatar-portrait .jc-avatar-head,
        body:has(.ai-armed) #jc-avatar-portrait .jc-avatar-head {
            animation: jc-avatar-breathe 4.8s ease-in-out infinite;
        }

        body:has(.ai-idle) #jarvis-avatar-wrap::before,
        body:has(.ai-armed) #jarvis-avatar-wrap::before {
            animation: jc-avatar-aura 4.8s ease-in-out infinite;
        }

        body:has(.ai-listening) #jc-avatar-portrait .jc-avatar-head {
            animation: jc-listening-nod 2.4s ease-in-out infinite;
            filter: drop-shadow(0 0 22px rgba(var(--jc-accent), 0.26));
        }

        body:has(.ai-listening) #jc-avatar-portrait .jc-avatar-eyes {
            filter: drop-shadow(0 0 9px rgba(103, 239, 255, 0.7));
            animation-duration: 4.2s;
        }

        body:has(.ai-listening) #jc-avatar-portrait .jc-avatar-orbit {
            animation-duration: 24s;
        }

        body:has(.ai-listening) #jc-avatar-portrait .jc-avatar-listen-gesture {
            opacity: 0.92;
            transform: scale(1);
            animation: jc-listening-radar 1.8s ease-in-out infinite;
        }

        body:has(.ai-listening) #jc-avatar-portrait .jc-avatar-scan {
            opacity: 0.8;
            animation: jc-listening-scan 3.2s ease-in-out infinite;
        }

        body:has(.ai-speaking) #jc-avatar-portrait .jc-mouth-aperture {
            animation: jc-speaking-mouth 0.34s ease-in-out infinite alternate;
        }

        body:has(.ai-speaking) #jc-avatar-portrait .jc-avatar-head {
            animation: jc-speaking-nod 0.9s ease-in-out infinite alternate;
            filter: drop-shadow(0 0 24px rgba(var(--jc-accent), 0.32));
        }

        body:has(.ai-speaking) #jc-avatar-portrait .jc-avatar-eyes {
            filter: drop-shadow(0 0 12px rgba(103, 239, 255, 0.9));
        }

        body:has(.ai-thinking) #jc-avatar-portrait .jc-avatar-orbit {
            animation-duration: 16s;
        }

        #jarvis-avatar-wrap.jc-avatar-pulse #jc-avatar-portrait {
            filter: drop-shadow(0 0 38px rgba(var(--jc-accent), 0.55));
        }

        @keyframes jc-listening-nod {
            0%, 100% { transform: rotate(0deg) translateY(0); }
            38% { transform: rotate(-2deg) translateY(3px); }
            68% { transform: rotate(-1deg) translateY(1px); }
        }

        @keyframes jc-avatar-breathe {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-3px) rotate(0.35deg); }
        }

        @keyframes jc-avatar-aura {
            0%, 100% { opacity: calc(0.48 * var(--jc-holo-intensity)); }
            50% { opacity: calc(0.72 * var(--jc-holo-intensity)); }
        }

        @keyframes jc-bust-glow {
            0%, 100% { opacity: 0.86; }
            50% { opacity: 1; }
        }

        @keyframes jc-avatar-blink {
            0%, 41%, 43%, 81%, 83%, 100% { transform: scaleY(1); }
            42%, 82% { transform: scaleY(0.08); }
        }

        @keyframes jc-orbit-drift {
            to { transform: rotate(360deg); }
        }

        @keyframes jc-node-glint {
            0%, 100% { opacity: 0.45; transform: scale(0.8); }
            50% { opacity: 1; transform: scale(1.35); }
        }

        @keyframes jc-core-pulse {
            0%, 100% { opacity: 0.65; transform: scale(0.86); }
            50% { opacity: 1; transform: scale(1.18); }
        }

        @keyframes jc-circuit-charge {
            to { stroke-dashoffset: -88; }
        }

        @keyframes jc-listening-scan {
            0% { transform: translateY(-300px); }
            50% { transform: translateY(300px); }
            100% { transform: translateY(-300px); }
        }

        @keyframes jc-listening-radar {
            0%, 100% { transform: scale(0.96); opacity: 0.55; }
            50% { transform: scale(1.06); opacity: 1; }
        }

        @keyframes jc-speaking-mouth {
            0% { transform: scaleY(0.18); }
            25% { transform: scaleY(0.92); }
            55% { transform: scaleY(0.34); }
            78% { transform: scaleY(1.22); }
            100% { transform: scaleY(0.42); }
        }

        @keyframes jc-speaking-nod {
            from { transform: rotate(0deg) translateY(0); }
            to { transform: rotate(0.7deg) translateY(2px); }
        }

        @media (max-width: 640px) {
            #jc-avatar-portrait {
                width: min(170vw, 760px);
                max-height: 94vh;
                bottom: 0;
                opacity: 0.78;
            }

            #jarvis-avatar-wrap::before {
                width: 124vw;
                height: 72vh;
                top: 12%;
            }

            #jc-grid-bg {
                left: -42%;
                right: -42%;
                height: 38vh;
                background-size: 52px 52px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            #jc-avatar-portrait *,
            #jarvis-avatar-wrap::before {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* Scrim keeps the interface readable over the figure */
        #jc-scrim {
            position: fixed;
            inset: 0;
            z-index: 3;
            pointer-events: none;
            background:
                linear-gradient(to top, rgba(3, 12, 29, 0.76) 0%, rgba(3, 12, 29, 0.38) 25%, transparent 58%),
                linear-gradient(to bottom, rgba(5, 15, 31, 0.24), transparent 18%);
        }

        #jarvis-command {
            border-color: rgba(130, 221, 255, 0.2);
            background: linear-gradient(145deg, rgba(13, 29, 48, 0.88), rgba(8, 19, 33, 0.83));
            box-shadow: 0 18px 55px rgba(0, 8, 22, 0.38), 0 0 34px -16px rgba(59, 205, 255, 0.32);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        @keyframes jc-holo-breathe {
            0%, 100% { opacity: calc(0.78 * var(--jc-holo-intensity)); }
            50% { opacity: calc(0.98 * var(--jc-holo-intensity)); }
        }

        @keyframes jc-hud-orbit {
            to { transform: rotate(360deg); }
        }

        @keyframes jc-grid-breathe {
            0%, 100% { opacity: calc(0.19 * var(--jc-holo-intensity)); }
            50% { opacity: calc(0.27 * var(--jc-holo-intensity)); }
        }

        /* -------- ambient states (CSS glow, independent of WebGL) -------- */
        body:has(.ai-idle) #jarvis-avatar-wrap::before { opacity: calc(0.5 * var(--jc-holo-intensity)); }
        body:has(.ai-armed) #jarvis-avatar-wrap::before { opacity: calc(0.7 * var(--jc-holo-intensity)); }
        body:has(.ai-listening) #jarvis-avatar-wrap::before {
            opacity: var(--jc-holo-intensity);
            animation: jc-listen-pulse 1.2s ease-in-out infinite;
        }
        body:has(.ai-thinking) #jarvis-avatar-wrap::before {
            opacity: calc(0.85 * var(--jc-holo-intensity));
            animation: jc-think-flicker 1.1s ease-in-out infinite;
        }
        body:has(.ai-speaking) #jarvis-avatar-wrap::before {
            opacity: var(--jc-holo-intensity);
            animation: jc-speak-flicker 0.5s ease-in-out infinite;
        }

        @keyframes jc-listen-pulse {
            0%, 100% { opacity: calc(0.7 * var(--jc-holo-intensity)); }
            50% { opacity: var(--jc-holo-intensity); }
        }

        @keyframes jc-think-flicker {
            0%, 100% { opacity: calc(0.55 * var(--jc-holo-intensity)); }
            50% { opacity: calc(0.9 * var(--jc-holo-intensity)); }
        }

        @keyframes jc-speak-flicker {
            0%, 100% { opacity: calc(0.8 * var(--jc-holo-intensity)); }
            50% { opacity: var(--jc-holo-intensity); }
        }

        /* Voice activity bars: live only while listening or speaking */
        .jc-wave {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            height: 18px;
            margin-top: 12px;
        }

        .jc-wave span {
            width: 2px;
            height: 3px;
            border-radius: 2px;
            background: rgba(var(--jc-cyan), 0.45);
            transition: background 0.3s ease;
        }

        .ai-listening .jc-wave span,
        .ai-speaking .jc-wave span {
            animation: jc-wave 0.9s ease-in-out infinite;
        }
        .ai-listening .jc-wave span { background: rgba(var(--jc-emerald), 0.8); }
        .ai-speaking .jc-wave span { background: rgba(var(--jc-cyan), 0.9); animation-duration: 0.55s; }
        .ai-thinking .jc-wave span {
            background: rgba(var(--jc-violet), 0.8);
            animation: jc-think-bar 1.2s ease-in-out infinite;
        }

        .jc-wave span:nth-child(1) { animation-delay: 0ms; }
        .jc-wave span:nth-child(2) { animation-delay: 70ms; }
        .jc-wave span:nth-child(3) { animation-delay: 140ms; }
        .jc-wave span:nth-child(4) { animation-delay: 210ms; }
        .jc-wave span:nth-child(5) { animation-delay: 280ms; }
        .jc-wave span:nth-child(6) { animation-delay: 210ms; }
        .jc-wave span:nth-child(7) { animation-delay: 140ms; }
        .jc-wave span:nth-child(8) { animation-delay: 70ms; }
        .jc-wave span:nth-child(9) { animation-delay: 0ms; }

        @keyframes jc-wave {
            0%, 100% { height: 3px; }
            50% { height: 16px; }
        }

        @keyframes jc-think-bar {
            0%, 100% { height: 3px; opacity: 0.35; }
            50% { height: 8px; opacity: 1; }
        }

        #jarvis-state-label {
            transition: color 0.2s ease;
        }

        /* PERF: stop all CSS animations when the tab is hidden */
        html.jc-tab-hidden *,
        html.jc-tab-hidden *::before,
        html.jc-tab-hidden *::after {
            animation-play-state: paused !important;
        }

        /* ---------- Command bar ---------- */
        #jarvis-command {
            transition: box-shadow 0.25s ease, border-color 0.25s ease;
        }

        #jarvis-command:focus-within {
            border-color: rgba(var(--jc-cyan), 0.4);
            box-shadow: 0 0 0 1px rgba(var(--jc-cyan), 0.25), 0 0 36px -8px rgba(var(--jc-cyan), 0.4);
        }

        /* Visible keyboard focus everywhere */
        button:focus-visible,
        a:focus-visible,
        select:focus-visible {
            outline: 2px solid rgba(var(--jc-cyan), 0.6);
            outline-offset: 2px;
        }

        /* ---------- Console / email reply ---------- */
        #jarvis-email-view textarea {
            resize: vertical;
        }

        #jarvis-email-body {
            max-height: 160px;
            overflow-y: auto;
        }

        .jc-fade-in {
            animation: jc-fade-in 0.25s ease-out;
        }

        @keyframes jc-fade-in {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .jc-error-message {
            color: #ef4444;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }

        @media (prefers-reduced-motion: reduce) {
            body:has(.ai-listening) #jarvis-avatar-wrap::before,
            body:has(.ai-thinking) #jarvis-avatar-wrap::before,
            body:has(.ai-speaking) #jarvis-avatar-wrap::before,
            #jarvis-avatar-wrap::after,
            #jc-grid-bg,
            .ai-listening .jc-wave span,
            .ai-speaking .jc-wave span,
            .ai-thinking .jc-wave span,
            .jc-shimmer-text {
                animation: none !important;
            }
        }

    </style>
    <style>
    /* State colour: the halo, iris rim and pulses all follow this one variable */
    #jarvis-avatar-wrap { --jc-accent: 110, 231, 255; --jc-mouth: 0; }
    #jarvis-avatar-wrap[data-state="armed"] { --jc-accent: 88, 220, 218; }
    #jarvis-avatar-wrap[data-state="listening"] { --jc-accent: 52, 211, 153; }
    #jarvis-avatar-wrap[data-state="thinking"]  { --jc-accent: 167, 139, 250; }
 
    /* ---------- Voice halo (the signature element) ---------- */
    #jc-avatar-portrait .jc-halo-ticks {
        stroke: rgb(var(--jc-accent));
        stroke-opacity: 0.85;
        filter: drop-shadow(0 0 6px rgba(var(--jc-accent), 0.55));
        transition: stroke 0.35s ease;
    }
    #jc-avatar-portrait .jc-halo-guide {
        stroke: rgb(var(--jc-accent));
        stroke-opacity: 0.22;
    }
 
    /* ---------- Eyes: blink is driven from JS so the rhythm isn't mechanical ---------- */
    #jc-avatar-portrait .jc-avatar-eyes {
        animation: none;
        transition: transform 0.07s ease-out;
    }
    #jc-avatar-portrait .jc-avatar-eyes.jc-blink { transform: scaleY(0.08); }
    #jc-avatar-portrait .jc-iris-rim { stroke: rgb(var(--jc-accent)); transition: stroke 0.35s ease; }
 
    /* ---------- Brows carry the emotion ---------- */
    #jc-avatar-portrait .jc-brow {
        transform-box: fill-box;
        transform-origin: center;
        transition: transform 0.45s cubic-bezier(0.2, 0.8, 0.2, 1);
    }
    body:has(.ai-listening) #jc-avatar-portrait .jc-brow { transform: translateY(-3px); }
    body:has(.ai-thinking) #jc-avatar-portrait .jc-brow-l { transform: translateY(-5px) rotate(-4deg); }
    body:has(.ai-thinking) #jc-avatar-portrait .jc-brow-r { transform: translateY(1px) rotate(2deg); }
 
    /* ---------- Mouth + lower lip ---------- */
    #jc-avatar-portrait .jc-lip-lower {
        transform-box: fill-box;
        transform-origin: center top;
    }
    body:has(.ai-speaking) #jc-avatar-portrait .jc-lip-lower {
        animation: jc-lip-talk 0.34s ease-in-out infinite alternate;
    }
    /* When word-boundary events arrive, JS drives the mouth with --jc-mouth */
    #jarvis-avatar-wrap.jc-mouth-driven #jc-avatar-portrait .jc-mouth-aperture {
        animation: none !important;
        transform: scaleY(calc(0.12 + var(--jc-mouth) * 1.1));
        transition: transform 0.06s linear;
    }
    #jarvis-avatar-wrap.jc-mouth-driven #jc-avatar-portrait .jc-lip-lower {
        animation: none !important;
        transform: translateY(calc(var(--jc-mouth) * 7px));
        transition: transform 0.06s linear;
    }
    @keyframes jc-lip-talk {
        from { transform: translateY(1px); }
        to   { transform: translateY(6px); }
    }
 
    /* ---------- Hair, bust breathing, rising motes ---------- */
    #jc-avatar-portrait .jc-hair-front {
        transform-box: view-box;
        transform-origin: 450px 100px;
        animation: jc-hair-sway 6.5s ease-in-out infinite;
    }
    @keyframes jc-hair-sway {
        0%, 100% { transform: rotate(0deg); }
        50%      { transform: rotate(0.6deg); }
    }
 
    #jc-avatar-portrait .jc-avatar-bust {
        animation: jc-bust-glow 5.6s ease-in-out infinite, jc-bust-breathe 4.8s ease-in-out infinite;
    }
    @keyframes jc-bust-breathe {
        0%, 100% { transform: translateY(0) scale(1); }
        50%      { transform: translateY(-2px) scale(1.004); }
    }
 
    #jc-avatar-portrait .jc-mote {
        opacity: 0;
        animation: jc-mote-rise var(--dur, 9s) linear var(--delay, 0s) infinite;
    }
    @keyframes jc-mote-rise {
        0%   { transform: translate(0, 0); opacity: 0; }
        15%  { opacity: 0.75; }
        100% { transform: translate(var(--dx, 0px), -460px); opacity: 0; }
    }
 
    /* Head tracking wrapper: JS sets the transform */
    #jc-avatar-portrait .jc-avatar-track {
        transform-box: view-box;
        transform-origin: 450px 430px;
    }
 
    @media (prefers-reduced-motion: reduce) {
        #jc-avatar-portrait .jc-mote,
        #jc-avatar-portrait .jc-hair-front,
        #jc-avatar-portrait .jc-avatar-bust { animation: none !important; }
    }
</style>
<style>
    #jarvis-avatar-wrap { --jc-wide: 1; --jc-smile: .35; --jc-brow: 0; --jc-level: 0; --jc-pupil: 1; }

    /* Mouth width (visemes) */
    #jc-avatar-portrait .jc-mouth {
        transform-box: fill-box;
        transform-origin: center;
        transform: scaleX(var(--jc-wide));
    }

    /* Smile creases fade with mood */
    #jc-avatar-portrait .jc-smile { stroke-opacity: calc(var(--jc-smile) * .8); }

    /* Brows: state pose stays on `transform`, live emphasis rides on `translate` */
    #jc-avatar-portrait .jc-brow {
        translate: 0 calc(var(--jc-brow) * -4px);
        transition: transform .45s cubic-bezier(.2, .8, .2, 1), translate .1s linear;
    }

    /* Pupil dilation */
    #jc-avatar-portrait .jc-pupil {
        transform-box: fill-box;
        transform-origin: center;
        scale: var(--jc-pupil);
    }

    /* Chest core reacts to speech energy (composes with its existing pulse) */
    #jc-avatar-portrait .jc-holo-core { scale: calc(1 + var(--jc-level) * .7); }

    /* Thinking bubbles */
    #jc-avatar-portrait .jc-think-bubbles circle {
        opacity: 0;
        transform-box: fill-box;
        transform-origin: center;
    }
    body:has(.ai-thinking) #jc-avatar-portrait .jc-think-bubbles circle {
        animation: jc-bubble 1.2s ease-in-out infinite;
    }
    body:has(.ai-thinking) #jc-avatar-portrait .jc-think-bubbles circle:nth-child(2) { animation-delay: .2s; }
    body:has(.ai-thinking) #jc-avatar-portrait .jc-think-bubbles circle:nth-child(3) { animation-delay: .4s; }
    @keyframes jc-bubble {
        0%, 100% { opacity: .2; transform: scale(.8); }
        50%      { opacity: 1;  transform: scale(1.2); }
    }

    /* Scan sweep: travels down the figure, then rests */
    #jc-avatar-portrait .jc-sweep { animation: jc-sweep 11s linear infinite; opacity: 0; }
    body:has(.ai-listening) #jc-avatar-portrait .jc-sweep { animation-duration: 4.5s; }
    @keyframes jc-sweep {
        0%   { transform: translateY(0);     opacity: 0; }
        6%   { opacity: 1; }
        55%  { transform: translateY(940px); opacity: 1; }
        62%, 100% { transform: translateY(940px); opacity: 0; }
    }

    /* Rare hologram glitch (also fires on wake) */
    #jc-avatar-portrait.jc-glitch { animation: jc-glitch .24s steps(4) 1; }
    @keyframes jc-glitch {
        0%   { translate: 0 0;   filter: drop-shadow(-3px 0 0 rgba(255, 90, 170, .55)) drop-shadow(3px 0 0 rgba(90, 255, 255, .55)); }
        25%  { translate: 7px 0; filter: drop-shadow(-6px 0 0 rgba(255, 90, 170, .55)) drop-shadow(6px 0 0 rgba(90, 255, 255, .55)); }
        50%  { translate: -6px 0; filter: drop-shadow(5px 0 0 rgba(255, 90, 170, .55)) drop-shadow(-5px 0 0 rgba(90, 255, 255, .55)); }
        75%  { translate: 3px 0; filter: drop-shadow(-2px 0 0 rgba(255, 90, 170, .4)) drop-shadow(2px 0 0 rgba(90, 255, 255, .4)); }
        100% { translate: 0 0;   filter: drop-shadow(0 0 28px rgba(25, 185, 255, .22)); }
    }

    @media (prefers-reduced-motion: reduce) {
        #jc-avatar-portrait .jc-sweep,
        #jc-avatar-portrait .jc-think-bubbles circle,
        #jc-avatar-portrait.jc-glitch { animation: none !important; }
    }
</style>
<style>
    /* Brushed-steel finish: cool blue-white bloom instead of the cyan wash */
    #jc-avatar-portrait {
        filter: drop-shadow(0 0 30px rgba(150, 205, 255, 0.24));
    }
    #jc-avatar-portrait .jc-avatar-head {
        filter: drop-shadow(0 6px 14px rgba(0, 10, 25, 0.45))
                drop-shadow(0 0 12px rgba(190, 225, 255, 0.16));
    }
    #jc-avatar-portrait .jc-face-highlight { opacity: .7; }
</style>
<style>
    /* Cyber android: dark brows and a cool cyan bloom (overrides the realistic-tone brow colour) */
    #jc-avatar-portrait .jc-brow { stroke: #1b1511; }
    #jc-avatar-portrait .jc-avatar-head {
        filter: drop-shadow(0 0 16px rgba(70, 190, 255, 0.30))
                drop-shadow(0 6px 14px rgba(0, 10, 25, 0.5));
    }
</style>
<style>
    /* Keep the legacy SVG in the DOM for existing state animations; Three.js draws the visible avatar. */
    #jc-avatar-portrait {
        top: 0;
        bottom: auto;
        width: min(92vw, 78vh, 900px);
        max-height: 100vh;
        opacity: 1;
        filter: drop-shadow(0 0 24px rgba(22, 160, 210, 0.18));
    }
    #jc-avatar-portrait > :not(defs):not(.jc-avatar-track) {
        display: none !important;
    }
    #jc-avatar-portrait .jc-avatar-track {
        transform-origin: 450px 660px;
    }
    #jc-avatar-portrait .jc-avatar-head {
        display: none !important;
    }
    @media (max-width: 640px) {
        #jc-avatar-portrait {
            top: 0;
            bottom: auto;
            width: min(94vw, 78vh, 760px);
            max-height: 100vh;
            opacity: 1;
        }
    }
</style>
<style>
    /* =====================================================================
       SETTINGS DRAWER (shared form controls)
       ===================================================================== */
    .jc-label { display:block; font-size:10px; text-transform:uppercase; letter-spacing:.2em; color:rgba(255,255,255,.4); }
    .jc-hint  { display:block; margin-top:4px; font-size:10px; line-height:1.5; color:rgba(255,255,255,.3); }
    .jc-field {
        margin-top:8px; width:100%; height:44px; border-radius:12px;
        border:1px solid rgba(255,255,255,.1); background:rgba(255,255,255,.03);
        padding:0 12px; font-size:14px; color:#fff; outline:none;
        transition:border-color .2s ease, box-shadow .2s ease;
    }
    textarea.jc-field { height:auto; padding:8px 12px; line-height:1.5; }
    .jc-field::placeholder { color:rgba(255,255,255,.2); }
    .jc-field:focus { border-color:rgba(255,255,255,.28); }
    .jc-field.is-invalid { border-color:rgba(248,113,113,.65); box-shadow:0 0 0 3px rgba(248,113,113,.12); }
    select.jc-field { color-scheme:dark; }
    select.jc-field option { background:#111827; color:#fff; }

    .jc-btn {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        height:36px; padding:0 14px; border-radius:12px; font-size:12px; font-weight:500;
        border:1px solid rgba(255,255,255,.1); background:rgba(255,255,255,.04);
        color:rgba(255,255,255,.7); transition:background .2s ease, color .2s ease, opacity .2s ease;
    }
    .jc-btn:hover:not(:disabled) { background:rgba(255,255,255,.09); color:#fff; }
    .jc-btn:disabled { opacity:.45; cursor:not-allowed; }
    .jc-btn-primary { background:rgba(52,211,153,.9); border-color:transparent; color:#04130d; }
    .jc-btn-primary:hover:not(:disabled) { background:#34d399; color:#04130d; }
    .jc-btn-danger { color:rgba(252,165,165,.8); }
    .jc-btn-danger:hover:not(:disabled) { background:rgba(248,113,113,.12); color:#fca5a5; }
    .jc-btn-sm { height:28px; padding:0 10px; font-size:10px; border-radius:8px; }

    .jc-tab {
        flex:1; padding:12px 8px; color:rgba(255,255,255,.4);
        border-bottom:2px solid transparent; transition:color .2s ease, border-color .2s ease;
    }
    .jc-tab:hover { color:rgba(255,255,255,.75); }
    .jc-tab[aria-selected="true"] { color:#fff; border-bottom-color:rgba(var(--jc-cyan), .7); }

    .jc-notice { border-radius:10px; padding:8px 12px; font-size:11px; line-height:1.5; }
    .jc-notice-warn { border:1px solid rgba(251,146,60,.25); background:rgba(251,146,60,.08); color:#fdba74; }
    .jc-notice-ok   { border:1px solid rgba(52,211,153,.2);  background:rgba(52,211,153,.06); color:#6ee7b7; }
    .jc-notice-err  { border:1px solid rgba(248,113,113,.2); background:rgba(248,113,113,.06); color:#fca5a5; }
    .jc-notice-info { border:1px solid rgba(255,255,255,.1); background:rgba(255,255,255,.03); color:rgba(255,255,255,.6); }

    /* A closed drawer is out of the tab order and hidden from assistive tech */
    #jc-settings-drawer { transition:transform .3s ease-out, visibility 0s linear .3s; }
    #jc-settings-drawer[inert] { visibility:hidden; }
    #jc-settings-drawer:not([inert]) { visibility:visible; transition-delay:0s; }
</style>
</head>

<body>

    <div id="jc-grid-bg" aria-hidden="true"></div>
    <canvas id="jc-network-bg" aria-hidden="true"></canvas>

    {{-- Full-screen particle landscape; Three.js animates the flowing wave field. --}}
    <div id="jarvis-avatar-wrap" aria-hidden="true">
        <canvas id="jc-avatar-3d"></canvas>
        <svg id="jc-avatar-portrait" viewBox="0 0 900 1350" role="presentation" focusable="false">
            <defs>
                <radialGradient id="jc-face-glass" cx="46%" cy="32%" r="80%">
                    <stop offset="0"   stop-color="#f6d9bd" />
                    <stop offset=".24" stop-color="#dfc9bc" />
                    <stop offset=".58" stop-color="#a88f88" />
                    <stop offset="1"   stop-color="#5f3524" />
                </radialGradient>
                <linearGradient id="jc-holo-stroke" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0"   stop-color="#ffffff" />
                    <stop offset=".5"  stop-color="#d4dee8" />
                    <stop offset="1"   stop-color="#7fb6f0" />
                </linearGradient>
                <linearGradient id="jc-holo-dim" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0" stop-color="#35cfff" stop-opacity=".08" />
                    <stop offset=".5" stop-color="#c1faff" stop-opacity=".72" />
                    <stop offset="1" stop-color="#278dff" stop-opacity=".08" />
                </linearGradient>
                <linearGradient id="jc-holo-body" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0"   stop-color="#dfe6ec" stop-opacity=".96" />
                    <stop offset=".35" stop-color="#a3afba" stop-opacity=".96" />
                    <stop offset=".7"  stop-color="#5f6b78" stop-opacity=".96" />
                    <stop offset="1"   stop-color="#2a333d" stop-opacity=".96" />
                </linearGradient>
                <radialGradient id="jc-holo-aura" cx="50%" cy="44%" r="54%">
                    <stop offset="0" stop-color="#cfe6ff" stop-opacity=".34" />
                    <stop offset=".48" stop-color="#5aa8f0" stop-opacity=".12" />
                    <stop offset="1" stop-color="#2f7fdc" stop-opacity="0" />
                </radialGradient>
                <linearGradient id="jc-holo-platform" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#65eaff" stop-opacity=".36" />
                    <stop offset=".22" stop-color="#0d74ba" stop-opacity=".56" />
                    <stop offset="1" stop-color="#04152b" stop-opacity=".95" />
                </linearGradient>
                <linearGradient id="jc-face-highlight" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0"   stop-color="#ff8a35" stop-opacity=".3" />
                    <stop offset=".16" stop-color="#ffb15a" stop-opacity=".1" />
                    <stop offset=".43" stop-color="#fffaf4" stop-opacity=".08" />
                    <stop offset=".7"  stop-color="#55eaff" stop-opacity=".09" />
                    <stop offset="1"   stop-color="#28cfff" stop-opacity=".3" />
                </linearGradient>
                <pattern id="jc-holo-scanlines" width="5" height="8" patternUnits="userSpaceOnUse">
                    <path d="M0 1 H5" stroke="#d7ffff" stroke-opacity=".22" stroke-width=".7" />
                </pattern>
        
                {{-- Brushed steel: fine horizontal grain (a pattern, not feTurbulence, so the
                     animated head group never forces a filter re-rasterise) --}}
                <pattern id="jc-brushed" width="64" height="7" patternUnits="userSpaceOnUse">
                    <g stroke="#ffffff" stroke-linecap="round" fill="none">
                        <path d="M0 .6 H41"    stroke-opacity=".20" stroke-width=".5" />
                        <path d="M18 1.7 H64"  stroke-opacity=".09" stroke-width=".5" />
                        <path d="M0 2.8 H29"   stroke-opacity=".24" stroke-width=".4" />
                        <path d="M33 3.9 H64"  stroke-opacity=".12" stroke-width=".5" />
                        <path d="M6 5.0 H52"   stroke-opacity=".17" stroke-width=".4" />
                        <path d="M0 6.2 H22"   stroke-opacity=".10" stroke-width=".5" />
                    </g>
                    <g stroke="#1a2430" stroke-linecap="round" fill="none">
                        <path d="M10 1.2 H58"  stroke-opacity=".10" stroke-width=".4" />
                        <path d="M0 4.4 H38"   stroke-opacity=".12" stroke-width=".4" />
                    </g>
                </pattern>

                {{-- Panel seams that follow the face contours --}}
                <g id="jc-seams" fill="none" stroke-linecap="round">
                    <path d="M340 200 Q450 172 560 200" />
                    <path d="M450 150 V236" />
                    <path d="M350 193 Q333 265 356 320" />
                    <path d="M550 193 Q567 265 544 320" />
                    <path d="M364 300 Q383 340 420 372" />
                    <path d="M536 300 Q517 340 480 372" />
                    <path d="M372 366 Q450 396 528 366" />
                </g>

                {{-- Skin tone: neck shading --}}
                <linearGradient id="jc-skin-neck" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0"   stop-color="#7d5f57" />
                    <stop offset=".22" stop-color="#bb9481" />
                    <stop offset=".46" stop-color="#d4b09d" />
                    <stop offset=".72" stop-color="#bb9481" />
                    <stop offset="1"   stop-color="#7d5f57" />
                </linearGradient>
                <linearGradient id="jc-skin-neck-shade" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0"   stop-color="#3a2c2c" stop-opacity=".6" />
                    <stop offset=".3"  stop-color="#3a2c2c" stop-opacity=".26" />
                    <stop offset="1"   stop-color="#3a2c2c" stop-opacity=".06" />
                </linearGradient>

                {{-- Realistic skin: roundness vignette + fine pore texture --}}
                <radialGradient id="jc-real-vig" cx="50%" cy="40%" r="62%">
                    <stop offset="0"   stop-color="#5f3524" stop-opacity="0" />
                    <stop offset=".55" stop-color="#5f3524" stop-opacity="0" />
                    <stop offset=".82" stop-color="#5f3524" stop-opacity=".22" />
                    <stop offset="1"   stop-color="#5f3524" stop-opacity=".5" />
                </radialGradient>
                <pattern id="jc-pores" width="17" height="13" patternUnits="userSpaceOnUse">
                    <circle cx="3"  cy="4"  r=".55" fill="#000" opacity=".22" />
                    <circle cx="11" cy="2"  r=".45" fill="#000" opacity=".16" />
                    <circle cx="8"  cy="9"  r=".5"  fill="#000" opacity=".2" />
                    <circle cx="15" cy="11" r=".4"  fill="#000" opacity=".14" />
                </pattern>

                {{-- Cyber android: eye glow and translucent circuit plate --}}
                <radialGradient id="jc-eye-glow" cx="50%" cy="50%" r="50%">
                    <stop offset="0"  stop-color="#8fe8ff" stop-opacity=".5" />
                    <stop offset=".5" stop-color="#3aa8ff" stop-opacity=".18" />
                    <stop offset="1"  stop-color="#3aa8ff" stop-opacity="0" />
                </radialGradient>
                <linearGradient id="jc-plate-glass" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#9ff0ff" stop-opacity=".24" />
                    <stop offset="1" stop-color="#3ab8ff" stop-opacity=".08" />
                </linearGradient>

                {{-- New: iris, cheeks, lips, hair --}}
                <radialGradient id="jc-iris" cx="50%" cy="50%" r="50%">
                    <stop offset="0"   stop-color="#eaffff" />
                    <stop offset=".3"  stop-color="#5fd6ff" />
                    <stop offset=".62" stop-color="#1b8cff" />
                    <stop offset=".86" stop-color="#0a52c9" />
                    <stop offset="1"   stop-color="#04246b" />
                </radialGradient>
                <radialGradient id="jc-cheek" cx="50%" cy="50%" r="50%">
                    <stop offset="0"  stop-color="#d9948f" stop-opacity=".2" />
                    <stop offset=".5" stop-color="#d9948f" stop-opacity=".09" />
                    <stop offset="1"  stop-color="#d9948f" stop-opacity="0" />
                </radialGradient>
                <linearGradient id="jc-lip" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0"  stop-color="#cc9088" stop-opacity=".97" />
                    <stop offset=".5" stop-color="#c08078" stop-opacity=".92" />
                    <stop offset="1"  stop-color="#9f6a66" stop-opacity=".95" />
                </linearGradient>
                <linearGradient id="jc-hair" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0"  stop-color="#3a4250" stop-opacity=".98" />
                    <stop offset=".5" stop-color="#12161d" stop-opacity=".98" />
                    <stop offset="1"  stop-color="#07090c" stop-opacity=".95" />
                </linearGradient>
                <linearGradient id="jc-hair-line" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#8fa3c2" stop-opacity=".7" />
                    <stop offset="1" stop-color="#3b4a63" stop-opacity=".15" />
                </linearGradient>
        
                <clipPath id="jc-face-clip">
                    <path d="M450 88 C392 88 352 126 345 188 C341 224 341 260 348 292 C355 326 370 355 391 378 C407 396 427 411 441 419 C445 422 448 424 450 425 C452 424 455 422 459 419 C473 411 493 396 509 378 C530 355 545 326 552 292 C559 260 559 224 555 188 C548 126 508 88 450 88Z" />
                </clipPath>
                <clipPath id="jc-eye-l-clip"><path d="M365 267 Q390 250 417 266 Q395 286 365 267Z" /></clipPath>
                <clipPath id="jc-eye-r-clip"><path d="M483 266 Q510 250 535 267 Q505 286 483 266Z" /></clipPath>
                <clipPath id="jc-bust-clip">
                    <path d="M397 397 L397 458 C345 466 292 480 251 513 C207 548 181 610 166 690 C160 722 179 745 218 753 C282 767 360 776 450 776 C540 776 618 767 682 753 C721 745 740 722 734 690 C719 610 693 548 649 513 C608 480 555 466 503 458 L503 397Z" />
                </clipPath>
            </defs>
        
            <ellipse cx="450" cy="394" rx="350" ry="384" fill="url(#jc-holo-aura)" />
        
            {{-- Voice halo: a 270-degree ring of ticks. JS fills #jc-halo-ticks every frame. --}}
            <path class="jc-halo-guide" d="M270.4 434.6 A255 255 0 1 1 629.6 434.6" fill="none" stroke-width="1" stroke-dasharray="2 9" />
            <path id="jc-halo-ticks" class="jc-halo-ticks" fill="none" stroke-width="2.2" stroke-linecap="round" />
        
            {{-- Fine holographic cage --}}
            <g class="jc-avatar-orbit" fill="none" stroke="url(#jc-holo-dim)" stroke-width="1.15" opacity=".62">
                <path d="M450 44 L563 81 L628 154 L648 264 L620 370 L560 435 L450 469 L340 435 L280 370 L252 264 L272 154 L337 81Z" />
                <path d="M450 44 L450 139 L563 81 L531 173 L628 154 L553 245 L648 264 L553 302 L620 370 L523 353 L560 435 L477 378 L450 469 L423 378 L340 435 L377 353 L280 370 L347 302 L252 264 L347 245 L272 154 L369 173 L337 81Z" />
                <path d="M337 81 L369 173 L347 245 L377 353 L423 378 M563 81 L531 173 L553 245 L523 353 L477 378 M272 154 L369 173 L450 139 L531 173 L628 154 M252 264 L347 245 L450 265 L553 245 L648 264" />
                <circle cx="450" cy="265" r="205" stroke-dasharray="2 12" opacity=".58" />
                <circle cx="450" cy="265" r="228" stroke-dasharray="1 16" opacity=".44" />
                <path d="M450 46 V17 M252 264 H220 M648 264 H680 M450 468 V503" stroke-width="2" />
                <circle class="jc-avatar-node" cx="450" cy="44" r="4" fill="#d8ffff" />
                <circle class="jc-avatar-node" cx="648" cy="264" r="3.5" fill="#9af4ff" />
                <circle class="jc-avatar-node" cx="280" cy="370" r="3" fill="#9af4ff" />
                <circle class="jc-avatar-node" cx="563" cy="81" r="3" fill="#d8ffff" />
            </g>
        
            {{-- Side readouts, pushed outward so the halo has room --}}
            <g transform="translate(-34 0)" fill="none" stroke="#6feaff" stroke-opacity=".4" stroke-width="1.2">
                <path d="M114 268 H239 V424 H128 V307 H94 V199 H181" />
                <path d="M125 286 H220 M125 296 H201 M125 306 H211 M125 316 H186 M125 397 H219" />
                <rect x="125" y="329" width="94" height="55" rx="2" fill="#0a74ac" fill-opacity=".1" />
                <path d="M132 370 L146 357 L157 363 L171 342 L184 351 L207 335" />
                <path class="jc-hud-line" d="M87 233 H177 M114 448 H205" />
                <circle class="jc-avatar-node" cx="94" cy="199" r="3" fill="#b8f9ff" />
            </g>
            <g transform="translate(34 0)" fill="none" stroke="#6feaff" stroke-opacity=".4" stroke-width="1.2">
                <path d="M786 263 H658 V429 H770 V304 H806 V192 H719" />
                <path d="M680 282 H759 M680 292 H735 M680 302 H751 M680 312 H721 M680 401 H759" />
                <rect x="681" y="323" width="78" height="64" rx="2" fill="#0a74ac" fill-opacity=".1" />
                <circle cx="720" cy="355" r="20" />
                <circle cx="720" cy="355" r="12" stroke-dasharray="3 4" />
                <path d="M720 335 V343 M740 355 H732 M720 375 V367 M700 355 H708" />
                <path class="jc-hud-line" d="M723 225 H812 M697 453 H787" />
                <circle class="jc-avatar-node" cx="806" cy="192" r="3" fill="#b8f9ff" />
            </g>
        
            {{-- Bust --}}
            <g class="jc-avatar-bust">
                <path d="M397 397 L397 458 C345 466 292 480 251 513 C207 548 181 610 166 690 C160 722 179 745 218 753 C282 767 360 776 450 776 C540 776 618 767 682 753 C721 745 740 722 734 690 C719 610 693 548 649 513 C608 480 555 466 503 458 L503 397Z" fill="url(#jc-holo-body)" stroke="url(#jc-holo-stroke)" stroke-width="2.2" />
                <g clip-path="url(#jc-bust-clip)" fill="none" stroke="#0e1822" stroke-width="1" opacity=".3">
                    <path d="M183 589 L279 530 L345 572 L407 493 L450 541 L511 493 L555 572 L621 530 L717 589 M174 658 L271 611 L339 655 L407 584 L450 618 L493 584 L561 655 L629 611 L726 658 M210 731 L299 676 L371 718 L450 652 L529 718 L601 676 L690 731" />
                    <path d="M279 530 L271 611 L299 676 M345 572 L339 655 L371 718 M407 493 L407 584 L450 652 M450 541 V618 M511 493 L493 584 L450 652 M555 572 L561 655 L529 718 M621 530 L629 611 L601 676" />
                    <path d="M234 505 C273 573 272 647 241 738 M310 478 C335 563 334 655 313 760 M385 464 C397 550 397 656 390 773 M515 464 C503 550 503 656 510 773 M590 478 C565 563 566 655 587 760 M666 505 C627 573 628 647 659 738" opacity=".44" />
                </g>
                <rect x="150" y="440" width="600" height="340" clip-path="url(#jc-bust-clip)" fill="url(#jc-holo-scanlines)" opacity=".08" />
                <rect x="150" y="440" width="600" height="340" clip-path="url(#jc-bust-clip)" fill="url(#jc-brushed)" style="mix-blend-mode:soft-light" opacity=".8" />
                <path d="M397 397 L397 458 C345 466 292 480 251 513 C207 548 181 610 166 690 C160 722 179 745 218 753 C282 767 360 776 450 776 C540 776 618 767 682 753 C721 745 740 722 734 690 C719 610 693 548 649 513 C608 480 555 466 503 458 L503 397Z" fill="none" stroke="#d4ffff" stroke-opacity=".24" stroke-width="7" />
                <circle cx="450" cy="607" r="25" fill="#36cfff" fill-opacity=".05" stroke="#82f2ff" stroke-opacity=".62" stroke-width="1.2" stroke-dasharray="3 6" />
                <circle cx="450" cy="607" r="17" fill="none" stroke="#bdfaff" stroke-opacity=".35" stroke-width="1" />
                <g fill="#d8ffff">
                    <circle class="jc-holo-node" cx="279" cy="530" r="3" />
                    <circle class="jc-holo-node" cx="407" cy="493" r="3.2" />
                    <circle class="jc-holo-node" cx="511" cy="493" r="3.2" />
                    <circle class="jc-holo-node" cx="621" cy="530" r="3" />
                    <circle class="jc-holo-node" cx="339" cy="655" r="2.5" />
                    <circle class="jc-holo-node" cx="561" cy="655" r="2.5" />
                    <circle class="jc-holo-node" cx="220" cy="633" r="2" />
                    <circle class="jc-holo-node" cx="270" cy="702" r="2.2" />
                    <circle class="jc-holo-node" cx="319" cy="576" r="1.8" />
                    <circle class="jc-holo-node" cx="366" cy="693" r="2" />
                    <circle class="jc-holo-node" cx="450" cy="541" r="2.2" />
                    <circle class="jc-holo-node" cx="450" cy="718" r="2" />
                    <circle class="jc-holo-node" cx="534" cy="693" r="2" />
                    <circle class="jc-holo-node" cx="581" cy="576" r="1.8" />
                    <circle class="jc-holo-node" cx="630" cy="702" r="2.2" />
                    <circle class="jc-holo-node" cx="680" cy="633" r="2" />
                    <circle class="jc-holo-core" cx="450" cy="607" r="8" />
                </g>
                <path d="M397 438 Q450 469 503 438 M398 458 Q450 486 502 458 M450 616 V756 M270 546 L307 574 L289 620 M630 546 L593 574 L611 620" fill="none" stroke="url(#jc-holo-dim)" stroke-width="1.5" opacity=".7" />
            </g>
        
            {{-- Neck (skin tone) --}}
            <path d="M397 397 L397 458 Q450 486 503 458 L503 397Z" fill="url(#jc-skin-neck)" stroke="none" />
            <path d="M397 397 L397 458 Q450 486 503 458 L503 397Z" fill="url(#jc-skin-neck-shade)" stroke="none" />
            <path d="M397 397 L397 458 Q450 486 503 458 L503 397Z" fill="none" stroke="url(#jc-holo-stroke)" stroke-opacity=".55" stroke-width="1.4" />

            {{-- Cyber neck --}}
            <path d="M424 418 H476 L472 466 Q450 476 428 466Z" fill="#0b1118" fill-opacity=".92" stroke="none" />
            <path d="M410 420 Q406 446 412 468" fill="none" stroke="#0b1118" stroke-width="3.2" stroke-linecap="round" />
            <path d="M490 420Q494 446 488 468" fill="none" stroke="#0b1118" stroke-width="3.2" stroke-linecap="round" />
            <g fill="none" stroke-linecap="round" style="stroke:rgb(var(--jc-accent))">
                <path d="M429 434 H471" stroke-width="1.6" stroke-opacity=".85" />
                <path d="M429 446 H471" stroke-width="1.6" stroke-opacity=".85" />
                <path d="M430 458 H470" stroke-width="1.6" stroke-opacity=".85" />
                <path d="M450 420 V470" stroke-width="1" stroke-opacity=".5" />
                <path d="M410 420 Q406 446 412 468" stroke-width="1" stroke-opacity=".8" />
                <path d="M490 420Q494 446 488 468" stroke-width="1" stroke-opacity=".8" />
                <path d="M404 452 Q450 478 496 452" stroke-width="1.6" stroke-opacity=".7" />
            </g>

            {{-- Head. The outer group is moved by JS (cursor tracking); the inner group keeps the CSS breathing/nodding. --}}
            <g class="jc-avatar-track" id="jc-avatar-track">
                <g class="jc-avatar-head" stroke="url(#jc-holo-stroke)" stroke-linecap="round" stroke-linejoin="round">
        
                    {{-- Hair, back layer: frames the jaw --}}
                    <path d="M336 236 C322 128 378 70 450 70 C522 70 578 128 564 236 C562 252 558 262 554 270 L346 270 C342 262 338 252 336 236Z" fill="url(#jc-hair)" stroke-opacity=".45" stroke-width="1.4" />
        
                    {{-- Ears and earpieces --}}
                    <path d="M343 252 C326 244 317 262 319 284 C321 302 334 308 345 298Z" fill="url(#jc-face-glass)" stroke="none" />
                    <path d="M557 252C574 244 583 262 581 284C579 302 566 308 555 298Z" fill="url(#jc-face-glass)" stroke="none" />
                    <path d="M334 262 C321 262 315 276 318 292 C320 304 331 306 338 298Z" fill="#0d131b" stroke-width="1.2" style="stroke:rgb(var(--jc-accent))" />
                    <path d="M566 262C579 262 585 276 582 292C580 304 569 306 562 298Z" fill="#0d131b" stroke-width="1.2" style="stroke:rgb(var(--jc-accent))" />
                    <path d="M322 300 C324 316 334 326 350 332" fill="none" stroke="#0d131b" stroke-width="2.4" />
                    <path d="M578 300C576 316 566 326 550 332" fill="none" stroke="#0d131b" stroke-width="2.4" />
                    <circle cx="326" cy="283" r="2.2" stroke="none" style="fill:rgb(var(--jc-accent))" />
                    <circle cx="574" cy="283" r="2.2" stroke="none" style="fill:rgb(var(--jc-accent))" />

                    {{-- Face --}}
                    <path d="M450 88 C392 88 352 126 345 188 C341 224 341 260 348 292 C355 326 370 355 391 378 C407 396 427 411 441 419 C445 422 448 424 450 425 C452 424 455 422 459 419 C473 411 493 396 509 378 C530 355 545 326 552 292 C559 260 559 224 555 188 C548 126 508 88 450 88Z" fill="url(#jc-face-glass)" stroke-width="2.4" />
                    <g clip-path="url(#jc-face-clip)" fill="none" stroke="#724a33" stroke-width="1" opacity=".1">
                        <path d="M318 157 L375 190 L450 140 L525 190 L582 157 M314 217 L375 235 L450 202 L525 235 L586 217 M315 278 L371 266 L450 302 L529 266 L585 278 M329 338 L392 316 L450 352 L508 316 L571 338 M360 382 L414 367 L450 398 L486 367 L540 382" />
                        <path d="M375 190 L375 235 L371 266 L392 316 L414 367 M450 140 L450 202 L450 302 L450 352 L450 398 M525 190 L525 235 L529 266 L508 316 L486 367 M318 157 L314 217 L315 278 L329 338 L360 382 M582 157 L586 217 L585 278 L571 338 L540 382" />
                        <path d="M331 186 Q450 229 569 186 M325 304 Q450 346 575 304" opacity=".55" />
                    </g>
                    <path d="M450 88 C392 88 352 126 345 188 C341 224 341 260 348 292 C355 326 370 355 391 378 C407 396 427 411 441 419 C445 422 448 424 450 425 C452 424 455 422 459 419 C473 411 493 396 509 378 C530 355 545 326 552 292 C559 260 559 224 555 188 C548 126 508 88 450 88Z" fill="url(#jc-face-highlight)" class="jc-face-highlight" stroke="none" />
                    <rect x="315" y="90" width="270" height="340" clip-path="url(#jc-face-clip)" fill="url(#jc-holo-scanlines)" opacity=".07" />

                    <rect x="315" y="90" width="270" height="340" clip-path="url(#jc-face-clip)" fill="url(#jc-brushed)" style="mix-blend-mode:soft-light" opacity=".22" />

                    <g clip-path="url(#jc-face-clip)">
                        <use href="#jc-seams" stroke="#724a33" stroke-opacity=".28" stroke-width="1" />
                        <use href="#jc-seams" transform="translate(0 1.3)" stroke="#ffffff" stroke-opacity=".1" stroke-width=".8" />
                    </g>

                    <g clip-path="url(#jc-face-clip)" stroke="none" style="mix-blend-mode:screen">
                        <ellipse cx="450" cy="138" rx="60" ry="16" fill="#ffffff" opacity=".16" />
                        <ellipse cx="450" cy="300" rx="4" ry="26" fill="#ffffff" opacity=".1" />
                        <ellipse cx="386" cy="314" rx="22" ry="8" fill="#ffffff" opacity=".1" transform="rotate(-20 386 314)" />
                        <ellipse cx="514" cy="314" rx="22" ry="8" fill="#ffffff" opacity=".1" transform="rotate(20 514 314)" />
                        <ellipse cx="450" cy="408" rx="26" ry="6"  fill="#dff0ff" opacity=".1" />
                        <path d="M346 240 Q343 290 360 336" stroke="#9fd2ff" stroke-opacity=".4" stroke-width="3" fill="none" />
                        <path d="M554 240 Q557 290 540 336" stroke="#9fd2ff" stroke-opacity=".4" stroke-width="3" fill="none" />
                    </g>
                    {{-- Realistic skin shading --}}
                    <g clip-path="url(#jc-face-clip)" stroke="none">
                        <rect x="315" y="90" width="270" height="340" fill="url(#jc-real-vig)" />
                        <rect x="315" y="90" width="270" height="340" fill="url(#jc-pores)" style="mix-blend-mode:soft-light" opacity=".5" />
                        <ellipse cx="450" cy="132" rx="70" ry="28" fill="#f6d9bd" opacity=".22" />
                        <ellipse cx="391" cy="246" rx="30" ry="9" fill="#5f3524" opacity=".1" />
                        <ellipse cx="509" cy="246" rx="30" ry="9" fill="#5f3524" opacity=".1" />
                        <ellipse cx="391" cy="288" rx="24" ry="7" fill="#5f3524" opacity=".13" />
                        <ellipse cx="509" cy="288" rx="24" ry="7" fill="#5f3524" opacity=".13" />
                        <ellipse cx="441" cy="300" rx="4" ry="28" fill="#5f3524" opacity=".06" />
                        <ellipse cx="459" cy="300" rx="4" ry="28" fill="#5f3524" opacity=".06" />
                        <ellipse cx="450" cy="326" rx="15" ry="10" fill="#cf7f6d" opacity=".28" />
                        <ellipse cx="450" cy="357" rx="8"  ry="8"  fill="#5f3524" opacity=".08" />
                        <ellipse cx="450" cy="413" rx="24" ry="7"  fill="#5f3524" opacity=".16" />
                    </g>
                    {{-- Face structure: cheekbone light and soft contour under the cheekbones, mirrored --}}
                    <g clip-path="url(#jc-face-clip)" stroke="none">
                        <ellipse cx="372" cy="352" rx="24" ry="12" fill="#5f3524" opacity=".12" transform="rotate(38 372 352)" />
                        <ellipse cx="528" cy="352" rx="24" ry="12" fill="#5f3524" opacity=".12" transform="rotate(-38 528 352)" />
                        <ellipse cx="386" cy="296" rx="22" ry="8" fill="#f6d9bd" opacity=".14" transform="rotate(-20 386 296)" />
                        <ellipse cx="514" cy="296" rx="22" ry="8" fill="#f6d9bd" opacity=".14" transform="rotate(20 514 296)" />
                    </g>
                    <ellipse cx="388" cy="320" rx="30" ry="19" fill="url(#jc-cheek)" stroke="none" />
                    <ellipse cx="512" cy="320" rx="30" ry="19" fill="url(#jc-cheek)" stroke="none" />
                    <path d="M350 193 Q333 265 356 320 M550 193 Q567 265 544 320 M372 366 Q450 396 528 366" fill="none" stroke="#d8ffff" stroke-opacity=".3" stroke-width="1.4" />
                    <circle class="jc-holo-node" cx="341" cy="284" r="2.4" fill="#d8ffff" stroke="none" />
                    <circle class="jc-holo-node" cx="559" cy="284" r="2.4" fill="#d8ffff" stroke="none" />
        
                    {{-- Translucent circuit plate (forehead and temples) --}}
                    <g id="jc-circuit-plate" clip-path="url(#jc-face-clip)" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M350 214 C348 164 392 126 450 126 C508 126 552 164 550 214 C520 204 486 200 450 200 C414 200 380 204 350 214Z" fill="url(#jc-plate-glass)" stroke="none" />
                        <path d="M350 214 C346 250 348 290 360 322 L370 318 C360 288 358 252 362 220Z" fill="url(#jc-plate-glass)" stroke="none" />
                        <path d="M550 214C554 250 552 290 540 322L530 318C540 288 542 252 538 220Z" fill="url(#jc-plate-glass)" stroke="none" />
                        <path d="M350 214 C348 164 392 126 450 126 C508 126 552 164 550 214 C520 204 486 200 450 200 C414 200 380 204 350 214Z" stroke-width="5" stroke-opacity=".16" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M350 214 C348 164 392 126 450 126 C508 126 552 164 550 214 C520 204 486 200 450 200 C414 200 380 204 350 214Z" stroke-width="1.4" stroke-opacity=".9" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M350 214 C346 250 348 290 360 322 L370 318 C360 288 358 252 362 220Z" stroke-width="1.2" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M550 214C554 250 552 290 540 322L530 318C540 288 542 252 538 220Z" stroke-width="1.2" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M450 150 H420 L410 160 H384" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M450 150H480L490 160H516" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M450 168 H436 L426 178 H398 L390 186 H372" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M450 168H464L474 178H502L510 186H528" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M450 186 H442 L436 192 H412" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M450 186H458L464 192H488" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M382 160 V172 L374 180" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M518 160V172 L526 180" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M366 204 H396 L404 196 H430" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M534 204H504L496 196H470" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M400 140 H430 L438 148" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M500 140H470L462 148" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M372 152 L384 140 H404" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M528 152L516 140H496" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M356 232 V262 L362 270 V300 L368 308" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M544 232V262 L538 270V300 L532 308" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M366 226 V248" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M534 226V248" stroke-width="1.1" stroke-opacity=".85" style="stroke:rgb(var(--jc-accent))" />
                        <path d="M450 150 H420 L410 160 H384" class="jc-hud-line" stroke="#eaffff" stroke-width="1.6" stroke-opacity=".9" />
                        <path d="M450 150H480L490 160H516" class="jc-hud-line" stroke="#eaffff" stroke-width="1.6" stroke-opacity=".9" />
                        <path d="M450 168 H436 L426 178 H398 L390 186 H372" class="jc-hud-line" stroke="#eaffff" stroke-width="1.6" stroke-opacity=".9" />
                        <path d="M450 168H464L474 178H502L510 186H528" class="jc-hud-line" stroke="#eaffff" stroke-width="1.6" stroke-opacity=".9" />
                        <path d="M356 232 V262 L362 270 V300 L368 308" class="jc-hud-line" stroke="#eaffff" stroke-width="1.6" stroke-opacity=".9" />
                        <path d="M544 232V262 L538 270V300 L532 308" class="jc-hud-line" stroke="#eaffff" stroke-width="1.6" stroke-opacity=".9" />
                        <g stroke="none">
                        <circle cx="384" cy="160" r="1.9" style="fill:rgb(var(--jc-accent))" /><circle cx="516" cy="160" r="1.9" style="fill:rgb(var(--jc-accent))" />
                        <circle cx="372" cy="186" r="1.9" style="fill:rgb(var(--jc-accent))" /><circle cx="528" cy="186" r="1.9" style="fill:rgb(var(--jc-accent))" />
                        <circle cx="412" cy="192" r="1.9" style="fill:rgb(var(--jc-accent))" /><circle cx="488" cy="192" r="1.9" style="fill:rgb(var(--jc-accent))" />
                        <circle cx="430" cy="196" r="1.9" style="fill:rgb(var(--jc-accent))" /><circle cx="470" cy="196" r="1.9" style="fill:rgb(var(--jc-accent))" />
                        <circle cx="438" cy="148" r="1.9" style="fill:rgb(var(--jc-accent))" /><circle cx="462" cy="148" r="1.9" style="fill:rgb(var(--jc-accent))" />
                        <circle cx="404" cy="140" r="1.9" style="fill:rgb(var(--jc-accent))" /><circle cx="496" cy="140" r="1.9" style="fill:rgb(var(--jc-accent))" />
                        <circle cx="374" cy="180" r="1.9" style="fill:rgb(var(--jc-accent))" /><circle cx="526" cy="180" r="1.9" style="fill:rgb(var(--jc-accent))" />
                        <circle cx="356" cy="232" r="1.9" style="fill:rgb(var(--jc-accent))" /><circle cx="544" cy="232" r="1.9" style="fill:rgb(var(--jc-accent))" />
                        <circle cx="368" cy="308" r="1.9" style="fill:rgb(var(--jc-accent))" /><circle cx="532" cy="308" r="1.9" style="fill:rgb(var(--jc-accent))" />
                        <circle cx="366" cy="248" r="1.9" style="fill:rgb(var(--jc-accent))" /><circle cx="534" cy="248" r="1.9" style="fill:rgb(var(--jc-accent))" />
                        </g>
                    </g>

                    {{-- Split-spectrum cybernetic detail: amber left rim, cyan right-side circuitry --}}
                    <g id="jc-face-cyber-etch" clip-path="url(#jc-face-clip)" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M350 226 V254 L359 263 V288 L371 300 V321 L384 334" stroke="#ff7a2f" stroke-width="7" stroke-opacity=".08" />
                        <path d="M350 226 V254 L359 263 V288 L371 300 V321 L384 334" stroke="#ff9a45" stroke-width="1.25" stroke-opacity=".82" />
                        <path d="M356 236 H370 L378 244 V260 M362 294 H382 L392 304 V322 L405 335" stroke="#ffb15a" stroke-width="1" stroke-opacity=".68" />
                        <path d="M544 218 V242 L536 250 V278 L526 288 V309 L514 321 V342 L497 356" stroke="#36dfff" stroke-width="7" stroke-opacity=".1" />
                        <path d="M544 218 V242 L536 250 V278 L526 288 V309 L514 321 V342 L497 356" stroke="#56e9ff" stroke-width="1.35" stroke-opacity=".92" />
                        <path d="M538 232 H526 L518 240 V258 L508 268 V286 H494 L486 294 V310" stroke="#35d7ff" stroke-width="1.1" stroke-opacity=".82" />
                        <path d="M530 294 H516 L507 303 V318 H491 L482 327 V346 L470 358" stroke="#9af5ff" stroke-width="1" stroke-opacity=".72" />
                        <path d="M535 262 H520 L514 268 M520 309 H532 M492 338 H506" stroke="#31cfff" stroke-width=".9" stroke-opacity=".72" />
                        <g stroke="none">
                            <circle cx="359" cy="263" r="2.1" fill="#ffb15a" />
                            <circle cx="371" cy="300" r="2" fill="#ff8c3c" />
                            <circle cx="382" cy="260" r="1.6" fill="#ffd19a" />
                            <circle cx="536" cy="250" r="2" fill="#8af4ff" />
                            <circle cx="526" cy="288" r="2.1" fill="#35dfff" />
                            <circle cx="514" cy="321" r="2" fill="#b5faff" />
                            <circle cx="491" cy="346" r="1.7" fill="#35dfff" />
                        </g>
                    </g>

                    {{-- Hair, front layer: swept fringe with light strands --}}
                    <g class="jc-hair-front">
                        <path d="M338 236 C328 150 384 96 450 92 C516 96 572 150 562 236 C556 190 520 134 450 128 C380 134 344 190 338 236Z" fill="url(#jc-hair)" fill-opacity=".9" stroke-opacity=".55" stroke-width="1.6" />
                        <g fill="none" stroke="url(#jc-hair-line)" stroke-width="1.1" opacity=".8">
                            <path d="M450 128 V96" />
                            <path d="M420 132 C418 118 424 104 432 94" />
                            <path d="M392 148 C388 130 398 112 412 98" />
                            <path d="M362 178 C356 150 372 122 392 104" />
                            <path d="M480 132C482 118 476 104 468 94" />
                            <path d="M508 148C512 130 502 112 488 98" />
                            <path d="M538 178C544 150 528 122 508 104" />
                        </g>
                    </g>
        
                    {{-- Brows --}}
                    <path class="jc-brow jc-brow-l" d="M352 243 Q385 222 419 240" fill="none" stroke-width="3.4" opacity=".88" />
                    <path class="jc-brow jc-brow-r" d="M481 240 Q515 222 548 243" fill="none" stroke-width="3.4" opacity=".88" />
        
                    {{-- Eyes: sclera, tracking iris, lash line. JS moves .jc-gaze-l / .jc-gaze-r --}}
                    <ellipse cx="391" cy="267" rx="36" ry="17" fill="url(#jc-eye-glow)" stroke="none" style="mix-blend-mode:screen" />
                    <ellipse cx="509" cy="267" rx="36" ry="17" fill="url(#jc-eye-glow)" stroke="none" style="mix-blend-mode:screen" />
                    <g class="jc-avatar-eyes">
                        <path d="M365 267 Q390 250 417 266 Q395 286 365 267Z M483 266 Q510 250 535 267 Q505 286 483 266Z" fill="#f4f1ee" fill-opacity=".92" stroke-width="1.6" />
                        <g clip-path="url(#jc-eye-l-clip)">
                            <g class="jc-gaze jc-gaze-l">
                                <circle cx="391" cy="267" r="11" fill="url(#jc-iris)" stroke="none" />
                                <circle class="jc-iris-rim" cx="391" cy="267" r="11" fill="none" stroke-width="1.4" />
                                <circle cx="391" cy="267" r="7.6" fill="none" stroke="#c9f6ff" stroke-opacity=".75" stroke-width=".7" stroke-dasharray="1.2 1.6" />
                                <circle class="jc-pupil" cx="391" cy="267" r="4.6" fill="#03152e" stroke="none" />
                                <circle cx="387.5" cy="263.5" r="2" fill="#ffffff" stroke="none" />
                            </g>
                        </g>
                        <g clip-path="url(#jc-eye-r-clip)">
                            <g class="jc-gaze jc-gaze-r">
                                <circle cx="509" cy="267" r="11" fill="url(#jc-iris)" stroke="none" />
                                <circle class="jc-iris-rim" cx="509" cy="267" r="11" fill="none" stroke-width="1.4" />
                                <circle cx="509" cy="267" r="7.6" fill="none" stroke="#c9f6ff" stroke-opacity=".75" stroke-width=".7" stroke-dasharray="1.2 1.6" />
                                <circle class="jc-pupil" cx="509" cy="267" r="4.6" fill="#03152e" stroke="none" />
                                <circle cx="505.5" cy="263.5" r="2" fill="#ffffff" stroke="none" />
                            </g>
                        </g>
                        <path d="M363 268 Q390 246 419 265 M481 265 Q510 246 537 268" fill="none" stroke="#241611" stroke-opacity=".9" stroke-width="2.6" />
                        <path d="M372 277 Q392 288 410 277 M490 277 Q508 288 528 277" fill="none" stroke-width="1" opacity=".45" />
                    </g>
        
                    {{-- Nose --}}
                    <path d="M450 272 L441 326 Q450 334 459 326 M434 338 Q450 348 466 338" fill="none" stroke="#5f3524" stroke-width="1.6" opacity=".5" />

                    {{-- Smile creases (opacity driven by --jc-smile) --}}
                    <path class="jc-smile" d="M406 368 Q396 380 404 394 M494 368 Q504 380 496 394" fill="none" stroke-width="1.3" stroke-linecap="round" />
        
                    {{-- Mouth: shadow, aperture, upper lip, lower lip (moves with speech) --}}
                    <g class="jc-mouth">
                    <path d="M416 381 Q450 373 484 381 Q450 397 416 381Z" fill="#051832" fill-opacity=".6" stroke="none" />
                    <ellipse class="jc-mouth-aperture" cx="450" cy="381" rx="27" ry="10" fill="#020b1a" stroke="#c5faff" stroke-width="1.4" />
                    <path d="M413 381 Q430 368 450 374 Q470 368 487 381 Q468 384 450 383 Q432 384 413 381Z" fill="url(#jc-lip)" stroke-width="1.4" />
                    <g class="jc-lip-lower">
                        <path d="M418 383 Q450 385 482 383 Q470 404 450 405 Q430 404 418 383Z" fill="url(#jc-lip)" fill-opacity=".85" stroke-width="1.4" />
                    </g>
                    <path d="M428 414 Q450 422 472 414" fill="none" stroke-width="1.2" opacity=".5" />
                    </g>
        
                    {{-- Scan line (listening) --}}
                    <g clip-path="url(#jc-face-clip)" class="jc-avatar-scan">
                        <path d="M285 0 H615 V3 H285Z" fill="#e1ffff" opacity=".8" />
                        <path d="M285 0 H615" stroke="#6cecff" stroke-width="14" opacity=".2" />
                    </g>
                </g>
            </g>
        
            {{-- Thinking cue --}}
            <g class="jc-think-bubbles" fill="#d8ffff" stroke="none">
                <circle cx="598" cy="152" r="2.6" />
                <circle cx="612" cy="133" r="3.8" />
                <circle cx="632" cy="108" r="5.4" />
            </g>

            {{-- Whole-figure scan sweep --}}
            <defs>
                <linearGradient id="jc-sweep-grad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#9ff3ff" stop-opacity="0" />
                    <stop offset=".85" stop-color="#9ff3ff" stop-opacity=".26" />
                    <stop offset="1" stop-color="#e6ffff" stop-opacity=".7" />
                </linearGradient>
                <linearGradient id="jc-sweep-fade" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0" stop-color="#000" />
                    <stop offset=".25" stop-color="#fff" />
                    <stop offset=".75" stop-color="#fff" />
                    <stop offset="1" stop-color="#000" />
                </linearGradient>
                <mask id="jc-sweep-mask" maskUnits="userSpaceOnUse" x="0" y="0" width="900" height="860">
                    <rect width="900" height="860" fill="url(#jc-sweep-fade)" />
                </mask>
            </defs>
            <g mask="url(#jc-sweep-mask)" aria-hidden="true">
                <rect class="jc-sweep" x="0" y="-80" width="900" height="80" fill="url(#jc-sweep-grad)" />
            </g>

            {{-- Listening gesture --}}
            <g class="jc-avatar-listen-gesture" fill="none" stroke="url(#jc-holo-stroke)" stroke-linecap="round">
                <path d="M311 225 Q276 266 311 307 M297 207 Q247 266 297 325 M589 225 Q624 266 589 307 M603 207 Q653 266 603 325" stroke-width="2.4" />
                <path d="M302 266 H287 M598 266 H613" stroke-width="2" />
                <circle cx="287" cy="266" r="3" fill="#d8ffff" stroke="none" />
                <circle cx="613" cy="266" r="3" fill="#d8ffff" stroke="none" />
            </g>
        
            {{-- Rising light motes (filled by JS) --}}
            <g id="jc-motes" fill="#bff8ff"></g>
        
            {{-- Projector pedestal and floor rings --}}
            <g fill="none" stroke-linecap="round">
                <path d="M0 819 H207 L296 786 M900 819 H693 L604 786 M0 843 H330 L382 797 M900 843 H570 L518 797" stroke="#238ed2" stroke-opacity=".35" stroke-width="1.3" />
                <ellipse cx="450" cy="807" rx="392" ry="29" stroke="#45d8ff" stroke-opacity=".72" stroke-width="2" />
                <ellipse cx="450" cy="807" rx="337" ry="22" stroke="#1586d2" stroke-opacity=".42" stroke-width="1.2" />
                <path d="M130 779 Q450 741 770 779 L747 806 Q450 839 153 806Z" fill="url(#jc-holo-platform)" stroke="#46ddff" stroke-width="1.6" />
                <ellipse cx="450" cy="777" rx="320" ry="35" fill="#06315c" fill-opacity=".72" stroke="#7cecff" stroke-width="2" />
                <ellipse cx="450" cy="775" rx="258" ry="23" stroke="#28cfff" stroke-width="4" />
                <ellipse cx="450" cy="775" rx="212" ry="14" stroke="#bcfaff" stroke-width="2.2" />
                <ellipse cx="450" cy="775" rx="148" ry="7" stroke="#28bbff" stroke-opacity=".7" stroke-width="1.4" />
                <path d="M450 779 V821 M430 778 V794 M470 778 V794" stroke="#8af0ff" stroke-opacity=".64" stroke-width="1.2" />
            </g>
        </svg>
    </div>
    <div id="jc-scrim" aria-hidden="true"></div>

    <div id="jc-app" class="flex h-screen w-full" data-test-mode="{{ config('jarvis.test_mode') ? 'true' : 'false' }}">

        {{-- Backdrop shown only while the inbox drawer is open --}}
        <div
            id="jc-inbox-backdrop"
            class="fixed inset-0 z-30 hidden bg-black/50 backdrop-blur-[2px]"
        ></div>

        {{-- LEFT: EMAIL PANEL (hidden by default, opens on demand; emails load lazily via JS) --}}
        <aside
            id="jc-inbox-drawer"
            class="jc-glass-strong fixed inset-y-0 left-0 z-40 flex w-[340px] max-w-full -translate-x-full flex-col border-r border-white/10 transition-transform duration-300 ease-out"
            aria-hidden="true"
        >

            {{-- Header --}}
            <div class="flex h-16 items-center justify-between border-b border-white/10 px-5">

                <div>
                    <div class="jc-shimmer-text text-sm font-semibold tracking-[0.25em]" data-ai-name>
                        {{ $aiName }}
                    </div>

                    <div class="mt-1 text-[10px] uppercase tracking-widest text-white/30">
                        Email Intelligence
                    </div>
                </div>

                <div class="flex items-center gap-3">

                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-emerald-400"></span>

                        <span class="text-[10px] uppercase tracking-wider text-emerald-400">
                            Online
                        </span>
                    </div>

                    <button
                        type="button"
                        id="jc-inbox-close"
                        aria-label="Close inbox"
                        class="flex h-7 w-7 items-center justify-center rounded-full text-white/40 transition hover:bg-white/10 hover:text-white"
                    >
                        ✕
                    </button>

                </div>

            </div>


            {{-- Email Summary (counts are filled in by JS) --}}
            <div class="border-b border-white/10 px-5 py-4">

                <div class="mb-3 text-[10px] uppercase tracking-[0.2em] text-white/30">
                    Inbox
                </div>

                <div class="grid grid-cols-3 gap-2">

                    <div class="rounded-lg border border-red-400/10 bg-red-400/5 p-3">
                        <div id="jc-count-urgent" class="text-lg font-semibold text-red-400">0</div>
                        <div class="mt-1 text-[9px] uppercase text-white/30">
                            Urgent
                        </div>
                    </div>

                    <div class="rounded-lg border border-orange-400/10 bg-orange-400/5 p-3">
                        <div id="jc-count-high" class="text-lg font-semibold text-orange-400">0</div>
                        <div class="mt-1 text-[9px] uppercase text-white/30">
                            High
                        </div>
                    </div>

                    <div class="rounded-lg border border-white/10 bg-white/[0.02] p-3">
                        <div id="jc-count-total" class="text-lg font-semibold text-white">0</div>
                        <div class="mt-1 text-[9px] uppercase text-white/30">
                            Emails
                        </div>
                    </div>

                </div>

            </div>


            {{-- Email List --}}
            <div class="flex-1 overflow-y-auto">

                <div class="flex items-center justify-between border-b border-white/5 px-5 py-3">
                    <div class="text-[10px] uppercase tracking-[0.2em] text-white/30">
                        Recent Emails
                    </div>
                    <button type="button" id="jc-inbox-refresh"
                        class="text-[10px] uppercase tracking-widest text-white/30 transition hover:text-white/70">
                        Refresh
                    </button>
                </div>

                <div id="jc-inbox-mailbox" class="truncate border-b border-white/5 px-5 py-2 text-[10px] text-white/30"></div>

                <div id="jc-inbox-list">
                    <div class="px-5 py-8 text-center text-xs text-white/30">
                        Open the inbox to load emails.
                    </div>
                </div>

            </div>

        </aside>

        {{-- Settings backdrop + drawer --}}
        <div id="jc-settings-backdrop" class="fixed inset-0 z-50 hidden bg-black/50 backdrop-blur-[2px]"></div>

        <aside
            id="jc-settings-drawer"
            class="jc-glass-strong fixed inset-y-0 right-0 z-[60] flex w-[420px] max-w-full translate-x-full flex-col border-l border-white/10"
            role="dialog"
            aria-modal="true"
            aria-labelledby="jc-settings-title"
            aria-hidden="true"
            inert
        >
            <div class="flex h-16 shrink-0 items-center justify-between border-b border-white/10 px-5">
                <div>
                    <div class="jc-shimmer-text text-sm font-semibold tracking-[0.25em]" data-ai-name>{{ $aiName }}</div>
                    <div id="jc-settings-title" class="mt-1 text-[10px] uppercase tracking-widest text-white/30">Settings</div>
                </div>
                <button type="button" id="jc-settings-close" aria-label="Close settings"
                    class="flex h-7 w-7 items-center justify-center rounded-full text-white/40 transition hover:bg-white/10 hover:text-white">✕</button>
            </div>

            {{-- Tabs --}}
            <div class="flex shrink-0 border-b border-white/10 text-[10px] uppercase tracking-wider" role="tablist" aria-label="Settings sections">
                <button type="button" role="tab" id="jc-tab-identity" aria-controls="jc-panel-identity" aria-selected="true"  tabindex="0"  class="jc-tab" data-tab="identity">Identity</button>
                <button type="button" role="tab" id="jc-tab-apikey"   aria-controls="jc-panel-apikey"   aria-selected="false" tabindex="-1" class="jc-tab" data-tab="apikey">API Key</button>
                <button type="button" role="tab" id="jc-tab-scope"    aria-controls="jc-panel-scope"    aria-selected="false" tabindex="-1" class="jc-tab" data-tab="scope">Scope</button>
                <button type="button" role="tab" id="jc-tab-workflow" aria-controls="jc-panel-workflow" aria-selected="false" tabindex="-1" class="jc-tab" data-tab="workflow">Workflow</button>
                <button type="button" role="tab" id="jc-tab-email"    aria-controls="jc-panel-email"    aria-selected="false" tabindex="-1" class="jc-tab" data-tab="email">Email</button>
            </div>

            <div class="flex-1 overflow-y-auto px-5 py-5">

                {{-- IDENTITY --}}
                <div class="jc-tab-panel space-y-4" role="tabpanel" id="jc-panel-identity" aria-labelledby="jc-tab-identity" data-panel="identity">
                    <label class="block">
                        <span class="jc-label">AI name</span>
                        <input id="jc-set-ai-name" type="text" maxlength="30" placeholder="JC" class="jc-field">
                        <span class="jc-hint">Say "Hello &lt;name&gt;" to listen, or "Wake up &lt;name&gt;" to check for important email alerts.</span>
                    </label>
                    <label class="block">
                        <span class="jc-label">Owner's name</span>
                        <input id="jc-set-owner-name" type="text" maxlength="60" placeholder="Your name" class="jc-field">
                    </label>
                    <label class="block">
                        <span class="jc-label">Saved memories</span>
                        <textarea id="jc-set-learned-memory" rows="4" maxlength="4000" placeholder="One preference or fact per line" class="jc-field"></textarea>
                        <span class="jc-hint"><span id="jc-memory-count">0</span>/4000 &middot; Ask your AI to remember something, or edit and clear notes here.</span>
                    </label>
                    <label class="block">
                        <span class="jc-label">Wake name variants</span>
                        <input id="jc-set-wake-variants" type="text" placeholder="Lyra, Lira" class="jc-field">
                        <span class="jc-hint">Names speech recognition may hear instead. Separate variants with commas.</span>
                    </label>
                </div>

                {{-- API KEY --}}
                <div class="jc-tab-panel hidden space-y-4" role="tabpanel" id="jc-panel-apikey" aria-labelledby="jc-tab-apikey" data-panel="apikey">
                    <label class="block">
                        <span class="jc-label">Anthropic API key</span>
                        <div class="flex gap-2">
                            <input id="jc-set-api-key" type="password" autocomplete="off" spellcheck="false" placeholder="sk-ant-..." class="jc-field flex-1">
                            <button type="button" id="jc-toggle-key" class="jc-btn mt-2 h-11" aria-label="Show or hide API key">Show</button>
                        </div>
                        <span id="jc-key-status" class="mt-2 block text-[10px] text-white/30" role="status">No key saved.</span>
                    </label>
                    <p class="text-[11px] leading-5 text-white/30">
                        The key is sent to your server and stored encrypted. It is never kept in the browser
                        and never shown again. Leave blank to keep the current key.
                    </p>
                </div>

                {{-- SCOPE / LIMITS --}}
                <div class="jc-tab-panel hidden space-y-5" role="tabpanel" id="jc-panel-scope" aria-labelledby="jc-tab-scope" data-panel="scope">
                    <fieldset>
                        <legend class="jc-label">What may the AI do?</legend>
                        <div class="mt-2 space-y-2 text-sm text-white/70">
                            <label class="flex items-center gap-2"><input type="checkbox" class="jc-scope-cb" value="read_emails" checked> Read emails</label>
                            <label class="flex items-center gap-2"><input type="checkbox" class="jc-scope-cb" value="search_emails" checked> Search emails</label>
                            <label class="flex items-center gap-2"><input type="checkbox" class="jc-scope-cb" value="draft_replies" checked> Draft replies</label>
                            <label class="flex items-center gap-2"><input type="checkbox" class="jc-scope-cb" value="send_replies"> Send replies</label>
                            <label class="flex items-center gap-2"><input type="checkbox" class="jc-scope-cb" value="send_emails"> Send new emails</label>
                            <label class="flex items-center gap-2"><input type="checkbox" class="jc-scope-cb" value="analysis" checked> Analysis &amp; summaries</label>
                        </div>
                    </fieldset>

                    <label class="flex items-center gap-2 text-sm text-white/70">
                        <input type="checkbox" id="jc-set-confirm-send" checked>
                        Always ask me before sending anything
                    </label>
                    <div id="jc-scope-warning" class="jc-notice jc-notice-warn hidden" role="alert">
                        Sending is enabled and confirmation is off. JC will be able to send email without asking you first.
                    </div>

                    <label class="block">
                        <span class="jc-label">Tone</span>
                        <select id="jc-set-tone" class="jc-field">
                            <option value="professional">Professional</option>
                            <option value="friendly">Friendly</option>
                            <option value="concise">Concise</option>
                            <option value="formal">Formal</option>
                        </select>
                    </label>

                    <label class="block">
                        <span class="jc-label">Capability Demonstrations</span>
                        <select id="jc-set-capability-demonstrations" class="jc-field">
                            <option value="ask_first">Ask First</option>
                            <option value="automatic_safe_demo">Automatic Safe Demo</option>
                            <option value="never_demo">Never Demo</option>
                        </select>
                        <span class="mt-1 block text-xs text-white/40">This controls live demonstrations using your data. Visual explanations stay available. Sending, deletion and other changes require a real request.</span>
                    </label>

                    <label class="block">
                        <span class="jc-label">Daily credit limit (0 = unlimited)</span>
                        <input id="jc-set-credit-limit" type="number" min="0" step="1" inputmode="numeric" value="0" class="jc-field">
                    </label>

                    <label class="block">
                        <span class="jc-label">Scope of work</span>
                        <textarea id="jc-set-scope" rows="3" placeholder="e.g. Handle warranty, invoice and IT support emails only." class="jc-field"></textarea>
                    </label>

                    <label class="block">
                        <span class="jc-label">Limitations / never do</span>
                        <textarea id="jc-set-limits" rows="3" placeholder="e.g. Never share pricing. Never delete emails. Escalate legal matters to me." class="jc-field"></textarea>
                    </label>
                </div>

                {{-- WORKFLOW --}}
                <div class="jc-tab-panel hidden space-y-4" role="tabpanel" id="jc-panel-workflow" aria-labelledby="jc-tab-workflow" data-panel="workflow">
                    <a href="{{ route('jarvis.connections.index') }}" class="jc-btn">Connections · saved API authentication</a>
                    <p class="text-sm leading-6 text-white/50">
                        Build and manage the automations your AI runs (triggers, actions, schedules).
                    </p>
                    <a id="jc-workflow-link" href="{{ url('/jarvis/workflows') }}"
                       class="flex h-11 items-center justify-center rounded-xl border border-cyan-400/30 bg-cyan-400/10 text-sm font-medium text-cyan-300 transition hover:bg-cyan-400/20">
                        Open AI Automation Workflow
                    </a>
                </div>

                {{-- EMAIL ACCOUNTS --}}
                <div class="jc-tab-panel hidden space-y-4" role="tabpanel" id="jc-panel-email" aria-labelledby="jc-tab-email" data-panel="email">

                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-white">Email accounts</h3>
                            <p class="mt-1 text-xs text-white/40">Mailboxes JC can use for email automation.</p>
                        </div>
                        <button type="button" id="jc-email-add" class="jc-btn jc-btn-sm shrink-0">+ Add account</button>
                    </div>

                    <div id="jc-email-accounts-list" class="space-y-3" aria-live="polite">
                        <div class="py-6 text-center text-xs text-white/30">Loading email accounts...</div>
                    </div>

                    {{-- Add / Edit form --}}
                    <div id="jc-email-account-form" class="hidden space-y-4 rounded-xl border border-white/10 bg-white/[0.02] p-4">
                        <input type="hidden" id="jc-email-id" value="">

                        <div>
                            <h4 id="jc-email-form-title" class="text-sm font-semibold text-white">Add email account</h4>
                            <p class="mt-1 text-xs text-white/40">Configure the mailbox JC will use.</p>
                        </div>

                        <label class="block">
                            <span class="jc-label">Account name</span>
                            <input type="text" id="jc-email-name" class="jc-field" placeholder="e.g. Support inbox" maxlength="80">
                        </label>

                        <label class="block">
                            <span class="jc-label">Provider preset</span>
                            <select id="jc-email-provider" class="jc-field">
                                <option value="godaddy">GoDaddy</option>
                                <option value="microsoft365">Microsoft 365</option>
                                <option value="gmail">Gmail / Google Workspace</option>
                                <option value="custom">Custom server</option>
                            </select>
                            <span id="jc-email-provider-hint" class="jc-hint"></span>
                        </label>

                        <label class="block">
                            <span class="jc-label">Email address</span>
                            <input type="email" id="jc-email-address" class="jc-field" placeholder="name@company.com" autocomplete="off">
                        </label>

                        <label class="block">
                            <span class="jc-label">Mailbox password</span>
                            <div class="flex gap-2">
                                <input type="password" id="jc-email-password" class="jc-field flex-1" placeholder="Mailbox password" autocomplete="new-password">
                                <button type="button" id="jc-email-pw-toggle" class="jc-btn mt-2 h-11" aria-label="Show or hide mailbox password">Show</button>
                            </div>
                            <span id="jc-email-pw-hint" class="jc-hint">The password is encrypted before being stored.</span>
                        </label>

                        <fieldset class="border-t border-white/10 pt-4">
                            <legend class="mb-3 text-xs font-semibold text-white">IMAP: incoming mail</legend>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <label class="block sm:col-span-3">
                                    <span class="jc-label">Host</span>
                                    <input type="text" id="jc-email-imap-host" class="jc-field" placeholder="imap.secureserver.net" autocomplete="off">
                                </label>
                                <label class="block">
                                    <span class="jc-label">Port</span>
                                    <input type="number" id="jc-email-imap-port" class="jc-field" value="993" min="1" max="65535">
                                </label>
                                <label class="block sm:col-span-2">
                                    <span class="jc-label">Encryption</span>
                                    <select id="jc-email-imap-encryption" class="jc-field">
                                        <option value="ssl">SSL</option>
                                        <option value="tls">TLS</option>
                                        <option value="none">None</option>
                                    </select>
                                </label>
                            </div>
                        </fieldset>

                        <fieldset class="border-t border-white/10 pt-4">
                            <legend class="mb-3 text-xs font-semibold text-white">SMTP: outgoing mail</legend>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <label class="block sm:col-span-3">
                                    <span class="jc-label">Host</span>
                                    <input type="text" id="jc-email-smtp-host" class="jc-field" placeholder="smtpout.secureserver.net" autocomplete="off">
                                </label>
                                <label class="block">
                                    <span class="jc-label">Port</span>
                                    <input type="number" id="jc-email-smtp-port" class="jc-field" value="465" min="1" max="65535">
                                </label>
                                <label class="block sm:col-span-2">
                                    <span class="jc-label">Encryption</span>
                                    <select id="jc-email-smtp-encryption" class="jc-field">
                                        <option value="ssl">SSL</option>
                                        <option value="tls">TLS</option>
                                        <option value="none">None</option>
                                    </select>
                                </label>
                            </div>
                            <div id="jc-email-enc-warning" class="jc-notice jc-notice-warn mt-3 hidden" role="alert">
                                Encryption is off for at least one server. Your password will be sent in plain text.
                            </div>
                        </fieldset>

                        <div class="flex flex-wrap gap-4 text-xs text-white/70">
                            <label class="inline-flex items-center gap-2"><input type="checkbox" id="jc-email-active" checked> Active</label>
                            <label class="inline-flex items-center gap-2"><input type="checkbox" id="jc-email-default"> Default account</label>
                        </div>

                        <div id="jc-email-test-result" class="hidden" role="status"></div>

                        <div class="flex flex-wrap gap-2 pt-1">
                            <button type="button" id="jc-email-test" class="jc-btn">Test connection</button>
                            <button type="button" id="jc-email-save" class="jc-btn jc-btn-primary">Save account</button>
                            <button type="button" id="jc-email-cancel" class="jc-btn border-transparent bg-transparent">Cancel</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex shrink-0 items-center justify-between gap-3 border-t border-white/10 px-5 py-4">
                <span id="jc-settings-status" class="min-w-0 flex-1 truncate text-[11px] text-white/40" role="status" aria-live="polite"></span>
                <button type="button" id="jc-settings-save" class="jc-btn jc-btn-primary px-5">Save settings</button>
            </div>
        </aside>

        {{-- MAIN AREA --}}
        <main class="relative flex min-w-0 flex-1 flex-col">

            {{-- Top Bar --}}
            <header class="jc-glass flex h-16 items-center justify-between gap-3 border-b border-white/10 px-4 sm:px-8">

                <div class="flex items-center gap-4">
                    <button
                        type="button"
                        id="jc-inbox-btn"
                        aria-label="Open inbox"
                        title="Inbox"
                        class="flex h-8 items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-3 text-[10px] uppercase tracking-wider text-white/50 transition hover:bg-white/10 hover:text-white"
                    >
                        Inbox
                    </button>

                    <div class="hidden text-xs uppercase tracking-[0.25em] text-white/45 md:block">
                        Personal assistant
                    </div>
                    @if (config('jarvis.test_mode'))
                        <span class="jc-demo-mode-badge">Development / Test Mode</span>
                    @endif
                </div>

                <div class="flex items-center gap-2 sm:gap-4">

                    {{-- Active mailbox chip (click to open Settings > Email) --}}
                    <button
                        type="button"
                        id="jc-mailbox-chip"
                        title="Active mailbox. Click to change."
                        class="flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.03] px-3 py-1.5 transition hover:bg-white/[0.08]"
                    >
                        <span id="jc-mailbox-dot" class="h-1.5 w-1.5 rounded-full" style="background:#6b7280"></span>
                        <span id="jc-mailbox-chip-text" class="max-w-[120px] truncate text-[10px] text-white/50 sm:max-w-[200px]">Mailbox...</span>
                        <span id="jc-mailbox-chip-badge" class="hidden rounded-full bg-white/5 px-1.5 py-0.5 text-[8px] uppercase tracking-wider text-white/40"></span>
                    </button>

                    {{-- AI Credit Balance --}}
                    <div
                        id="jc-credit-display"
                        class="flex items-center gap-2 rounded-full border border-cyan-400/10 bg-cyan-400/[0.03] px-3 py-1.5"
                        title="Remaining AI credit balance"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-cyan-400"></span>

                        <span class="hidden text-[10px] uppercase tracking-wider text-white/30 sm:inline">
                            Balance
                        </span>

                        <span
                            id="jc-credit-value"
                            class="min-w-[50px] text-right text-xs font-semibold text-cyan-300"
                        >
                            —
                        </span>
                    </div>
                    <button
                        type="button"
                        id="jc-settings-btn"
                        aria-label="Open settings"
                        title="Settings"
                        class="flex h-8 w-8 items-center justify-center rounded-full border border-white/10 bg-white/[0.04] text-white/50 transition hover:bg-white/10 hover:text-white"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </button>
                    <div class="hidden rounded-full border border-cyan-300/10 bg-cyan-300/[0.04] px-3 py-1.5 text-[9px] uppercase tracking-[0.18em] text-cyan-100/45 lg:block">
                        Voice + chat
                    </div>
                    <button
                        type="button"
                        id="jc-avatar-vrm-open"
                        aria-label="Load a 3D VRM avatar"
                        title="Load a 3D VRM avatar"
                        class="rounded-full border border-cyan-200/20 bg-cyan-200/[.06] px-3 py-1.5 text-[9px] uppercase tracking-wider text-cyan-100/75 transition hover:bg-cyan-200/[.12] hover:text-cyan-50"
                    >
                        <span id="jc-avatar-vrm-button-text">Load VRM</span>
                    </button>
                    <input id="jc-avatar-vrm-file" type="file" accept=".vrm,model/gltf-binary" class="hidden" aria-label="Choose a VRM avatar file">
                    <span id="jc-avatar-vrm-status" class="hidden max-w-36 truncate text-[9px] text-cyan-100/55" role="status" aria-live="polite"></span>

                </div>

            </header>


            {{-- Center --}}
            <section id="jc-workspace-stage" class="flex flex-1 flex-col items-center justify-end overflow-y-auto px-4 pb-6 pt-4 sm:px-8">

                <div class="flex w-full max-w-3xl flex-col">

                    {{-- Identity + Human-form hologram --}}
                    <div id="jarvis-presence" class="ai-idle mb-6 text-center">

                        <h1
                            class="jc-shimmer-text text-3xl font-light tracking-[0.2em] sm:text-4xl"
                            data-ai-name
                        >
                            {{ $aiName }}
                        </h1>

                        

                        <p class="mt-2 text-xs tracking-wide text-white/45 sm:text-sm">
                            A thoughtful partner for your day.
                        </p>

                        <div class="jc-wave mx-auto" aria-hidden="true">
                            <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                        </div>

                        <p
                            id="jarvis-state-label"
                            class="mx-auto mt-2 text-[11px] uppercase tracking-[0.2em] text-white/30"
                            role="status"
                            aria-live="polite"
                        ></p>
                    </div>


                    {{-- Command Input --}}
                    <div
                        id="jarvis-command"
                        class="jc-glass-strong rounded-2xl border border-cyan-200/10 p-2 shadow-[0_18px_70px_-28px_rgba(39,184,224,0.4)] sm:p-3"
                    >

                        <div class="flex items-center gap-3">

                            <input
                                id="jarvis-input"
                                type="text"
                                placeholder="Ask {{ $aiName }} anything..."
                                autocomplete="off"
                                class="h-12 min-w-0 flex-1 bg-transparent px-3 text-sm text-white outline-none placeholder:text-white/35 sm:h-14 sm:text-base"
                                aria-label="Ask {{ $aiName }} anything"
                            >

                            <button
                                type="button"
                                id="jarvis-wake-toggle"
                                class="flex h-12 shrink-0 items-center gap-2 rounded-xl border border-white/10 bg-white/[0.04] px-3 text-xs font-medium text-white/60 transition hover:border-emerald-300/25 hover:bg-emerald-300/[0.06] hover:text-emerald-100 sm:h-14 sm:px-4"
                                title='Enable always-listening for "Hello {{ $aiName }}"'
                                aria-pressed="false"
                            >
                                <span id="jarvis-wake-dot" class="h-1.5 w-1.5 rounded-full bg-white/20"></span>
                                <span id="jarvis-wake-label">Enable Voice</span>
                            </button>

                            <button
                                id="jarvis-send"
                                type="button"
                                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-cyan-200 to-sky-400 text-slate-950 shadow-lg shadow-cyan-950/30 transition hover:scale-[1.03] hover:brightness-110 focus-visible:outline-offset-4 sm:h-14 sm:w-14"
                                aria-label="Send message"
                                title="Send message"
                            >
                                ➤
                            </button>

                        </div>

                        <div class="flex items-center justify-between gap-3 px-3 pb-1 pt-2">
                            <span class="text-[10px] text-white/35">Ask, analyze, automate, or take action.</span>
                            <span class="hidden text-[9px] uppercase tracking-wider text-white/25 sm:inline">Enter to send</span>
                        </div>

                    </div>

                    {{-- Console: text reply and/or email view --}}
                    <div
                        id="jarvis-result"
                        class="jc-glass-strong jc-conversation-panel fixed right-4 top-20 z-20 hidden max-h-[calc(100vh_-_6rem)] w-[calc(100vw_-_2rem)] max-w-lg overflow-y-auto rounded-2xl border border-cyan-200/10 bg-slate-950/65 p-5 shadow-2xl sm:p-6"
                        role="region"
                        aria-live="polite"
                        aria-busy="false"
                        aria-label="{{ $aiName }} Response"
                    >

                        <div class="mb-3 flex items-center justify-between">

                            <div class="flex items-center gap-2">
                                <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                                <span id="jarvis-result-label" data-ai-name class="text-xs font-medium tracking-wide text-cyan-100/80">
                                    {{ $aiName }}
                                </span>
                            </div>

                            <button
                                type="button"
                                id="jarvis-result-close"
                                class="text-[10px] uppercase tracking-widest text-white/30 transition hover:text-white/70"
                                aria-label="Close response"
                            >
                                Close
                            </button>

                        </div>

                        <div
                            id="jarvis-response"
                            class="text-sm leading-6 text-white/80"
                        ></div>
                        <div id="jarvis-email-results" class="mt-4 hidden space-y-2"></div>

                        {{-- Email detail + reply panel, shown when an email is open --}}
                        <div id="jarvis-email-view" class="mt-4 hidden">

                            <div class="rounded-xl border border-white/10 bg-white/[0.02] p-4">

                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div id="jarvis-email-subject" class="truncate text-sm font-medium text-white"></div>
                                        <div id="jarvis-email-from" class="mt-1 truncate text-xs text-white/40"></div>
                                    </div>
                                    <div id="jarvis-email-date" class="whitespace-nowrap text-[10px] text-white/30"></div>
                                </div>

                                <div
                                    id="jarvis-email-body"
                                    class="mt-3 whitespace-pre-line border-t border-white/5 pt-3 text-xs leading-6 text-white/50"
                                ></div>

                            </div>

                            <div class="mt-4">

                                <div class="mb-2 flex items-center justify-between">
                                    <span class="text-[10px] uppercase tracking-[0.2em] text-white/30">
                                        Your reply
                                    </span>
                                    <span id="jarvis-draft-status" class="text-[10px] text-white/30"></span>
                                </div>

                                <textarea
                                    id="jarvis-reply-text"
                                    rows="4"
                                    placeholder='Type your reply, or say "proceed" and {{ $aiName }} will draft one'
                                    class="w-full rounded-xl border border-white/10 bg-white/[0.03] p-3 text-sm text-white outline-none placeholder:text-white/20 focus:border-white/20"
                                    aria-label="Email reply"
                                ></textarea>

                                <div class="mt-3 flex items-center gap-2">

                                    <button
                                        type="button"
                                        id="jarvis-draft-btn"
                                        class="rounded-xl border border-white/10 bg-white/[0.04] px-4 py-2 text-xs font-medium text-white/60 transition hover:bg-white/[0.08] hover:text-white"
                                    >
                                        Ask {{ $aiName }} to draft
                                    </button>

                                    <button
                                        type="button"
                                        id="jarvis-send-reply-btn"
                                        class="rounded-xl bg-emerald-400/90 px-4 py-2 text-xs font-medium text-black transition hover:bg-emerald-400"
                                    >
                                        Send reply
                                    </button>

                                </div>

                            </div>

                        </div>

                    </div>


                    <div class="mt-5">
                        <div class="mb-3 flex items-center justify-between gap-3 px-1">
                            <span class="text-[10px] font-medium uppercase tracking-[0.2em] text-white/45">Start anywhere</span>
                            <span class="text-[10px] text-white/30">No special commands needed</span>
                        </div>

                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <button type="button" data-prompt="Compare all active projects" class="jarvis-suggestion group rounded-xl border border-white/[0.09] bg-slate-950/35 px-3 py-3 text-left transition hover:-translate-y-0.5 hover:border-cyan-200/25 hover:bg-cyan-200/[0.06] focus-visible:outline-cyan-200">
                                <span class="mb-2 block text-[9px] uppercase tracking-[0.16em] text-cyan-100/45">Operations</span>
                                <span class="text-xs text-white/75 transition group-hover:text-white">Compare all active projects</span>
                            </button>
                            <button type="button" data-prompt="Show project delivery progress" class="jarvis-suggestion group rounded-xl border border-white/[0.09] bg-slate-950/35 px-3 py-3 text-left transition hover:-translate-y-0.5 hover:border-cyan-200/25 hover:bg-cyan-200/[0.06] focus-visible:outline-cyan-200">
                                <span class="mb-2 block text-[9px] uppercase tracking-[0.16em] text-cyan-100/45">Report</span>
                                <span class="text-xs text-white/75 transition group-hover:text-white">Show project delivery progress</span>
                            </button>
                            <button type="button" data-prompt="Check important emails" class="jarvis-suggestion group rounded-xl border border-white/[0.09] bg-slate-950/35 px-3 py-3 text-left transition hover:-translate-y-0.5 hover:border-cyan-200/25 hover:bg-cyan-200/[0.06] focus-visible:outline-cyan-200">
                                <span class="mb-2 block text-[9px] uppercase tracking-[0.16em] text-violet-100/45">Email</span>
                                <span class="text-xs text-white/75 transition group-hover:text-white">Check important emails</span>
                            </button>
                            <button type="button" data-prompt="Show my workflows" class="jarvis-suggestion group rounded-xl border border-white/[0.09] bg-slate-950/35 px-3 py-3 text-left transition hover:-translate-y-0.5 hover:border-cyan-200/25 hover:bg-cyan-200/[0.06] focus-visible:outline-cyan-200">
                                <span class="mb-2 block text-[9px] uppercase tracking-[0.16em] text-violet-100/45">Automation</span>
                                <span class="text-xs text-white/75 transition group-hover:text-white">Show my workflows</span>
                            </button>
                        </div>
                    </div>

                </div>

            </section>

            <aside id="jc-workspace" hidden aria-label="JARVIS workspace">
                <div data-workspace-splitter role="separator" aria-label="Resize workspace" aria-orientation="vertical" tabindex="0"></div>
                <header class="jc-workspace-header">
                    <div>
                        <p class="jc-workspace-kicker">JARVIS / WORKSPACE</p>
                        <h2>Active results <span id="jc-workspace-count">0</span></h2>
                    </div>
                    <div class="jc-workspace-header-actions">
                        <button type="button" data-workspace-action="conversation" class="jc-workspace-control">Conversation</button>
                        <button type="button" data-workspace-action="collapse" class="jc-workspace-control" aria-label="Collapse workspace">−</button>
                        <button type="button" data-workspace-action="close-all" class="jc-workspace-control" aria-label="Close all artifacts">×</button>
                    </div>
                </header>
                <div id="jc-workspace-minimized" class="jc-workspace-minimized" aria-label="Minimized artifacts"></div>
                <div id="jc-workspace-artifacts" class="jc-workspace-artifacts" aria-live="polite"></div>
            </aside>

            <button type="button" id="jc-mobile-workspace-toggle" class="jc-mobile-workspace-toggle" aria-label="Open JARVIS workspace">Workspace</button>


            {{-- Bottom Status --}}
            <footer class="jc-glass flex h-12 items-center justify-between border-t border-white/10 px-4 sm:px-8">

                <div class="text-[10px] uppercase tracking-widest text-white/20">
                    A clear space to think and get things done
                </div>

                <div class="text-[10px] text-white/20">
                    MMC AI • Anthropic
                </div>

            </footer>

        </main>

    </div>

{{-- =====================================================================
     MAIN APP SCRIPT
     ===================================================================== --}}
<script>
(function () {
    window.jcAiName = @js($aiName);

    // =====================================================================
    // CONFIGURATION & DEBUG UTILITIES
    // =====================================================================

    const DEBUG_MODE = localStorage.getItem('jc_debug') === 'true';

    // Endpoint that returns recent emails: { success: true, emails: [...] }
    // (add this route/controller method on the server, see notes)
    const INBOX_URL = '/jarvis/inbox';

    function debugLog(category, message, data = null) {
        if (!DEBUG_MODE) return;
        const timestamp = new Date().toLocaleTimeString();
        console.log(`[${timestamp}] [${category}] ${message}`, data || '');
    }

    function showError(message) {
        const errorEl = document.createElement('div');
        errorEl.className = 'jc-error-message';
        errorEl.textContent = message;
        responseBox.appendChild(errorEl);
        console.error('JC Error:', message);
    }

    function getCSRFToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content
            || document.querySelector('input[name="_token"]')?.value
            || '';
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    }

    // Pause CSS animations while the tab is hidden
    document.addEventListener('visibilitychange', function () {
        document.documentElement.classList.toggle('jc-tab-hidden', document.hidden);
    });

    // =====================================================================
    // DOM ELEMENTS
    // =====================================================================

    const input             = document.getElementById('jarvis-input');
    const send              = document.getElementById('jarvis-send');
    const result            = document.getElementById('jarvis-result');
    const resultLabel       = document.getElementById('jarvis-result-label');
    const resultClose       = document.getElementById('jarvis-result-close');
    const responseBox       = document.getElementById('jarvis-response');
    const presence          = document.getElementById('jarvis-presence');
    const stateLabel        = document.getElementById('jarvis-state-label');
    const wakeToggle        = document.getElementById('jarvis-wake-toggle');
    const wakeDot           = document.getElementById('jarvis-wake-dot');
    const wakeLabel         = document.getElementById('jarvis-wake-label');

    const emailView         = document.getElementById('jarvis-email-view');
    const emailResults      = document.getElementById('jarvis-email-results');
    const emailSubjectEl    = document.getElementById('jarvis-email-subject');
    const emailFromEl       = document.getElementById('jarvis-email-from');
    const emailDateEl       = document.getElementById('jarvis-email-date');
    const emailBodyEl       = document.getElementById('jarvis-email-body');
    const replyText         = document.getElementById('jarvis-reply-text');
    const draftStatus       = document.getElementById('jarvis-draft-status');
    const draftBtn          = document.getElementById('jarvis-draft-btn');
    const sendReplyBtn      = document.getElementById('jarvis-send-reply-btn');

    const creditValue       = document.getElementById('jc-credit-value');

    const inboxDrawer       = document.getElementById('jc-inbox-drawer');
    const inboxBackdrop     = document.getElementById('jc-inbox-backdrop');
    const inboxList         = document.getElementById('jc-inbox-list');

    // =====================================================================
    // VOICE CONFIGURATION
    // =====================================================================

    function normalizeWakeText(text) {
        return text
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^\p{L}\p{N}]+/gu, ' ')
            .trim()
            .replace(/\s+/g, ' ');
    }

    function buildWakePhrases(name, variants = []) {
        const names = [name, ...variants]
            .map((value) => normalizeWakeText(value))
            .filter(Boolean);
        const uniqueNames = [...new Set(names.length ? names : ['jc'])];

        return [...new Set(uniqueNames.flatMap((n) => [
            'hello ' + n,
            'hey ' + n,
            'ok ' + n,
            'okay ' + n,
            'wake up ' + n,
        ]))];
    }

    function matchesWakeNameWord(spoken, configured) {
        if (spoken === configured) return true;
        if (configured.length < 4 || Math.abs(spoken.length - configured.length) > 1) return false;

        let spokenIndex = 0;
        let configuredIndex = 0;
        let differences = 0;

        while (spokenIndex < spoken.length && configuredIndex < configured.length) {
            if (spoken[spokenIndex] === configured[configuredIndex]) {
                spokenIndex++;
                configuredIndex++;
                continue;
            }

            differences++;
            if (differences > 1) return false;

            if (spoken.length > configured.length) {
                spokenIndex++;
            } else if (configured.length > spoken.length) {
                configuredIndex++;
            } else {
                spokenIndex++;
                configuredIndex++;
            }
        }

        return differences + (spoken.length - spokenIndex) + (configured.length - configuredIndex) <= 1;
    }

    let storedWakeNameVariants = [];
    try {
        storedWakeNameVariants = JSON.parse(localStorage.getItem('jc_wake_name_variants') || '[]');
        if (!Array.isArray(storedWakeNameVariants)) storedWakeNameVariants = [];
    } catch (error) {
        storedWakeNameVariants = [];
    }

    let WAKE_PHRASES = buildWakePhrases(window.jcAiName, storedWakeNameVariants);

    window.jcSetWakeName = function (name, variants = []) {
        storedWakeNameVariants = Array.isArray(variants) ? variants : [];
        WAKE_PHRASES = buildWakePhrases(name, storedWakeNameVariants);
        localStorage.setItem('jc_wake_name_variants', JSON.stringify(storedWakeNameVariants));
        debugLog('VOICE', 'Wake phrases updated', WAKE_PHRASES);
    };

    const STOP_PHRASES = [
        'stop', 'pause', 'cancel', 'stop it', 'pause it', 'cancel it',
        'never mind', 'nevermind', 'stop working', 'pause working',
        'cancel that', 'stop that', 'stop this', 'pause this'
    ];

    const SpeechRecognitionAPI = window.SpeechRecognition || window.webkitSpeechRecognition;
    const speechSupported = !!SpeechRecognitionAPI;
    const speakingSupported = 'speechSynthesis' in window;

    const MIN_COMMAND_LENGTH = 3;
    const CONVERSATION_TIMEOUT = 30000;

    // =====================================================================
    // STATE MANAGEMENT
    // =====================================================================

    let recognition = null;
    let wakeModeEnabled = false;
    let listeningForCommand = false;
    let conversationMode = false;
    let conversationTimer = null;
    let restartTimer = null;
    let isJCSpeaking = false;
    let jarvisWorking = false;
    let jarvisPaused = false;
    let activeCommandController = null;
    let currentEmail = null;
    let draftPending = false;
    let emailCheckInFlight = false;

    let conversationId = localStorage.getItem('jc_conversation_id');

    // =====================================================================
    // CREDIT MANAGEMENT
    // =====================================================================

    function updateCreditDisplay(balance) {
        if (!creditValue) return;

        const numericBalance = Number(balance);

        if (!Number.isFinite(numericBalance)) {
            console.warn('Invalid credit balance:', balance);
            return;
        }

        const formatted = numericBalance.toLocaleString('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });

        creditValue.textContent = formatted;

        localStorage.setItem(
            'jc_credit_php',
            String(numericBalance)
        );
    }
    async function loadCreditBalance() {
        if (!creditValue) return;

        try {
            const response = await fetch('/jarvis/credits', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                cache: 'no-store',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (
                data.success &&
                data.credit &&
                data.credit.jarvis_php_balance !== undefined &&
                data.credit.jarvis_php_balance !== null
            ) {
                updateCreditDisplay(
                    data.credit.jarvis_php_balance
                );

                window.jcCredit = data.credit;

                debugLog('CREDIT', 'JARVIS credit balance', {
                    actualUsd: data.credit.actual_usd_balance,
                    multiplier: data.credit.multiplier,
                    jarvisUsd: data.credit.jarvis_usd_balance,
                    forex: data.credit.usd_php_rate,
                    jarvisPhp: data.credit.jarvis_php_balance,
                });
            }

        } catch (error) {
            console.error(
                'Failed to load JARVIS credit balance:',
                error
            );
        }
    }
    // Restore the last known credit (FIX: this read 'jc_credit' while the writer uses 'jc_credit_php',
    // so the cached value was never restored)
    const savedCredit = localStorage.getItem('jc_credit_php');

    if (savedCredit !== null) {
        updateCreditDisplay(savedCredit);
    } else {
        // Don't show a false 0 before the server responds.
        if (creditValue) {
            creditValue.textContent = '—';
        }
    }

    // =====================================================================
    // AI STATE & PRESENCE
    // =====================================================================

    function setAiState(state) {
        window.jcAiStateName = state; // lets the lazily-loaded avatar pick up the current state
        window.dispatchEvent(new CustomEvent('jc:avatar-state', { detail: { state } }));

        if (presence) {
            presence.classList.remove('ai-idle', 'ai-armed', 'ai-listening', 'ai-thinking', 'ai-speaking');
            presence.classList.add('ai-' + state);
        }

        if (window.jcAvatar && typeof window.jcAvatar.setState === 'function') {
            window.jcAvatar.setState(state);
        }

        debugLog('STATE', 'AI state changed', state);
    }

    function setLabel(text) {
        if (stateLabel) {
            stateLabel.textContent = text;
        }
    }

    function getAiName() {
        return (window.jcAiName || localStorage.getItem('jc_ai_name') || 'JC').trim() || 'JC';
    }

    function getIdleLabel() {
        return `Say "Hello ${getAiName()}" to wake me, or type below`;
    }

    function getWakePhraseLabel() {
        return `Say "${WAKE_PHRASES[0]}" to activate`;
    }

    if (stateLabel) {
        stateLabel.textContent = getIdleLabel();
    }

    // =====================================================================
    // EMAIL CONSOLE
    // =====================================================================

    function openEmailConsole(email, jcNote = null) {
        if (!email) return;

        const uid = email.uid ?? email.email_uid ?? email.id ?? null;
        if (!uid) {
            console.error('Email has no UID:', email);
            showError('Email missing required UID field');
            return;
        }

        email.uid = uid;
        currentEmail = email;
        draftPending = false;

        emailResults.classList.add('hidden');
        emailResults.innerHTML = '';

        result.classList.remove('hidden');
        resultLabel.textContent = getAiName();
        resultClose.classList.remove('hidden');

        window.jcResponse?.render(responseBox, { display: { type: 'conversation', message: jcNote || 'Here is that email. You can type a reply below, or ask me to draft one.' } });
        responseBox.classList.remove('hidden');

        emailSubjectEl.textContent = email.subject || '(No subject)';
        emailFromEl.textContent = email.from_name || email.from || 'Unknown sender';

        emailDateEl.textContent = email.date
            ? new Date(email.date).toLocaleString(undefined, {
                month: 'short', day: 'numeric', year: 'numeric',
                hour: 'numeric', minute: '2-digit'
              })
            : '';

        emailBodyEl.textContent = email.body || '(No content)';

        replyText.value = '';
        draftStatus.textContent = '';

        emailView.classList.remove('hidden');
        emailView.classList.add('jc-fade-in');

        setTimeout(() => emailView.classList.remove('jc-fade-in'), 500);

        debugLog('EMAIL', 'Email console opened', { uid, subject: email.subject });
    }

    function closeEmailConsole() {
        currentEmail = null;
        draftPending = false;
        emailView.classList.add('hidden');
        result.classList.add('hidden');
    }

    resultClose.addEventListener('click', closeEmailConsole);

    // =====================================================================
    // EMAIL RESULTS (inline list in the console)
    // =====================================================================

    function formatShortDate(value) {
        return value
            ? new Date(value).toLocaleString(undefined, {
                month: 'short', day: 'numeric', year: 'numeric',
                hour: 'numeric', minute: '2-digit'
              })
            : '';
    }

    function showEmailResults(emails) {
        emailResults.innerHTML = '';
        emailResults.classList.remove('hidden');

        if (!Array.isArray(emails) || emails.length === 0) {
            emailResults.innerHTML = '<div class="px-4 py-3 text-sm text-white/50">No matching emails found.</div>';
            return;
        }

        const frag = document.createDocumentFragment();

        emails.forEach(function (email) {
            const uid = email.uid ?? email.email_uid ?? email.id ?? '';
            if (!uid) {
                console.warn('Email has no UID:', email);
                return;
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'w-full rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3 text-left transition hover:bg-white/[0.08] focus:outline-none focus:ring-2 focus:ring-white/20';
            button.dataset.uid = uid;

            button.innerHTML = `
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium text-white">${escapeHtml(email.subject || '(No subject)')}</div>
                        <div class="mt-1 truncate text-xs text-white/60">${escapeHtml(email.from_name || email.from || 'Unknown sender')}</div>
                    </div>
                    <div class="shrink-0 text-[11px] text-white/40">${escapeHtml(formatShortDate(email.date))}</div>
                </div>
            `;

            button.addEventListener('click', () => selectEmail(button));
            frag.appendChild(button);
        });

        emailResults.appendChild(frag);
    }

    // =====================================================================
    // INBOX DRAWER (lazy loaded, so page render never waits on IMAP)
    // =====================================================================

    let inboxLoaded = false;
    let inboxLoading = false;

    function categoryFor(subject) {
        const s = String(subject || '').toLowerCase();
        if (s.includes('warranty')) return 'Warranty';
        if (s.includes('invoice')) return 'Finance';
        if (s.includes('laptop')) return 'IT Support';
        return 'General';
    }

    function renderInbox(emails) {
        const list = Array.isArray(emails) ? emails : [];

        const setText = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = String(value);
        };

        setText('jc-count-total', list.length);
        setText('jc-count-urgent', list.filter(e => String(e.priority || '').toLowerCase() === 'urgent').length);
        setText('jc-count-high', list.filter(e => String(e.priority || '').toLowerCase() === 'high').length);

        if (list.length === 0) {
            inboxList.innerHTML = '<div class="px-5 py-8 text-center text-xs text-white/30">No emails found.</div>';
            return;
        }

        const frag = document.createDocumentFragment();

        list.forEach(function (email) {
            const uid = email.uid ?? email.email_uid ?? email.id ?? '';
            if (!uid) return;

            const seen = Boolean(email.seen);
            const subject = email.subject || '(No Subject)';
            const from = email.from_name || email.from || '';
            const dateLabel = email.date
                ? new Date(email.date).toLocaleDateString(undefined, { month: 'short', day: '2-digit' })
                : '';

            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'jc-email-row w-full border-b border-white/5 px-5 py-4 text-left transition hover:bg-white/[0.07]';
            row.dataset.uid = uid;

            row.innerHTML = `
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium ${seen ? 'text-white/70' : 'text-white'}">${escapeHtml(subject)}</div>
                        <div class="mt-1 truncate text-xs text-white/40">${escapeHtml(from)}</div>
                    </div>
                    <div class="whitespace-nowrap text-[10px] text-white/30">${escapeHtml(dateLabel)}</div>
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <span class="rounded-full bg-white/5 px-2 py-1 text-[9px] uppercase tracking-wider text-white/40">${categoryFor(subject)}</span>
                    ${seen ? '' : `
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                        <span class="text-[9px] uppercase tracking-wider text-emerald-400">New</span>`}
                </div>
            `;

            row.addEventListener('click', function () {
                closeInbox();
                selectEmail(row);
            });

            frag.appendChild(row);
        });

        inboxList.innerHTML = '';
        inboxList.appendChild(frag);
    }

    async function loadInbox(force = false) {
        if (inboxLoading || (inboxLoaded && !force)) return;

        inboxLoading = true;
        inboxList.innerHTML = `
            <div class="flex items-center justify-center gap-2 px-5 py-8 text-xs text-white/40">
                <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-white/40"></span>
                Loading emails...
            </div>`;

        try {
            const response = await fetch(INBOX_URL, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || data.message || 'Could not load emails.');
            }

            renderInbox(data.emails);
            inboxLoaded = true;

        } catch (error) {
            console.error('Inbox load failed:', error);
            inboxList.innerHTML = `<div class="px-5 py-8 text-center text-xs text-red-300">${escapeHtml(describeFetchError(error))}</div>`;
        } finally {
            inboxLoading = false;
        }
    }

    function openInbox() {
        inboxDrawer.classList.remove('-translate-x-full');
        inboxDrawer.setAttribute('aria-hidden', 'false');
        inboxBackdrop.classList.remove('hidden');
        loadInbox();
    }

    function closeInbox() {
        inboxDrawer.classList.add('-translate-x-full');
        inboxDrawer.setAttribute('aria-hidden', 'true');
        inboxBackdrop.classList.add('hidden');
    }

    window.jcOpenInbox = openInbox;
    window.jcCloseInbox = closeInbox;

    document.getElementById('jc-inbox-btn')?.addEventListener('click', openInbox);
    document.getElementById('jc-inbox-close')?.addEventListener('click', closeInbox);
    document.getElementById('jc-inbox-refresh')?.addEventListener('click', () => loadInbox(true));
    inboxBackdrop?.addEventListener('click', closeInbox);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeInbox();
    });

    // =====================================================================
    // SPEECH & VOICE
    // =====================================================================

    function getFriendlyVoice() {
        if (!speakingSupported) return null;

        const voices = window.speechSynthesis.getVoices();

        if (!voices.length) {
            return null;
        }

        const englishVoices = voices.filter((voice) =>
            /^en([-_]|$)/i.test(voice.lang)
        );

        if (!englishVoices.length) {
            return null;
        }

        const preferredFemaleNames = [
            'jenny',
            'aria',
            'samantha',
            'zira',
            'susan',
            'victoria',
            'karen',
            'hazel',
            'moira',
            'ava',
            'allison',
            'tessa',
            'sara',
            'female'
        ];

        const scoredVoices = englishVoices.map((voice) => {
            const name = voice.name.toLowerCase();
            const lang = voice.lang.toLowerCase();

            let score = 0;

            preferredFemaleNames.forEach((keyword, index) => {
                if (name.includes(keyword)) {
                    score += 100 - index;
                }
            });

            if (lang === 'en-us') {
                score += 20;
            }

            if (!voice.localService) {
                score -= 5;
            }

            return { voice, score };
        });

        scoredVoices.sort((a, b) => b.score - a.score);

        return scoredVoices[0]?.voice || null;
    }

    // FIX: guarded, so browsers without speech synthesis don't throw on page load
    if (speakingSupported) {
        window.speechSynthesis.addEventListener('voiceschanged', () => {
            const voice = getFriendlyVoice();

            if (voice) {
                debugLog('VOICE', `Voice ready: ${voice.name} (${voice.lang})`);
            }
        });
    }

    function speak(text) {
        text = window.jcResponse?.safeSpeech(text) ?? 'The details are on screen.';
        if (!speakingSupported || !text) return;

        isJCSpeaking = true;
        clearTimeout(restartTimer);

        // Stop voice recognition while JARVIS is speaking
        if (recognition) {
            try {
                recognition.stop();
            } catch (e) {
                // Recognition may already be stopped
            }
        }

        // Stop any previous speech
        window.speechSynthesis.cancel();

        const utterance = new SpeechSynthesisUtterance(text);

        // Select feminine English voice
        const voice = getFriendlyVoice();

        if (voice) {
            utterance.voice = voice;
            utterance.lang = voice.lang || 'en-US';

            debugLog('VOICE', `Voice: ${voice.name} (${voice.lang})`);
        } else {
            // Safe fallback
            utterance.lang = 'en-US';
            console.warn('No preferred feminine voice found. Using browser default.');
        }

        // Feminine / natural speaking characteristics
        utterance.rate = 0.90;
        utterance.pitch = 1.16;
        utterance.volume = 1.0;

        utterance.onstart = function () {
            setAiState('speaking');
            setLabel(`${getAiName()} is speaking...`);
        };

        utterance.onboundary = function (event) {
            if (
                window.jcAvatar &&
                typeof window.jcAvatar.pulse === 'function'
            ) {
                window.jcAvatar.pulse(event, text);
            }
        };

        utterance.onend = function () {
            isJCSpeaking = false;
            afterSpeakingOrIdle();
        };

        utterance.onerror = function (event) {
            isJCSpeaking = false;

            console.error(
                'JARVIS speech error:',
                event.error
            );

            afterSpeakingOrIdle();
        };

        window.speechSynthesis.speak(utterance);
    }

    // =====================================================================
    // STOP / PAUSE COMMAND
    // =====================================================================

    function isStopCommand(command) {
        const normalized = command
            .trim()
            .toLowerCase()
            .replace(/[.!?,]/g, '')
            .replace(/\s+/g, ' ');

        return STOP_PHRASES.includes(normalized);
    }

    function stopJarvisWork() {
        if (!jarvisWorking) return false;

        debugLog('COMMAND', 'Stopping current work');

        jarvisPaused = true;
        jarvisWorking = false;
        window.jcResponse?.finish(responseBox);

        if (activeCommandController) {
            try {
                activeCommandController.abort();
            } catch (e) {
                console.warn('Could not abort command:', e);
            }
            activeCommandController = null;
        }

        if (speakingSupported) {
            window.speechSynthesis.cancel();
        }

        isJCSpeaking = false;

        responseBox.textContent = 'Okay, I stopped working on that.';
        responseBox.classList.remove('hidden');

        input.disabled = false;
        send.disabled = false;

        setAiState(wakeModeEnabled ? 'listening' : 'idle');
        setLabel(wakeModeEnabled ? "I'm listening..." : getIdleLabel());

        speak('Okay, I stopped.');

        return true;
    }

    // =====================================================================
    // STATE RECOVERY
    // =====================================================================

    function afterSpeakingOrIdle() {
        if (jarvisWorking) {
            setAiState('thinking');
            setLabel(`${getAiName()} is responding... Say "stop" or "pause" to interrupt.`);
            resumeRecognitionIfNeeded();
            return;
        }

        if (!wakeModeEnabled) {
            setAiState('idle');
            setLabel(getIdleLabel());
            return;
        }

        if (conversationMode) {
            setAiState('listening');
            setLabel("I'm listening...");
            refreshConversationTimer();
            resumeRecognitionIfNeeded();
            return;
        }

        setAiState('armed');
        setLabel(getWakePhraseLabel());
        resumeRecognitionIfNeeded();
    }

    // =====================================================================
    // CONVERSATION MODE
    // =====================================================================

    function startConversationMode() {
        conversationMode = true;
        listeningForCommand = true;
        refreshConversationTimer();
    }

    function refreshConversationTimer() {
        if (!conversationMode) return;

        clearTimeout(conversationTimer);

        conversationTimer = setTimeout(function () {
            conversationMode = false;
            listeningForCommand = false;

            if (wakeModeEnabled && !jarvisWorking) {
                setAiState('armed');
                setLabel(getWakePhraseLabel());
            }
        }, CONVERSATION_TIMEOUT);
    }

    // =====================================================================
    // MAIN COMMAND HANDLER
    // =====================================================================

    let visualCapabilityCategory = null;
    let capabilityExplanationActive = false;
    let pendingClarificationId = null;
    window.addEventListener('jc:response-command', (event) => {
        const command = event.detail?.command;
        if (typeof command === 'string' && !jarvisWorking) {
            void talkToJarvis(command).finally(() => window.jcResponse?.setBusy(false));
        } else {
            window.jcResponse?.setBusy(jarvisWorking);
        }
    });
    window.addEventListener('jc:response-fill', (event) => {
        if (!jarvisWorking && typeof event.detail?.text === 'string') {
            input.value = event.detail.text;
            input.focus();
        }
    });
    window.addEventListener('jc:response-open-email', (event) => {
        if (jarvisWorking || !/^\d+$/.test(String(event.detail?.uid ?? ''))) return;
        const button = document.createElement('button');
        button.dataset.uid = event.detail.uid;
        window.jcResponse?.setBusy(true);
        void selectEmail(button).finally(() => window.jcResponse?.setBusy(false));
    });
    window.addEventListener('jc:clarification-answer', (event) => {
        const answer = event.detail?.answer;
        if (typeof answer === 'string' && !jarvisWorking) {
            void talkToJarvis(answer, event.detail?.clarificationId).finally(() => window.jcClarification?.setBusy(false));
        } else {
            window.jcClarification?.setBusy(jarvisWorking);
        }
    });
    window.addEventListener('jc:visual-demo-category', (event) => {
        visualCapabilityCategory = event.detail?.category || null;
    });
    window.addEventListener('jc:visual-demo-command', (event) => {
        const command = event.detail?.command;
        if (typeof command === 'string' && !jarvisWorking) {
            void talkToJarvis(command).finally(() => window.jcVisualDemo?.setBusy(false));
        } else {
            window.jcVisualDemo?.setBusy(jarvisWorking);
        }
    });

    async function talkToJarvis(spokenCommand, clarificationId = undefined) {
        const command = ((spokenCommand !== undefined ? spokenCommand : input.value).trim());

        if (!command) return;

        // Handle working state
        if (jarvisWorking) {
            if (isStopCommand(command)) {
                stopJarvisWork();
                input.value = '';
                return;
            }
            debugLog('COMMAND', 'JC is busy. Ignoring:', command);
            return;
        }

        // Draft reply shortcut
        if (currentEmail && !draftPending && !pendingClarificationId && !clarificationId && window.jcVisualDemo?.isExplicitDraftCommand(command, capabilityExplanationActive)) {
            input.value = '';
            await draftReply();
            return;
        }

        // Start working
        jarvisWorking = true;
        jarvisPaused = false;

        window.jcVisualDemo?.setBusy(true);
        window.jcClarification?.setBusy(true);
        window.jcResponse?.setBusy(true);

        input.disabled = true;
        send.disabled = true;

        activeCommandController = new AbortController();

        setAiState('thinking');
        setLabel(`${getAiName()} is thinking...`);

        result.classList.remove('hidden');
        resultLabel.textContent = getAiName();
        responseBox.classList.remove('hidden');
        window.jcResponse?.begin(responseBox, command);

        try {
            const response = await fetch('/jarvis/command', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCSRFToken(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    command: command,
                    conversation_id: conversationId,
                    visual_category: visualCapabilityCategory,
                    clarification_id: clarificationId ?? pendingClarificationId,
                    email_uid: currentEmail ? currentEmail.uid : null,
                }),
                signal: activeCommandController.signal,
            });

            if (jarvisPaused) return;

            const data = await response.json();

            if (jarvisPaused) return;

            if (!response.ok || !data.success) {
                pendingClarificationId = null;
                capabilityExplanationActive = true;
                window.jcResponse?.render(responseBox, data.display ? data : {
                    display: { type: 'error', message: 'I could not finish that. Check the details below.', details: data.error || data.message || 'Please try again.' },
                });
                speak(window.jcResponse?.speechFor(data));
                return;
            }

            if (Array.isArray(data.wake_name_variants)) {
                window.jcSetWakeName(getAiName(), data.wake_name_variants);
            }

            // Save conversation ID
            if (data.conversation_id) {
                conversationId = data.conversation_id;
                localStorage.setItem('jc_conversation_id', conversationId);
            }

            window.jcArtifactWorkspace?.show(data.artifacts);

            // Update remaining credit balance
            if (
                data.credit &&
                data.credit.balance !== undefined
            ) {
                updateCreditDisplay(data.credit.balance);

                window.jcCredit = data.credit;

                debugLog('CREDIT', 'Credit balance updated', {
                    limit: data.credit.limit,
                    used: data.credit.used,
                    balance: data.credit.balance,
                });
            }

            // Handle email results
            let finalResponse = data.type === 'visual_demo' ? (data.response || '') : (data.response || 'Done.');

            if (Array.isArray(data.emails)) {
                showEmailResults(data.emails);

                if (data.emails.length > 0) {
                    const genericResponse = !data.response || /working|searching|finding|processing/i.test(data.response);
                    if (genericResponse) {
                        finalResponse = `Done. I found ${data.emails.length} matching email${data.emails.length === 1 ? '' : 's'}.`;
                    }
                } else {
                    finalResponse = data.response || 'I finished searching, but I could not find any matching emails.';
                }
            } else {
                emailResults.classList.add('hidden');
                emailResults.innerHTML = '';
            }

            // Handle specific email
            if (data.email) {
                openEmailConsole(data.email, finalResponse);
            }

            // Display response
            const responseRendered = window.jcResponse?.render(responseBox, data);
            if (!responseRendered) {
                responseBox.textContent = finalResponse;
            }
            if (data.display?.type === 'email_preview') {
                emailResults.classList.add('hidden');
                emailResults.replaceChildren();
            }
            pendingClarificationId = data.type === 'clarification' ? data.clarification_id : null;
            if (data.type === 'visual_demo') {
                visualCapabilityCategory = data.category || null;
            } else {
                visualCapabilityCategory = null;
            }
            if (data.type === 'visual_demo' || data.type === 'clarification') emailView.classList.add('hidden');
            capabilityExplanationActive = ['visual_demo', 'capabilities', 'capability_summary', 'live_demo', 'data_unavailable', 'clarification'].includes(data.type);

            // Announce completion
            speak(window.jcResponse?.speechFor(data));

            debugLog('COMMAND', 'Command completed', { command, response: finalResponse });

        } catch (error) {
            if (error.name === 'AbortError' || jarvisPaused) {
                debugLog('COMMAND', 'Command stopped by user');
                return;
            }

            console.error('JC command error:', error);

            const friendly = describeFetchError(error);
            window.jcResponse?.render(responseBox, { display: { type: 'error', message: 'I could not finish that. Try again in a moment.', details: friendly } });
            responseBox.classList.remove('hidden');

            speak('I could not finish that. Try again in a moment.');

        } finally {
            activeCommandController = null;
            jarvisWorking = false;
            jarvisPaused = false;

            window.jcVisualDemo?.setBusy(false);
            window.jcClarification?.setBusy(false);
            window.jcResponse?.setBusy(false);
            window.jcResponse?.finish(responseBox);

            input.disabled = false;
            send.disabled = false;
            input.value = '';

            if (!isJCSpeaking) {
                afterSpeakingOrIdle();
            }

            input.focus();
        }
    }

    // =====================================================================
    // EMAIL SELECTION
    // =====================================================================

    async function selectEmail(button, timeoutMs = 15000) {
        const uid = button.dataset.uid;

        if (!uid) {
            console.error('Missing email UID');
            return;
        }

        if (jarvisWorking) return;

        button.disabled = true;
        const originalHTML = button.innerHTML;

        button.innerHTML = `<div class="flex items-center gap-2 text-sm text-white/60">
            <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-white/60"></span>
            Opening email...
        </div>`;

        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);

        try {
            setAiState('thinking');
            setLabel('Opening email...');

            const response = await fetch(`/jarvis/email/${encodeURIComponent(uid)}`, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                signal: controller.signal,
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Unable to open this email.');
            }

            const email = data.email;
            if (!email) {
                throw new Error('Email data was not returned.');
            }

            email.uid = email.uid || uid;
            currentEmail = email;
            draftPending = false;

            openEmailConsole(email, `Here is the email from ${email.from_name || email.from || 'Unknown sender'}.`);

            setAiState(wakeModeEnabled ? 'listening' : 'idle');
            setLabel('Email opened');

            speak('The email is open.');

        } catch (error) {
            const message = error.name === 'AbortError' ? 'Email loading timed out.' : error.message;
            console.error('Unable to open email:', error);

            window.jcResponse?.render(responseBox, { display: { type: 'error', message: 'I could not open that email.', details: message } });
            responseBox.classList.remove('hidden');
            result.classList.remove('hidden');

            setAiState('idle');
            setLabel('Unable to open email');

            speak('Sorry, I could not open that email.');

        } finally {
            clearTimeout(timer);
            // Always restore the button (previously it stayed stuck on "Opening email...")
            button.innerHTML = originalHTML;
            button.disabled = false;
        }
    }

    window.selectEmail = selectEmail;

    // =====================================================================
    // DRAFT & SEND REPLY
    // =====================================================================

    async function draftReply() {
        if (!currentEmail) return;

        draftPending = true;
        draftBtn.disabled = true;

        setAiState('thinking');
        setLabel(`${getAiName()} is drafting a reply...`);
        draftStatus.textContent = 'Drafting...';

        try {
            const response = await fetch(`/jarvis/email/${encodeURIComponent(currentEmail.uid)}/draft-reply`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCSRFToken(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ conversation_id: conversationId }),
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || `${getAiName()} could not draft a reply.`);
            }

            replyText.value = data.draft || '';
            draftStatus.textContent = 'Draft ready — review, edit if needed, then send.';

            const spoken = 'I\'ve drafted a reply. Take a look, and send it whenever you\'re ready.';
            window.jcResponse?.render(responseBox, { display: { type: 'status', message: spoken } });
            speak(spoken);

        } catch (error) {
            draftStatus.textContent = '';
            window.jcResponse?.render(responseBox, { display: { type: 'error', message: 'I could not prepare that reply.', details: error.message } });
            speak('I could not prepare that reply. The details are on screen.');

        } finally {
            draftBtn.disabled = false;
            draftPending = false;
            afterSpeakingOrIdle();
        }
    }

    draftBtn.addEventListener('click', draftReply);

    async function sendReply() {
        if (!currentEmail) return;

        const body = replyText.value.trim();

        if (!body) {
            draftStatus.textContent = `Write a reply first, or ask ${getAiName()} to draft one.`;
            return;
        }

        sendReplyBtn.disabled = true;
        draftStatus.textContent = 'Sending...';

        setAiState('thinking');
        setLabel(`${getAiName()} is sending your reply...`);

        try {
            const response = await fetch(`/jarvis/email/${encodeURIComponent(currentEmail.uid)}/send-reply`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCSRFToken(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ body: body }),
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || `${getAiName()} could not send that reply.`);
            }

            draftStatus.textContent = 'Reply sent.';
            const spoken = 'Your reply has been sent.';
            window.jcResponse?.render(responseBox, { display: { type: 'status', message: spoken } });
            speak(spoken);

        } catch (error) {
            draftStatus.textContent = '';
            window.jcResponse?.render(responseBox, { display: { type: 'error', message: 'I could not send that reply.', details: error.message } });
            speak('I could not send that reply. The details are on screen.');

        } finally {
            sendReplyBtn.disabled = false;
            afterSpeakingOrIdle();
        }
    }

    sendReplyBtn.addEventListener('click', sendReply);

    // =====================================================================
    // AUTOMATIC EMAIL MONITOR
    // =====================================================================

    async function acknowledgeEmailNotifications(notifications) {
        const tokens = notifications.map(notification => notification.token).filter(Boolean);
        if (tokens.length === 0) return;

        const response = await fetch('/jarvis/notification/ack', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCSRFToken(),
            },
            body: JSON.stringify({ tokens }),
        });

        if (!response.ok) {
            throw new Error(`Notification acknowledgement failed with HTTP ${response.status}`);
        }
    }

    async function checkUnreadEmails() {
        if (jarvisWorking) return;

        try {
            const response = await fetch('/jarvis/notification', {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
            });

            if (!response.ok) return;

            const data = await response.json();

            if (!data.success || !Array.isArray(data.notifications)) return;

            const notifications = data.notifications;
            if (notifications.length === 0) return;

            debugLog('EMAIL', `Actionable unread email notifications: ${notifications.length}`);

            // Inbox drawer is now stale
            inboxLoaded = false;

            const presentation = window.jcResponse.emailAttentionResponse(notifications);

            // Display notification
            result.classList.remove('hidden');
            resultLabel.textContent = 'Email Needs Attention';
            window.jcResponse.render(responseBox, presentation);
            responseBox.classList.remove('hidden');

            emailView.classList.add('hidden');
            resultClose.classList.remove('hidden');

            setAiState('speaking');
            setLabel('Email needs your attention');
            speak(window.jcResponse.speechFor(presentation));

            await acknowledgeEmailNotifications(notifications);

        } catch (error) {
            // Rethrow so the scheduler can back off (network errors show up here as "Failed to fetch")
            debugLog('EMAIL', 'Monitor error', error.message);
            throw error;
        }
    }

    // Turn low-level fetch errors into something readable
    function describeFetchError(error) {
        if (error && error.name === 'AbortError') return 'The request timed out.';
        if (error instanceof TypeError) {
            return 'Cannot reach the server (network error). Check that the Laravel server is running and that you opened the page from the same address it is served on.';
        }
        return (error && error.message) || 'Unknown error';
    }
    window.jcDescribeFetchError = describeFetchError;

    // Self-scheduling monitor: never overlaps itself, skips hidden tabs,
    // and backs off (30s -> 60s -> 120s -> 5min) while the server is unreachable.
    const MONITOR_BASE_MS = 30000;
    const MONITOR_MAX_MS = 300000;
    let monitorFailures = 0;

    async function guardedEmailCheck() {
        if (!emailCheckInFlight && !document.hidden) {
            emailCheckInFlight = true;
            try {
                await checkUnreadEmails();
                monitorFailures = 0;
            } catch (error) {
                monitorFailures++;
                if (monitorFailures === 1 || monitorFailures % 5 === 0) {
                    console.warn('JC email monitor:', describeFetchError(error), `(failure #${monitorFailures})`);
                }
            } finally {
                emailCheckInFlight = false;
            }
        }

        const delay = Math.min(MONITOR_BASE_MS * Math.pow(2, monitorFailures), MONITOR_MAX_MS);
        setTimeout(guardedEmailCheck, delay);
    }

    // =====================================================================
    // INPUT HANDLERS
    // =====================================================================

    send.addEventListener('click', () => talkToJarvis());

    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            talkToJarvis();
        }
    });

    document.querySelectorAll('.jarvis-suggestion').forEach(button => {
        button.addEventListener('click', function () {
            if (jarvisWorking) return;
            input.value = button.dataset.prompt || button.textContent.trim();
            talkToJarvis();
        });
    });

    // =====================================================================
    // WAKE PHRASE DETECTION
    // =====================================================================

    function stripWakePhrase(transcript) {
        const words = [...transcript.matchAll(/[\p{L}\p{N}]+/gu)];
        const normalizedWords = words.map(([word]) => normalizeWakeText(word));

        for (const phrase of WAKE_PHRASES) {
            const phraseWords = phrase.split(' ');

            for (let start = 0; start <= normalizedWords.length - phraseWords.length; start++) {
                const matchesPhrase = phraseWords.every((word, offset) => {
                    const spokenWord = normalizedWords[start + offset];
                    const isNameWord = offset === phraseWords.length - 1;

                    return spokenWord === word || (isNameWord && matchesWakeNameWord(spokenWord, word));
                });

                if (matchesPhrase) {
                    const lastWord = words[start + phraseWords.length - 1];
                    const remainder = transcript
                        .slice(lastWord.index + lastWord[0].length)
                        .replace(/^[\s,.;:!?-]+/, '');

                    return { heard: true, remainder, phrase };
                }
            }
        }

        return { heard: false, remainder: '' };
    }

    function isLikelyValidCommand(text) {
        const cleaned = text.trim().replace(/[.,!?]+$/g, '').toLowerCase();

        if (cleaned.length < MIN_COMMAND_LENGTH) return false;

        const noiseWords = ['uh', 'um', 'hmm', 'hm', 'ah', 'oh', 'mm', 'mmm', 'ahem', 'cough'];

        if (noiseWords.includes(cleaned)) return false;

        // Filter out repetitive noise
        const words = cleaned.split(/\s+/);
        if (words.length >= 3 && words[0] === words[1] && words[1] === words[2]) {
            return false;
        }

        return true;
    }

    // =====================================================================
    // SPEECH RECOGNITION
    // =====================================================================

    function initRecognition() {
        if (!speechSupported) return null;

        const rec = new SpeechRecognitionAPI();
        rec.continuous = true;
        rec.interimResults = true;
        rec.lang = 'en-US';

        rec.onresult = function (event) {
            let finalTranscript = '';

            for (let i = event.resultIndex; i < event.results.length; i++) {
                const chunk = event.results[i][0].transcript;
                if (event.results[i].isFinal) {
                    finalTranscript += chunk;
                }
            }

            if (!finalTranscript) return;

            const transcript = finalTranscript.trim();

            // JC is working - only accept stop commands
            if (jarvisWorking) {
                if (isStopCommand(transcript)) {
                    stopJarvisWork();
                    return;
                }
                debugLog('VOICE', 'JC busy, ignoring:', transcript);
                return;
            }

            // Conversation mode
            if (conversationMode) {
                clearTimeout(conversationTimer);

                if (!isLikelyValidCommand(transcript)) return;

                input.value = transcript;
                listeningForCommand = true;
                talkToJarvis(transcript);
                return;
            }

            // Wake mode - listen for wake phrase
            const { heard, remainder, phrase } = stripWakePhrase(transcript);

            if (!heard) return;

            // Wake phrase + command
            if (remainder) {
                if (!isLikelyValidCommand(remainder)) return;

                startConversationMode();
                input.value = remainder;
                setAiState('thinking');
                setLabel(`${getAiName()} is thinking...`);
                talkToJarvis(remainder);
                return;
            }

            // Just wake phrase
            startConversationMode();

            if (phrase.startsWith('wake up ')) {
                setAiState('listening');
                setLabel("I'm listening...");
                checkUnreadEmails();
                return;
            }

            setAiState('listening');
            setLabel("I'm listening...");
        };

        rec.onerror = function (event) {
            console.warn('Speech recognition error:', event.error);

            switch (event.error) {
                case 'no-speech':
                    setLabel('No speech detected. Please try again.');
                    break;
                case 'audio-capture':
                    setLabel('Microphone not accessible.');
                    break;
                case 'network':
                    setLabel('Network error. Check your connection.');
                    break;
                default:
                    setLabel(`Speech error: ${event.error}`);
            }
        };

        rec.onend = function () {
            if (!wakeModeEnabled || isJCSpeaking) return;

            clearTimeout(restartTimer);

            restartTimer = setTimeout(() => {
                if (!wakeModeEnabled || isJCSpeaking) return;

                try {
                    rec.start();
                } catch (e) {
                    // Already running
                }
            }, 300);
        };

        return rec;
    }

    function resumeRecognitionIfNeeded() {
        if (!wakeModeEnabled || isJCSpeaking || jarvisWorking || !recognition) return;

        try {
            recognition.start();
        } catch (e) {
            // Already running
        }
    }

    // =====================================================================
    // VOICE MODE TOGGLE
    // =====================================================================

    function enableWakeMode() {
        if (!speechSupported) {
            setLabel('Voice recognition is not supported in this browser');
            return;
        }

        wakeModeEnabled = true;
        recognition = recognition || initRecognition();

        try {
            recognition.start();
        } catch (e) {
            // Already running
        }

        wakeToggle.classList.add('border-emerald-400/30', 'text-emerald-300');
        wakeToggle.setAttribute('aria-pressed', 'true');

        wakeDot.classList.remove('bg-white/20');
        wakeDot.classList.add('bg-emerald-400');

        wakeLabel.textContent = 'Voice On';

        setAiState('armed');
        setLabel(getWakePhraseLabel());

        debugLog('VOICE', 'Wake mode enabled');
    }

    function disableWakeMode() {
        wakeModeEnabled = false;
        listeningForCommand = false;
        conversationMode = false;

        clearTimeout(restartTimer);
        clearTimeout(conversationTimer);

        if (recognition) {
            try {
                recognition.stop();
            } catch (e) {
                // noop
            }
        }

        wakeToggle.classList.remove('border-emerald-400/30', 'text-emerald-300');
        wakeToggle.setAttribute('aria-pressed', 'false');

        wakeDot.classList.add('bg-white/20');
        wakeDot.classList.remove('bg-emerald-400');

        wakeLabel.textContent = 'Enable Voice';

        setAiState('idle');
        setLabel(getIdleLabel());

        debugLog('VOICE', 'Wake mode disabled');
    }

    wakeToggle.addEventListener('click', function () {
        if (wakeModeEnabled) {
            disableWakeMode();
        } else {
            enableWakeMode();
        }
    });

    // =====================================================================
    // INITIALIZATION
    // =====================================================================

    setAiState('idle');

    if (!speechSupported) {
        setLabel('Voice recognition is not supported in this browser — type your command below');
    }

    // Load current AI credit balance
    loadCreditBalance();

    // Refresh credit balance every 60 seconds
    setInterval(loadCreditBalance, 60000);

    // PERF: don't compete with page load. First check after 4s, then every 30s.
    setTimeout(guardedEmailCheck, 4000); // reschedules itself

    debugLog('APP', 'JC Assistant initialized');

})();
</script>

{{-- =====================================================================
     AMBIENT PARTICLE NETWORK (page background)
     ===================================================================== --}}
<script>
(function () {
    const canvas = document.getElementById('jc-network-bg');
    if (!canvas || !canvas.getContext) return;

    const ctx = canvas.getContext('2d');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const LINK_DIST = 130;
    const LINK_DIST_SQ = LINK_DIST * LINK_DIST;

    let width = 0;
    let height = 0;
    let points = [];

    function resize() {
        width = canvas.width = window.innerWidth;
        height = canvas.height = window.innerHeight;
    }

    function makePoints() {
        const count = Math.min(70, Math.floor((width * height) / 26000));
        points = Array.from({ length: count }, () => ({
            x: Math.random() * width,
            y: Math.random() * height,
            vx: (Math.random() - 0.5) * 0.12,
            vy: (Math.random() - 0.5) * 0.12,
        }));
    }

    function draw() {
        ctx.clearRect(0, 0, width, height);

        ctx.fillStyle = 'rgba(110, 231, 255, 0.55)';
        for (const p of points) {
            if (!reducedMotion) {
                p.x += p.vx;
                p.y += p.vy;

                if (p.x < 0 || p.x > width) p.vx *= -1;
                if (p.y < 0 || p.y > height) p.vy *= -1;
            }

            ctx.beginPath();
            ctx.arc(p.x, p.y, 1.2, 0, Math.PI * 2);
            ctx.fill();
        }

        ctx.strokeStyle = 'rgba(110, 231, 255, 0.35)';
        for (let i = 0; i < points.length; i++) {
            const a = points[i];
            for (let j = i + 1; j < points.length; j++) {
                const b = points[j];
                const dx = a.x - b.x;
                const dy = a.y - b.y;
                const d2 = dx * dx + dy * dy; // squared distance: no hypot in the hot loop

                if (d2 < LINK_DIST_SQ) {
                    ctx.globalAlpha = 1 - Math.sqrt(d2) / LINK_DIST;
                    ctx.beginPath();
                    ctx.moveTo(a.x, a.y);
                    ctx.lineTo(b.x, b.y);
                    ctx.stroke();
                }
            }
        }

        ctx.globalAlpha = 1;
    }

    function loop() {
        requestAnimationFrame(loop);
        if (document.hidden) return; // PERF: no work in background tabs
        draw();
    }

    window.addEventListener('resize', () => {
        resize();
        makePoints();
        if (reducedMotion) draw();
    });

    resize();
    makePoints();

    if (reducedMotion) {
        draw();
    } else {
        loop();
    }
})();
</script>

{{-- Avatar state and pulse hooks used by the voice interface. --}}
<script>
(() => {
    const wrap = document.getElementById('jarvis-avatar-wrap');
    const svg  = document.getElementById('jc-avatar-portrait');
    if (!wrap || !svg) return;

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const NS = 'http://www.w3.org/2000/svg';
    const q  = (sel) => svg.querySelector(sel);

    const track = q('#jc-avatar-track');
    const gazeL = q('.jc-gaze-l');
    const gazeR = q('.jc-gaze-r');
    const eyes  = q('.jc-avatar-eyes');
    const halo  = q('#jc-halo-ticks');
    const motes = q('#jc-motes');
    const bust  = q('.jc-avatar-bust');
    const orbit = q('.jc-avatar-orbit');

    const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
    const lerp  = (a, b, k) => a + (b - a) * k;

    let state = 'idle';
    let level = 0;            // 0..1 speech energy, decays every frame
    let lastPulse = -9999;    // last word-boundary time (ms)
    let lastPointer = -9999;  // last pointer move (ms)
    let pulseTimer;
    let frameNo = 0;
    let browKick = 0;         // brief brow raise on emphasis
    let bob = 0;              // brief head bob on stressed words

    const gaze = { x: 0, y: 0, tx: 0, ty: 0 };
    const head = { x: 0, y: 0, tx: 0, ty: 0 };
    const sac  = { x: 0, y: 0 };                       // micro-saccade offset
    const face = { wide: 1, open: 0, smile: 0.35, brow: 0, pupil: 1 };

    // Mood per state
    const LOOK = {
        idle:      { smile: 0.35, pupil: 1.00, brow: 0 },
        armed:     { smile: 0.30, pupil: 1.05, brow: 0.1 },
        listening: { smile: 0.55, pupil: 1.25, brow: 0.6 },
        thinking:  { smile: 0.00, pupil: 0.82, brow: -0.2 },
        speaking:  { smile: 0.45, pupil: 1.05, brow: 0.15 },
    };

    // ---------------------------------------------------------------
    // Visemes: [mouth openness, mouth width] per vowel
    // ---------------------------------------------------------------
    const VIS = { a: [1.0, 1.12], e: [0.55, 1.25], i: [0.35, 1.30], o: [0.85, 0.78], u: [0.60, 0.65] };
    const vis = { open: 0.12, wide: 1 };
    let visTimers = [];

    function playWord(word, ms) {
        visTimers.forEach(clearTimeout);
        visTimers = [];

        const found = word.toLowerCase().match(/[aeiouy]/g) || ['e'];
        const step = clamp(ms / found.length, 60, 150);

        found.forEach((v, i) => {
            visTimers.push(setTimeout(() => {
                const s = VIS[v === 'y' ? 'i' : v];
                vis.open = s[0];
                vis.wide = s[1];
            }, i * step));
        });

        visTimers.push(setTimeout(() => { vis.open = 0.12; vis.wide = 1; }, found.length * step));
    }

    // ---------------------------------------------------------------
    // Voice halo geometry: 270-degree ring, open at the bottom
    // ---------------------------------------------------------------
    const N = 64, CX = 450, CY = 265, R0 = 248;
    const A0 = 135 * Math.PI / 180, SPAN = 270 * Math.PI / 180;
    const COS = [], SIN = [];
    for (let i = 0; i < N; i++) {
        const a = A0 + SPAN * i / (N - 1);
        COS.push(Math.cos(a));
        SIN.push(Math.sin(a));
    }

    function drawHalo(t) {
        if (!halo) return;
        const time = t * 0.001;
        const sweep = state === 'thinking' ? (time * 22) % (N + 12) - 6 : -99;
        let d = '';

        for (let i = 0; i < N; i++) {
            const n   = 0.5 + 0.5 * Math.sin(i * 1.7 + time * 5) * Math.sin(i * 0.55 - time * 3.1);
            const env = 0.55 + 0.45 * Math.sin(Math.PI * i / (N - 1));
            let amp = 0.07 + 0.025 * Math.sin(time * 1.4 + i * 0.3);

            if (state === 'listening')      amp += 0.16 + 0.16 * n;
            else if (state === 'speaking')  amp += level * (0.35 + 0.65 * n) * 1.1;
            if (sweep > -50)                amp += 0.6 * Math.exp(-((i - sweep) * (i - sweep)) / 10);

            const len = 3 + 32 * clamp(amp * env, 0, 1);
            const x0 = CX + COS[i] * R0, y0 = CY + SIN[i] * R0;
            const x1 = CX + COS[i] * (R0 + len), y1 = CY + SIN[i] * (R0 + len);
            d += 'M' + x0.toFixed(1) + ' ' + y0.toFixed(1) + 'L' + x1.toFixed(1) + ' ' + y1.toFixed(1);
        }
        halo.setAttribute('d', d);
    }

    // ---------------------------------------------------------------
    // Rising motes (skipped for reduced motion)
    // ---------------------------------------------------------------
    if (motes && !reduced) {
        for (let i = 0; i < 14; i++) {
            const c = document.createElementNS(NS, 'circle');
            c.setAttribute('class', 'jc-mote');
            c.setAttribute('cx', (210 + Math.random() * 480).toFixed(0));
            c.setAttribute('cy', (760 + Math.random() * 20).toFixed(0));
            c.setAttribute('r', (1 + Math.random() * 1.6).toFixed(1));
            c.style.setProperty('--dur', (7 + Math.random() * 7).toFixed(1) + 's');
            c.style.setProperty('--delay', (-Math.random() * 12).toFixed(1) + 's');
            c.style.setProperty('--dx', ((Math.random() - 0.5) * 60).toFixed(0) + 'px');
            motes.appendChild(c);
        }
    }

    // ---------------------------------------------------------------
    // Gaze: follows the cursor, drifts on its own, looks up when thinking
    // ---------------------------------------------------------------
    window.addEventListener('pointermove', (e) => {
        const r = svg.getBoundingClientRect();
        const cx = r.left + r.width * 0.5;
        const cy = r.top + r.height * 0.3;
        head.tx = clamp((e.clientX - cx) / (window.innerWidth * 0.5), -1, 1);
        head.ty = clamp((e.clientY - cy) / (window.innerHeight * 0.6), -1, 1);
        lastPointer = performance.now();
    }, { passive: true });

    function idleDrift() {
        const idle = performance.now() - lastPointer > 3000;
        if (idle) {
            head.tx = (Math.random() - 0.5) * 0.7;
            head.ty = (Math.random() - 0.5) * 0.4;
        }
        setTimeout(idleDrift, 1500 + Math.random() * 2500);
    }

    // Micro-saccades: tiny snap movements of the eyes
    function saccade() {
        if (state !== 'thinking' && !document.hidden) {
            sac.x = (Math.random() - 0.5) * 0.5;
            sac.y = (Math.random() - 0.5) * 0.35;
            setTimeout(() => { sac.x = 0; sac.y = 0; }, 120 + Math.random() * 220);
        }
        setTimeout(saccade, 700 + Math.random() * 2200);
    }

    // ---------------------------------------------------------------
    // Blink at an irregular rhythm, occasionally twice
    // ---------------------------------------------------------------
    function blink() {
        if (!eyes) return;
        eyes.classList.add('jc-blink');
        setTimeout(() => eyes.classList.remove('jc-blink'), 110);
    }

    function scheduleBlink() {
        setTimeout(() => {
            if (!document.hidden) {
                blink();
                if (Math.random() < 0.18) setTimeout(blink, 240);
            }
            scheduleBlink();
        }, 2200 + Math.random() * 4200);
    }

    // ---------------------------------------------------------------
    // Hologram glitch: rare, and on wake
    // ---------------------------------------------------------------
    function glitch() {
        if (reduced) return;
        svg.classList.add('jc-glitch');
        setTimeout(() => svg.classList.remove('jc-glitch'), 280);
    }

    function scheduleGlitch() {
        setTimeout(() => {
            if (!document.hidden) glitch();
            scheduleGlitch();
        }, 9000 + Math.random() * 14000);
    }

    // ---------------------------------------------------------------
    // Main loop
    // ---------------------------------------------------------------
    function frame(t) {
        requestAnimationFrame(frame);
        if (document.hidden) return;
        frameNo++;

        // Gaze target depends on state
        if (state === 'thinking') { gaze.tx = -0.7; gaze.ty = -0.8; }
        else {
            const k = state === 'idle' || state === 'armed' ? 1 : 0.5; // look at the user when engaged
            gaze.tx = head.tx * k;
            gaze.ty = head.ty * k;
        }

        gaze.x += (gaze.tx - gaze.x) * 0.14;
        gaze.y += (gaze.ty - gaze.y) * 0.14;
        head.x += (head.tx - head.x) * 0.06;
        head.y += (head.ty - head.y) * 0.06;

        const gt = 'translate(' + ((gaze.x + sac.x) * 5).toFixed(2) + 'px,' + ((gaze.y + sac.y) * 3.2).toFixed(2) + 'px)';
        if (gazeL) gazeL.style.transform = gt;
        if (gazeR) gazeR.style.transform = gt;

        // Head tracking + bob on stressed words
        bob *= 0.9;
        if (track) {
            track.style.transform =
                'rotate(' + (head.x * 2.2).toFixed(2) + 'deg) translate(' +
                (head.x * 4).toFixed(2) + 'px,' + (head.y * 3 + bob * 2.2).toFixed(2) + 'px)';
        }

        // Depth parallax: bust drifts against the head, cage drifts with it
        if (bust)  bust.style.translate  = (-head.x * 5).toFixed(2) + 'px ' + (-head.y * 2).toFixed(2) + 'px';
        if (orbit) orbit.style.translate = (head.x * 8).toFixed(2) + 'px ' + (head.y * 4).toFixed(2) + 'px';

        // Speech energy: real word boundaries when available, synthetic fallback otherwise
        level *= 0.86;
        if (state === 'speaking' && t - lastPulse > 700) {
            const time = t * 0.001;
            level = Math.max(level, 0.3 + 0.25 * Math.abs(Math.sin(time * 9) * Math.sin(time * 5.3)));
        }

        const driven = t - lastPulse < 650;
        wrap.classList.toggle('jc-mouth-driven', driven);

        // Face: mood, mouth shape, brows, pupils
        const look = LOOK[state] || LOOK.idle;
        browKick *= 0.9;
        face.smile = lerp(face.smile, look.smile, 0.05);
        face.pupil = lerp(face.pupil, look.pupil, 0.08);
        face.brow  = lerp(face.brow, look.brow + browKick, 0.2);
        face.open  = lerp(face.open, driven ? vis.open : 0, 0.4);
        face.wide  = lerp(face.wide, driven ? vis.wide : (state === 'speaking' ? 1 + 0.1 * Math.sin(t * 0.011) : 1), 0.35);

        const s = wrap.style;
        s.setProperty('--jc-mouth', face.open.toFixed(3));
        s.setProperty('--jc-wide',  face.wide.toFixed(3));
        s.setProperty('--jc-smile', clamp(face.smile, 0, 1).toFixed(3));
        s.setProperty('--jc-brow',  face.brow.toFixed(3));
        s.setProperty('--jc-pupil', face.pupil.toFixed(3));
        s.setProperty('--jc-level', level.toFixed(3));

        // Halo: full rate while active, half rate at idle
        if (state !== 'idle' && state !== 'armed') drawHalo(t);
        else if (frameNo % 2 === 0) drawHalo(t);
    }

    // ---------------------------------------------------------------
    // Public API used by the main script
    // ---------------------------------------------------------------
    window.jcAvatar = {
        setState(next) {
            if (next === 'listening' && state !== 'listening') {
                face.smile = Math.min(1, face.smile + 0.35); // greeting smile, settles on its own
                glitch();                                     // "materialise" flicker on wake
            }
            state = next;
            wrap.dataset.state = next;
            if (next !== 'speaking') level = 0;
            if (reduced) drawHalo(0);
        },
        // pulse(event, fullText): call on each speech boundary
        pulse(ev, text) {
            lastPulse = performance.now();
            level = 0.55 + Math.random() * 0.45;
            bob = 0.5 + Math.random() * 0.5;

            if (ev && text && typeof ev.charIndex === 'number' && (!ev.name || ev.name === 'word')) {
                const rest = text.slice(ev.charIndex);
                const len = ev.charLength || (rest.search(/[\s,.!?;:]|$/) || 5);
                const word = rest.slice(0, len || 5);
                playWord(word, (len || 5) * 60);
                if (len > 6 || /[,.!?]/.test(rest.charAt(len))) browKick = 0.7; // emphasis
            } else if (Math.random() < 0.25) {
                browKick = 0.6;
            }

            wrap.classList.add('jc-avatar-pulse');
            window.clearTimeout(pulseTimer);
            pulseTimer = window.setTimeout(() => wrap.classList.remove('jc-avatar-pulse'), 420);
        },
    };

    // Pick up whatever state the main script set before this script loaded
    window.jcAvatar.setState(window.jcAiStateName || 'idle');

    if (reduced) {
        drawHalo(0);
    } else {
        idleDrift();
        scheduleBlink();
        saccade();
        scheduleGlitch();
        requestAnimationFrame(frame);
    }
})();
</script>

{{-- =====================================================================
     SETTINGS DRAWER + EMAIL ACCOUNTS
     ===================================================================== --}}
<script>
(function () {
    'use strict';

    const $ = (id) => document.getElementById(id);

    const drawer   = $('jc-settings-drawer');
    const backdrop = $('jc-settings-backdrop');
    const statusEl = $('jc-settings-status');
    const CSRF     = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const DEBUG    = localStorage.getItem('jc_debug') === 'true';

    if (!drawer) return;

    const debugLog = (msg, data) => { if (DEBUG) console.log(`[Settings] ${msg}`, data ?? ''); };

    // =====================================================================
    // SHARED HELPERS
    // =====================================================================

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    }

    function getErrorMessages(data) {
        if (data?.errors && typeof data.errors === 'object') {
            return Object.values(data.errors)
                .map((m) => (Array.isArray(m) ? m[0] : m))
                .join('\n');
        }
        return data?.message || data?.error || 'Unknown error';
    }

    function friendlyError(error) {
        if (typeof window.jcDescribeFetchError === 'function') return window.jcDescribeFetchError(error);
        return (error && error.message) || 'Unknown error';
    }

    let statusTimer = null;
    function showStatus(message, isError = false, duration = 3500) {
        if (!statusEl) return;
        clearTimeout(statusTimer);
        statusEl.textContent = message;
        statusEl.title = message;
        statusEl.style.color = isError ? '#f87171' : '#a3e635';
        if (!isError && duration > 0) {
            statusTimer = setTimeout(() => {
                if (statusEl.textContent === message) statusEl.textContent = '';
            }, duration);
        }
    }

    /**
     * One fetch wrapper for every settings call. Handles CSRF, session expiry (419),
     * HTML error pages (which used to surface as "Unexpected token <") and Laravel 422 errors.
     */
    async function api(url, { method = 'GET', body } = {}) {
        const headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (method !== 'GET') {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-TOKEN'] = CSRF;
        }

        const response = await fetch(url, {
            method,
            headers,
            body: body !== undefined ? JSON.stringify(body) : undefined,
            cache: method === 'GET' ? 'no-store' : undefined,
            credentials: 'same-origin',
        });

        if (response.status === 419) throw new Error('Your session expired. Reload the page and try again.');
        if (response.status === 401) throw new Error('You are signed out. Reload the page and sign in again.');

        const text = await response.text();
        let data = null;
        try { data = text ? JSON.parse(text) : {}; } catch (e) { data = null; }

        if (data === null) throw new Error(`The server returned an unexpected response (HTTP ${response.status}).`);
        if (!response.ok || data.success === false) throw new Error(getErrorMessages(data));

        return data;
    }

    function markInvalid(id, on = true) {
        $(id)?.classList.toggle('is-invalid', on);
    }

    function clearInvalid(scope) {
        (scope || drawer).querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
    }

    drawer.addEventListener('input', (e) => e.target.classList?.remove('is-invalid'));

    function setBusy(button, busy, busyText) {
        if (!button) return;
        if (busy) {
            button.dataset.label = button.textContent;
            button.textContent = busyText;
            button.disabled = true;
        } else {
            button.textContent = button.dataset.label || button.textContent;
            button.disabled = false;
        }
    }

    const splitList = (value) => String(value || '').split(/[,\n]/).map((v) => v.trim()).filter(Boolean);

    // =====================================================================
    // TABS (keyboard accessible)
    // =====================================================================

    const tabs = Array.from(document.querySelectorAll('.jc-tab'));

    function activateTab(tabName, focus = false) {
        tabs.forEach((t) => {
            const active = t.dataset.tab === tabName;
            t.setAttribute('aria-selected', active ? 'true' : 'false');
            t.tabIndex = active ? 0 : -1;
            if (active && focus) t.focus();
        });
        document.querySelectorAll('.jc-tab-panel').forEach((panel) => {
            panel.classList.toggle('hidden', panel.dataset.panel !== tabName);
        });
    }

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activateTab(tab.dataset.tab));
        tab.addEventListener('keydown', (e) => {
            let next = null;
            if (e.key === 'ArrowRight') next = (index + 1) % tabs.length;
            else if (e.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
            else if (e.key === 'Home') next = 0;
            else if (e.key === 'End') next = tabs.length - 1;
            if (next === null) return;
            e.preventDefault();
            activateTab(tabs[next].dataset.tab, true);
        });
    });

    // =====================================================================
    // GENERAL SETTINGS: READ / VALIDATE / FILL
    // =====================================================================

    let lastSettings = null;   // last copy from the server
    let baseline = '';         // serialized form state used for the unsaved-changes check

    function readForm() {
        const rawLimit = ($('jc-set-credit-limit')?.value || '').trim();
        return {
            ai_name: $('jc-set-ai-name')?.value.trim() || '',
            owner_name: $('jc-set-owner-name')?.value.trim() || '',
            api_key: $('jc-set-api-key')?.value.trim() || '',
            allowed_actions: Array.from(document.querySelectorAll('.jc-scope-cb:checked')).map((cb) => cb.value),
            confirm_before_send: $('jc-set-confirm-send')?.checked ?? true,
            tone: $('jc-set-tone')?.value || 'professional',
            capability_demonstrations: $('jc-set-capability-demonstrations')?.value || 'ask_first',
            daily_credit_limit: rawLimit === '' ? 0 : parseInt(rawLimit, 10),
            scope_of_work: $('jc-set-scope')?.value.trim() || '',
            limitations: $('jc-set-limits')?.value.trim() || '',
            learned_memory: $('jc-set-learned-memory')?.value.trim() || '',
            wake_name_variants: splitList($('jc-set-wake-variants')?.value),
        };
    }

    const isDirty = () => baseline !== '' && JSON.stringify(readForm()) !== baseline;
    const snapshot = () => { baseline = JSON.stringify(readForm()); };

    function fail(tab, id, message) {
        activateTab(tab);
        markInvalid(id);
        showStatus('✗ ' + message, true, 0);
        $(id)?.focus();
        return false;
    }

    function validateSettings() {
        clearInvalid();
        const f = readForm();

        if (f.ai_name.length > 30)    return fail('identity', 'jc-set-ai-name', 'AI name must be 30 characters or less.');
        if (f.owner_name.length > 60) return fail('identity', 'jc-set-owner-name', 'Owner name must be 60 characters or less.');
        if (f.learned_memory.length > 4000) return fail('identity', 'jc-set-learned-memory', 'Saved memories are limited to 4000 characters.');

        if (!Number.isInteger(f.daily_credit_limit) || f.daily_credit_limit < 0) {
            return fail('scope', 'jc-set-credit-limit', 'Daily credit limit must be zero or a positive whole number.');
        }

        if (f.api_key && !/^sk-ant-[A-Za-z0-9_\-]{20,}$/.test(f.api_key)) {
            return fail('apikey', 'jc-set-api-key', 'That does not look like an Anthropic API key (it should start with sk-ant-).');
        }

        return true;
    }

    function updateScopeWarning() {
        const canSend = ['send_replies', 'send_emails'].some((v) => document.querySelector(`.jc-scope-cb[value="${v}"]`)?.checked);
        const confirm = $('jc-set-confirm-send')?.checked;
        $('jc-scope-warning')?.classList.toggle('hidden', !(canSend && !confirm));
    }

    function updateMemoryCount() {
        const el = $('jc-memory-count');
        if (el) el.textContent = String($('jc-set-learned-memory')?.value.length || 0);
    }

    document.querySelectorAll('.jc-scope-cb').forEach((cb) => cb.addEventListener('change', updateScopeWarning));
    $('jc-set-confirm-send')?.addEventListener('change', updateScopeWarning);
    $('jc-set-learned-memory')?.addEventListener('input', updateMemoryCount);

    function applyNames(aiName, ownerName, wakeNameVariants = []) {
        const name = (aiName || 'JC').trim() || 'JC';

        document.querySelectorAll('[data-ai-name]').forEach((el) => { el.textContent = name; });
        document.title = name + ' - AI Assistant';

        const input = $('jarvis-input');
        if (input) {
            input.placeholder = `Ask ${name} anything...`;
            input.setAttribute('aria-label', `Ask ${name} anything`);
        }
        $('jarvis-result')?.setAttribute('aria-label', `${name} Response`);
        $('jarvis-wake-toggle')?.setAttribute('title', `Enable always-listening for "Hello ${name}"`);
        const reply = $('jarvis-reply-text');
        if (reply) reply.placeholder = `Type your reply, or say "proceed" and ${name} will draft one`;
        const draftButton = $('jarvis-draft-btn');
        if (draftButton) draftButton.textContent = `Ask ${name} to draft`;

        window.jcAiName = name;
        window.jcOwnerName = ownerName || '';

        localStorage.setItem('jc_ai_name', name);
        localStorage.setItem('jc_owner_name', ownerName || '');

        if (typeof window.jcSetWakeName === 'function') window.jcSetWakeName(name, wakeNameVariants);
    }

    function setVal(id, value) { const el = $(id); if (el) el.value = value; }

    function fillForm(settings) {
        if (!settings) return;
        lastSettings = settings;

        setVal('jc-set-ai-name', settings.ai_name || 'JC');
        setVal('jc-set-owner-name', settings.owner_name || '');
        setVal('jc-set-tone', settings.tone || 'professional');
        setVal('jc-set-capability-demonstrations', settings.capability_demonstrations || 'ask_first');
        setVal('jc-set-credit-limit', settings.daily_credit_limit || 0);
        setVal('jc-set-scope', settings.scope_of_work || '');
        setVal('jc-set-limits', settings.limitations || '');
        setVal('jc-set-learned-memory', settings.learned_memory || '');
        setVal('jc-set-wake-variants', (settings.wake_name_variants || []).join(', '));

        const confirmBox = $('jc-set-confirm-send');
        if (confirmBox) confirmBox.checked = settings.confirm_before_send !== false;

        if (Array.isArray(settings.allowed_actions)) {
            document.querySelectorAll('.jc-scope-cb').forEach((cb) => {
                cb.checked = settings.allowed_actions.includes(cb.value);
            });
        }

        const keyStatus = $('jc-key-status');
        if (keyStatus) {
            if (settings.has_api_key) {
                keyStatus.textContent = `✓ Key saved (${settings.api_key_hint || 'configured'}). Enter a new one to replace it.`;
                keyStatus.style.color = '#34d399';
            } else {
                keyStatus.textContent = 'No key saved.';
                keyStatus.style.color = '#9ca3af';
            }
        }
        setVal('jc-set-api-key', ''); // the real key is never populated

        const workflowLink = $('jc-workflow-link');
        if (workflowLink && settings.workflow_url) workflowLink.href = settings.workflow_url;

        applyNames(settings.ai_name, settings.owner_name, settings.wake_name_variants || []);
        updateScopeWarning();
        updateMemoryCount();
        clearInvalid();
        snapshot();
    }

    // =====================================================================
    // LOAD / SAVE GENERAL SETTINGS
    // =====================================================================

    let settingsLoading = false;

    async function loadSettings() {
        if (settingsLoading) return null;
        settingsLoading = true;

        try {
            const data = await api('/jarvis/settings');
            if (data.settings) fillForm(data.settings);
            return data;
        } catch (error) {
            console.error('[Settings] Error loading settings:', error);
            showStatus('✗ ' + friendlyError(error), true, 0);

            const keyStatus = $('jc-key-status');
            if (keyStatus) {
                keyStatus.textContent = 'Could not load key status.';
                keyStatus.style.color = '#f87171';
            }
            return null;
        } finally {
            settingsLoading = false;
        }
    }

    $('jc-settings-save')?.addEventListener('click', async () => {
        if (!validateSettings()) return;

        const button = $('jc-settings-save');
        const payload = readForm();

        debugLog('Saving settings', { ...payload, api_key: payload.api_key ? '[REDACTED]' : '' });

        setBusy(button, true, 'Saving...');
        showStatus('Saving settings...', false, 0);

        try {
            const data = await api('/jarvis/settings', { method: 'POST', body: payload });

            if (data.settings) {
                fillForm(data.settings);
            } else {
                setVal('jc-set-api-key', '');
                snapshot();
            }

            showStatus('✓ Settings saved.');
        } catch (error) {
            console.error('Settings save error:', error);
            showStatus('✗ ' + friendlyError(error), true, 0);
        } finally {
            setBusy(button, false);
        }
    });

    // Live wake-name preview while typing (reverted if the drawer is closed without saving)
    function previewWakeName() {
        if (typeof window.jcSetWakeName !== 'function') return;
        window.jcSetWakeName($('jc-set-ai-name')?.value.trim() || 'JC', splitList($('jc-set-wake-variants')?.value));
    }
    $('jc-set-ai-name')?.addEventListener('input', previewWakeName);
    $('jc-set-wake-variants')?.addEventListener('input', previewWakeName);

    // =====================================================================
    // API KEY VISIBILITY
    // =====================================================================

    function bindToggle(buttonId, inputId) {
        $(buttonId)?.addEventListener('click', () => {
            const el = $(inputId);
            const btn = $(buttonId);
            if (!el || !btn) return;
            const hide = el.type === 'text';
            el.type = hide ? 'password' : 'text';
            btn.textContent = hide ? 'Show' : 'Hide';
        });
    }
    bindToggle('jc-toggle-key', 'jc-set-api-key');
    bindToggle('jc-email-pw-toggle', 'jc-email-password');

    // =====================================================================
    // EMAIL ACCOUNTS
    // =====================================================================

    const PRESETS = {
        godaddy: {
            imap: ['imap.secureserver.net', 993, 'ssl'],
            smtp: ['smtpout.secureserver.net', 465, 'ssl'],
            hint: 'Use the full mailbox address and its password.',
        },
        microsoft365: {
            imap: ['outlook.office365.com', 993, 'ssl'],
            smtp: ['smtp.office365.com', 587, 'tls'],
            hint: 'Your tenant must allow IMAP and SMTP basic authentication.',
        },
        gmail: {
            imap: ['imap.gmail.com', 993, 'ssl'],
            smtp: ['smtp.gmail.com', 465, 'ssl'],
            hint: 'Use an app password (requires 2-step verification), not your normal Google password.',
        },
        custom: { imap: null, smtp: null, hint: 'Enter the server details from your email provider.' },
    };

    let emailAccounts = [];
    let accountsLoading = false;

    function applyPreset(key) {
        const preset = PRESETS[key] || PRESETS.custom;
        if (preset.imap) {
            setVal('jc-email-imap-host', preset.imap[0]);
            setVal('jc-email-imap-port', preset.imap[1]);
            setVal('jc-email-imap-encryption', preset.imap[2]);
        }
        if (preset.smtp) {
            setVal('jc-email-smtp-host', preset.smtp[0]);
            setVal('jc-email-smtp-port', preset.smtp[1]);
            setVal('jc-email-smtp-encryption', preset.smtp[2]);
        }
        const hint = $('jc-email-provider-hint');
        if (hint) hint.textContent = preset.hint;
        updateEncryptionWarning();
    }

    function detectPreset(account) {
        const found = Object.entries(PRESETS).find(([key, p]) => p.imap && p.imap[0] === account.imap_host);
        return found ? found[0] : 'custom';
    }

    function updateEncryptionWarning() {
        const insecure = $('jc-email-imap-encryption')?.value === 'none' || $('jc-email-smtp-encryption')?.value === 'none';
        $('jc-email-enc-warning')?.classList.toggle('hidden', !insecure);
    }

    $('jc-email-provider')?.addEventListener('change', (e) => applyPreset(e.target.value));
    $('jc-email-imap-encryption')?.addEventListener('change', updateEncryptionWarning);
    $('jc-email-smtp-encryption')?.addEventListener('change', updateEncryptionWarning);

    // Header chip + inbox drawer line reflect the account JC will actually use
    function pickActiveAccount() {
        return emailAccounts.find((a) => a.is_default && a.is_active)
            || emailAccounts.find((a) => a.is_active)
            || emailAccounts.find((a) => a.is_default)
            || null;
    }

    function updateMailboxIndicators(loadFailed = false) {
        const chipText = $('jc-mailbox-chip-text');
        const dot = $('jc-mailbox-dot');
        const chip = $('jc-mailbox-chip');
        const inboxLine = $('jc-inbox-mailbox');
        const badge = $('jc-mailbox-chip-badge');

        let text, color, title;

        if (loadFailed) {
            text = 'Mailbox unavailable'; color = '#f87171'; title = 'Could not load email accounts. Click to retry.';
        } else {
            const account = pickActiveAccount();
            if (!account) {
                text = 'No email registered'; color = '#6b7280'; title = 'No email registered. Click to add one.';
            } else if (!account.is_active) {
                text = account.email; color = '#fb923c'; title = `Mailbox ${account.email} is inactive. Click to change.`;
            } else {
                text = account.email; color = '#34d399'; title = `Active mailbox: ${account.email}. Click to change.`;
            }
        }

        if (chipText) chipText.textContent = text;
        if (dot) dot.style.background = color;
        if (chip) chip.title = title;
        if (inboxLine) inboxLine.textContent = loadFailed || text === 'No email registered' ? text : `Mailbox: ${text}`;

        if (badge) {
            const extra = emailAccounts.length - 1;
            badge.textContent = `+${extra}`;
            badge.classList.toggle('hidden', loadFailed || extra < 1);
        }
    }

    async function loadEmailAccounts() {
        if (accountsLoading) return;
        accountsLoading = true;

        const list = $('jc-email-accounts-list');
        if (list && !emailAccounts.length) {
            list.innerHTML = '<div class="py-6 text-center text-xs text-white/30">Loading email accounts...</div>';
        }

        try {
            const data = await api('/jarvis/email-accounts');
            emailAccounts = Array.isArray(data.accounts) ? data.accounts : [];
            renderEmailAccounts();
            updateMailboxIndicators();
        } catch (error) {
            console.error('[Email Accounts] Error loading accounts:', error);
            if (list) {
                list.innerHTML = `
                    <div class="jc-notice jc-notice-err text-center">
                        <div>Could not load email accounts.</div>
                        <div class="mt-1 text-[10px] opacity-70">${escapeHtml(friendlyError(error))}</div>
                        <button type="button" class="jc-btn jc-btn-sm mt-3" data-action="retry">Retry</button>
                    </div>`;
            }
            updateMailboxIndicators(true);
        } finally {
            accountsLoading = false;
        }
    }

    function renderEmailAccounts() {
        const list = $('jc-email-accounts-list');
        if (!list) return;

        if (!emailAccounts.length) {
            list.innerHTML = `
                <div class="rounded-lg border border-white/10 bg-white/[0.02] px-4 py-6 text-center">
                    <div class="text-xs text-white/50">No email accounts configured.</div>
                    <div class="mt-1 text-[10px] text-white/25">Add an account so JC can read and reply to email.</div>
                </div>`;
            return;
        }

        list.innerHTML = emailAccounts.map((a) => {
            const id = escapeHtml(a.id);
            return `
            <div class="rounded-lg border border-white/10 bg-white/[0.025] p-4" data-account-id="${id}">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="min-w-0 truncate text-sm font-medium text-white">${escapeHtml(a.name || 'Unnamed account')}</div>
                    ${a.is_default ? '<span class="rounded-full bg-cyan-400/10 px-2 py-0.5 text-[9px] uppercase tracking-wider text-cyan-300">Default</span>' : ''}
                    ${a.is_active
                        ? '<span class="rounded-full bg-emerald-400/10 px-2 py-0.5 text-[9px] uppercase tracking-wider text-emerald-300">Active</span>'
                        : '<span class="rounded-full bg-white/5 px-2 py-0.5 text-[9px] uppercase tracking-wider text-white/30">Inactive</span>'}
                </div>
                <div class="mt-1 truncate text-xs text-white/50">${escapeHtml(a.email || '')}</div>

                <div class="mt-3 grid grid-cols-2 gap-2 text-[10px]">
                    <div class="rounded-md border border-white/5 bg-black/10 px-2 py-2">
                        <div class="uppercase tracking-wider text-white/25">IMAP</div>
                        <div class="mt-1 truncate text-white/50">${escapeHtml(a.imap_host || '-')}:${escapeHtml(a.imap_port || '-')}</div>
                    </div>
                    <div class="rounded-md border border-white/5 bg-black/10 px-2 py-2">
                        <div class="uppercase tracking-wider text-white/25">SMTP</div>
                        <div class="mt-1 truncate text-white/50">${escapeHtml(a.smtp_host || '-')}:${escapeHtml(a.smtp_port || '-')}</div>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="jc-btn jc-btn-sm" data-action="edit" data-id="${id}">Edit</button>
                    ${a.is_default ? '' : `<button type="button" class="jc-btn jc-btn-sm" data-action="default" data-id="${id}">Make default</button>`}
                    <button type="button" class="jc-btn jc-btn-sm jc-btn-danger" data-action="delete" data-id="${id}">Remove</button>
                </div>
            </div>`;
        }).join('');
    }

    // One delegated handler instead of re-binding after every render
    $('jc-email-accounts-list')?.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-action]');
        if (!btn) return;
        const { action, id } = btn.dataset;

        if (action === 'retry')   return loadEmailAccounts();
        if (action === 'edit')    return openEmailForm(emailAccounts.find((a) => String(a.id) === String(id)));
        if (action === 'default') return makeDefault(id, btn);
        if (action === 'delete')  return deleteEmailAccount(id);
    });

    // ---------- form ----------

    function hideTestResult() {
        const result = $('jc-email-test-result');
        if (!result) return;
        result.className = 'hidden';
        result.textContent = '';
    }

    function showTestResult(kind, text) {
        const result = $('jc-email-test-result');
        if (!result) return;
        result.className = `jc-notice jc-notice-${kind}`;
        result.textContent = text;
    }

    function openEmailForm(account = null) {
        const form = $('jc-email-account-form');
        if (!form) return;

        clearInvalid(form);
        hideTestResult();
        form.classList.remove('hidden');

        const editing = Boolean(account);
        $('jc-email-form-title').textContent = editing ? 'Edit email account' : 'Add email account';
        setVal('jc-email-id', editing ? account.id : '');

        if (editing) {
            setVal('jc-email-name', account.name || '');
            setVal('jc-email-provider', detectPreset(account));
            setVal('jc-email-address', account.email || '');
            setVal('jc-email-imap-host', account.imap_host || '');
            setVal('jc-email-imap-port', account.imap_port || 993);
            setVal('jc-email-imap-encryption', account.imap_encryption || 'ssl');
            setVal('jc-email-smtp-host', account.smtp_host || '');
            setVal('jc-email-smtp-port', account.smtp_port || 465);
            setVal('jc-email-smtp-encryption', account.smtp_encryption || 'ssl');
            $('jc-email-active').checked = Boolean(account.is_active);
            $('jc-email-default').checked = Boolean(account.is_default);
            const hint = $('jc-email-provider-hint');
            if (hint) hint.textContent = (PRESETS[detectPreset(account)] || PRESETS.custom).hint;
            updateEncryptionWarning();
        } else {
            setVal('jc-email-name', '');
            setVal('jc-email-provider', 'godaddy');
            setVal('jc-email-address', '');
            applyPreset('godaddy');
            $('jc-email-active').checked = true;
            $('jc-email-default').checked = emailAccounts.length === 0; // first account becomes the default
        }

        setVal('jc-email-password', '');
        $('jc-email-password').type = 'password';
        $('jc-email-pw-toggle').textContent = 'Show';
        $('jc-email-pw-hint').textContent = editing
            ? 'Leave blank to keep the saved password.'
            : 'The password is encrypted before being stored.';
        $('jc-email-password').placeholder = editing ? 'Unchanged' : 'Mailbox password';

        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        $('jc-email-name')?.focus();
    }

    function closeEmailForm() {
        $('jc-email-account-form')?.classList.add('hidden');
        setVal('jc-email-password', '');
        setVal('jc-email-id', '');
        hideTestResult();
    }

    $('jc-email-add')?.addEventListener('click', () => openEmailForm());
    $('jc-email-cancel')?.addEventListener('click', closeEmailForm);

    function readEmailForm() {
        const email = $('jc-email-address')?.value.trim() || '';
        const password = $('jc-email-password')?.value || '';
        const id = $('jc-email-id')?.value || '';

        const payload = {
            name: $('jc-email-name')?.value.trim() || '',
            email,
            imap_host: $('jc-email-imap-host')?.value.trim() || '',
            imap_port: parseInt($('jc-email-imap-port')?.value, 10),
            imap_encryption: $('jc-email-imap-encryption')?.value,
            imap_username: email,
            smtp_host: $('jc-email-smtp-host')?.value.trim() || '',
            smtp_port: parseInt($('jc-email-smtp-port')?.value, 10),
            smtp_encryption: $('jc-email-smtp-encryption')?.value,
            smtp_username: email,
            is_active: $('jc-email-active')?.checked ? 1 : 0,
            is_default: $('jc-email-default')?.checked ? 1 : 0,
        };

        // When editing, a blank password means "keep the stored one", so it is not sent at all
        if (password) {
            payload.imap_password = password;
            payload.smtp_password = password;
        }

        return { id, payload, hasPassword: Boolean(password) };
    }

    function validateEmailForm({ id, payload, hasPassword }, { forTest = false } = {}) {
        const form = $('jc-email-account-form');
        clearInvalid(form);

        const bad = (field, message) => {
            markInvalid(field);
            showStatus('✗ ' + message, true, 0);
            $(field)?.focus();
            return false;
        };

        const validPort = (p) => Number.isInteger(p) && p >= 1 && p <= 65535;

        if (!forTest && !payload.name) return bad('jc-email-name', 'Account name is required.');
        if (!payload.email) return bad('jc-email-address', 'Email address is required.');
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(payload.email)) return bad('jc-email-address', 'Enter a valid email address.');
        if (!id && !hasPassword) return bad('jc-email-password', 'Mailbox password is required.');
        if (!payload.imap_host) return bad('jc-email-imap-host', 'IMAP host is required.');
        if (!validPort(payload.imap_port)) return bad('jc-email-imap-port', 'IMAP port must be between 1 and 65535.');
        if (!payload.smtp_host) return bad('jc-email-smtp-host', 'SMTP host is required.');
        if (!validPort(payload.smtp_port)) return bad('jc-email-smtp-port', 'SMTP port must be between 1 and 65535.');

        return true;
    }

    $('jc-email-test')?.addEventListener('click', async () => {
        const button = $('jc-email-test');
        const form = readEmailForm();
        if (!validateEmailForm(form, { forTest: true })) return;

        // For a saved account with no new password, ask the server to use the stored one
        const body = form.id ? { ...form.payload, id: form.id } : form.payload;

        setBusy(button, true, 'Testing...');
        showTestResult('info', 'Testing IMAP and SMTP connection...');

        try {
            const data = await api('/jarvis/email-accounts/test', { method: 'POST', body });
            showTestResult('ok', '✓ ' + (data.message || 'Email connection successful.'));
        } catch (error) {
            console.error('[Email Accounts] Test connection error:', error);
            showTestResult('err', '✗ ' + friendlyError(error));
        } finally {
            setBusy(button, false);
        }
    });

    $('jc-email-save')?.addEventListener('click', async () => {
        const button = $('jc-email-save');
        const form = readEmailForm();
        if (!validateEmailForm(form)) return;

        const editing = Boolean(form.id);
        const url = editing ? `/jarvis/email-accounts/${encodeURIComponent(form.id)}` : '/jarvis/email-accounts';

        setBusy(button, true, 'Saving...');

        try {
            await api(url, { method: editing ? 'PUT' : 'POST', body: form.payload });
            closeEmailForm();
            showStatus(editing ? '✓ Email account updated.' : '✓ Email account saved.');
            await loadEmailAccounts();
        } catch (error) {
            console.error('[Email Accounts] Save error:', error);
            showStatus('✗ ' + friendlyError(error), true, 0);
        } finally {
            setBusy(button, false);
        }
    });

    async function makeDefault(id, button) {
        const account = emailAccounts.find((a) => String(a.id) === String(id));
        if (!account) return;

        if (button) button.disabled = true;

        try {
            await api(`/jarvis/email-accounts/${encodeURIComponent(id)}`, {
                method: 'PUT',
                body: {
                    name: account.name,
                    email: account.email,
                    imap_host: account.imap_host, imap_port: account.imap_port, imap_encryption: account.imap_encryption,
                    imap_username: account.email,
                    smtp_host: account.smtp_host, smtp_port: account.smtp_port, smtp_encryption: account.smtp_encryption,
                    smtp_username: account.email,
                    is_active: 1,
                    is_default: 1,
                },
            });
            showStatus('✓ Default account updated.');
            await loadEmailAccounts();
        } catch (error) {
            console.error('[Email Accounts] Make default error:', error);
            showStatus('✗ ' + friendlyError(error), true, 0);
            if (button) button.disabled = false;
        }
    }

    async function deleteEmailAccount(id) {
        const account = emailAccounts.find((a) => String(a.id) === String(id));
        if (!account) return;

        if (!confirm(`Remove "${account.name}" (${account.email})?\n\nJC will no longer be able to use this mailbox.`)) return;

        try {
            await api(`/jarvis/email-accounts/${encodeURIComponent(id)}`, { method: 'DELETE' });
            if ($('jc-email-id')?.value === String(id)) closeEmailForm();
            showStatus('✓ Email account removed.');
            await loadEmailAccounts();
        } catch (error) {
            console.error('[Email Accounts] Delete error:', error);
            showStatus('✗ ' + friendlyError(error), true, 0);
        }
    }

    // =====================================================================
    // DRAWER OPEN / CLOSE (focus handling, unsaved changes, Escape)
    // =====================================================================

    let isOpen = false;
    let lastTrigger = null;

    function openSettings(tabName) {
        if (typeof tabName === 'string') activateTab(tabName);

        lastTrigger = document.activeElement;
        isOpen = true;

        drawer.removeAttribute('inert');
        drawer.classList.remove('translate-x-full');
        drawer.setAttribute('aria-hidden', 'false');
        backdrop?.classList.remove('hidden');

        // Don't overwrite what the user is typing if the drawer is re-opened mid-edit
        if (!isDirty()) loadSettings();
        loadEmailAccounts();

        setTimeout(() => $('jc-settings-close')?.focus(), 50);
    }

    function closeSettings({ force = false } = {}) {
        if (!isOpen) return;

        if (!force && isDirty() && !confirm('You have unsaved changes. Close without saving?')) return;

        // Roll back any unsaved live wake-name preview
        if (isDirty() && lastSettings) {
            applyNames(lastSettings.ai_name, lastSettings.owner_name, lastSettings.wake_name_variants || []);
            fillForm(lastSettings);
        }

        isOpen = false;
        drawer.classList.add('translate-x-full');
        drawer.setAttribute('aria-hidden', 'true');
        drawer.setAttribute('inert', '');
        backdrop?.classList.add('hidden');

        setVal('jc-set-api-key', '');
        if (lastTrigger && typeof lastTrigger.focus === 'function') lastTrigger.focus();
    }

    $('jc-settings-btn')?.addEventListener('click', () => openSettings());
    $('jc-mailbox-chip')?.addEventListener('click', () => openSettings('email'));
    $('jc-settings-close')?.addEventListener('click', () => closeSettings());
    backdrop?.addEventListener('click', () => closeSettings());

    document.addEventListener('keydown', (e) => {
        if (!isOpen) return;

        if (e.key === 'Escape') {
            e.preventDefault();
            closeSettings();
            return;
        }

        // Keep Tab inside the dialog
        if (e.key === 'Tab') {
            const focusable = Array.from(drawer.querySelectorAll(
                'button:not([disabled]), [href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )).filter((el) => el.offsetParent !== null);

            if (!focusable.length) return;

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });

    window.addEventListener('beforeunload', (e) => {
        if (isOpen && isDirty()) { e.preventDefault(); e.returnValue = ''; }
    });

    window.jcOpenSettings = openSettings;
    window.jcCloseSettings = closeSettings;

    // =====================================================================
    // INITIAL LOAD: header chip and saved AI name without opening the drawer
    // =====================================================================

    function initialLoad() {
        setTimeout(() => {
            loadSettings();
            loadEmailAccounts();
        }, 500);
    }

    if (document.readyState === 'complete') initialLoad();
    else window.addEventListener('load', initialLoad, { once: true });

    debugLog('Settings module initialized');
})();
</script>

</body>
</html>
