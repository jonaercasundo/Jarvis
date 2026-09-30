#!/usr/bin/env node
/*
 * Restyles the JC avatar SVG as smooth brushed steel.
 *
 * Usage:   node apply_steel_skin.js path/to/your-view.blade.php
 * Result:  the file is edited in place; the original is saved as <file>.bak
 *
 * Only paint changes: gradients, one texture pattern, seams, highlights and a
 * small CSS block. No paths, clips, classes or animation hooks are touched.
 * Every edit is checked first, so nothing is written if your file differs
 * from the version this script was written against.
 */
const fs = require('fs');

const file = process.argv[2];
if (!file) {
    console.error('Usage: node apply_steel_skin.js <file>');
    process.exit(1);
}

let out = fs.readFileSync(file, 'utf8');

function fail(msg) {
    console.error(msg + ' No changes written.');
    process.exit(1);
}

function replaceOnce(oldStr, newStr, label) {
    const n = out.split(oldStr).length - 1;
    if (n !== 1) fail(`[${label}] expected 1 match, found ${n}.`);
    out = out.split(oldStr).join(newStr);
}

function replaceGradient(id, markup) {
    const re = new RegExp(
        '<(radialGradient|linearGradient)\\s+id="' + id + '"[^>]*>[\\s\\S]*?</\\1>',
        'g'
    );
    const hits = out.match(re) || [];
    if (hits.length !== 1) fail(`[gradient ${id}] expected 1 match, found ${hits.length}.`);
    out = out.replace(re, () => markup);
}

// ---------------------------------------------------------------------------
// 1. Gradients (same IDs, so nothing else needs rewiring)
// ---------------------------------------------------------------------------
replaceGradient('jc-face-glass', `<radialGradient id="jc-face-glass" cx="42%" cy="30%" r="80%">
                    <stop offset="0"   stop-color="#f7fbff" />
                    <stop offset=".28" stop-color="#d5dde5" />
                    <stop offset=".62" stop-color="#9aa6b2" />
                    <stop offset="1"   stop-color="#56626f" />
                </radialGradient>`);

replaceGradient('jc-holo-stroke', `<linearGradient id="jc-holo-stroke" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0"   stop-color="#ffffff" />
                    <stop offset=".5"  stop-color="#d4dee8" />
                    <stop offset="1"   stop-color="#7fb6f0" />
                </linearGradient>`);

replaceGradient('jc-holo-body', `<linearGradient id="jc-holo-body" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0"   stop-color="#dfe6ec" stop-opacity=".96" />
                    <stop offset=".35" stop-color="#a3afba" stop-opacity=".96" />
                    <stop offset=".7"  stop-color="#5f6b78" stop-opacity=".96" />
                    <stop offset="1"   stop-color="#2a333d" stop-opacity=".96" />
                </linearGradient>`);

replaceGradient('jc-holo-aura', `<radialGradient id="jc-holo-aura" cx="50%" cy="44%" r="54%">
                    <stop offset="0" stop-color="#cfe6ff" stop-opacity=".34" />
                    <stop offset=".48" stop-color="#5aa8f0" stop-opacity=".12" />
                    <stop offset="1" stop-color="#2f7fdc" stop-opacity="0" />
                </radialGradient>`);

replaceGradient('jc-face-highlight', `<linearGradient id="jc-face-highlight" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0"   stop-color="#ffffff" stop-opacity=".6" />
                    <stop offset=".25" stop-color="#eaf6ff" stop-opacity=".18" />
                    <stop offset=".55" stop-color="#ffffff" stop-opacity="0" />
                    <stop offset="1"   stop-color="#7cc4ff" stop-opacity=".38" />
                </linearGradient>`);

replaceGradient('jc-cheek', `<radialGradient id="jc-cheek" cx="50%" cy="50%" r="50%">
                    <stop offset="0" stop-color="#ffffff" stop-opacity=".16" />
                    <stop offset="1" stop-color="#ffffff" stop-opacity="0" />
                </radialGradient>`);

replaceGradient('jc-lip', `<linearGradient id="jc-lip" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#e4ebf1" stop-opacity=".95" />
                    <stop offset="1" stop-color="#7d8a97" stop-opacity=".9" />
                </linearGradient>`);

replaceGradient('jc-hair', `<linearGradient id="jc-hair" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0"   stop-color="#eef3f7" stop-opacity=".95" />
                    <stop offset=".5"  stop-color="#9aa7b3" stop-opacity=".9" />
                    <stop offset="1"   stop-color="#4a5662" stop-opacity=".75" />
                </linearGradient>`);

replaceGradient('jc-hair-line', `<linearGradient id="jc-hair-line" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#ffffff" stop-opacity=".95" />
                    <stop offset="1" stop-color="#8cc6ff" stop-opacity=".25" />
                </linearGradient>`);

// ---------------------------------------------------------------------------
// 2. Brushed-grain pattern + shared seam paths (added to <defs>)
// ---------------------------------------------------------------------------
replaceOnce(
    '{{-- New: iris, cheeks, lips, hair --}}',
    `{{-- Brushed steel: fine horizontal grain (a pattern, not feTurbulence, so the
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

                {{-- New: iris, cheeks, lips, hair --}}`,
    'defs additions'
);

// ---------------------------------------------------------------------------
// 3. Face: engraved-panel wireframe, then grain, seams and specular pools
// ---------------------------------------------------------------------------
replaceOnce(
    '<g clip-path="url(#jc-face-clip)" fill="none" stroke="url(#jc-holo-dim)" stroke-width="1.1" opacity=".85">',
    '<g clip-path="url(#jc-face-clip)" fill="none" stroke="#0f1a24" stroke-width="1" opacity=".16">',
    'face wireframe'
);

replaceOnce(
    '<rect x="315" y="90" width="270" height="340" clip-path="url(#jc-face-clip)" fill="url(#jc-holo-scanlines)" opacity=".42" />',
    `<rect x="315" y="90" width="270" height="340" clip-path="url(#jc-face-clip)" fill="url(#jc-holo-scanlines)" opacity=".07" />

                    <rect x="315" y="90" width="270" height="340" clip-path="url(#jc-face-clip)" fill="url(#jc-brushed)" style="mix-blend-mode:soft-light" opacity=".9" />

                    <g clip-path="url(#jc-face-clip)">
                        <use href="#jc-seams" stroke="#0c151e" stroke-opacity=".5" stroke-width="1" />
                        <use href="#jc-seams" transform="translate(0 1.3)" stroke="#ffffff" stroke-opacity=".38" stroke-width=".8" />
                    </g>

                    <g clip-path="url(#jc-face-clip)" stroke="none" style="mix-blend-mode:screen">
                        <ellipse cx="418" cy="150" rx="46" ry="16" fill="#ffffff" opacity=".28" transform="rotate(-14 418 150)" />
                        <ellipse cx="444" cy="300" rx="5"  ry="26" fill="#ffffff" opacity=".22" />
                        <ellipse cx="378" cy="318" rx="24" ry="9"  fill="#ffffff" opacity=".2"  transform="rotate(-18 378 318)" />
                        <ellipse cx="450" cy="408" rx="26" ry="6"  fill="#dff0ff" opacity=".22" />
                        <path d="M556 250 Q562 300 542 346" stroke="#9fd2ff" stroke-opacity=".5" stroke-width="3" fill="none" />
                    </g>`,
    'face scanlines/grain/seams'
);

// ---------------------------------------------------------------------------
// 4. Bust (clothing): quieter mesh, faint scanlines, grain
// ---------------------------------------------------------------------------
replaceOnce(
    '<g clip-path="url(#jc-bust-clip)" fill="none" stroke="url(#jc-holo-dim)" stroke-width="1.1" opacity=".8">',
    '<g clip-path="url(#jc-bust-clip)" fill="none" stroke="#0e1822" stroke-width="1" opacity=".3">',
    'bust mesh'
);

replaceOnce(
    '<rect x="150" y="440" width="600" height="340" clip-path="url(#jc-bust-clip)" fill="url(#jc-holo-scanlines)" opacity=".36" />',
    `<rect x="150" y="440" width="600" height="340" clip-path="url(#jc-bust-clip)" fill="url(#jc-holo-scanlines)" opacity=".08" />
                <rect x="150" y="440" width="600" height="340" clip-path="url(#jc-bust-clip)" fill="url(#jc-brushed)" style="mix-blend-mode:soft-light" opacity=".8" />`,
    'bust scanlines/grain'
);

// ---------------------------------------------------------------------------
// 5. CSS: cool blue-white lighting (placed last so it wins at equal specificity)
// ---------------------------------------------------------------------------
replaceOnce(
    '</head>',
    `<style>
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
</head>`,
    'css block'
);

// ---------------------------------------------------------------------------
fs.copyFileSync(file, file + '.bak');
fs.writeFileSync(file, out, 'utf8');
console.log(`Done. Backup saved to ${file}.bak`);