#!/usr/bin/env node
/*
 * Restyles the avatar as a cyberpunk android:
 *   - dark slicked-back hair with an exposed forehead
 *   - cooler, natural skin with cyan rim light
 *   - glowing translucent circuit plate over the forehead and temples
 *   - ears with earpieces
 *   - glowing electric-blue eyes
 *   - cybernetic neck with ribbed lit column
 *
 * Run AFTER the earlier scripts (steel, skin tone, realistic tone, face shape).
 *
 * Usage:   node apply_cyber_android.cjs <file>
 * Result:  file edited in place; original saved as <file>.pre-cyber.bak
 * Undo:    copy <file>.pre-cyber.bak back over <file>
 *
 * The circuit glow follows the assistant state colour (cyan idle, green
 * listening, violet thinking). All animation hooks are untouched.
 */
const fs = require('fs');

const file = process.argv[2];
if (!file) {
    console.error('Usage: node apply_cyber_android.cjs <file>');
    process.exit(1);
}

let out = fs.readFileSync(file, 'utf8');

function fail(msg) {
    console.error(msg + ' No changes written.');
    process.exit(1);
}

if (out.includes('id="jc-circuit-plate"')) fail('Cyber android styling is already applied.');

function replaceOnce(oldStr, newStr, label) {
    const n = out.split(oldStr).length - 1;
    if (n !== 1) fail(`[${label}] expected 1 match, found ${n}.`);
    out = out.split(oldStr).join(newStr);
}

function replaceGradient(id, markup, optional = false) {
    const re = new RegExp('<(radialGradient|linearGradient)\\s+id="' + id + '"[^>]*>[\\s\\S]*?</\\1>', 'g');
    const hits = out.match(re) || [];
    if (hits.length === 0 && optional) { console.log(`  (skipped: gradient ${id} not found)`); return; }
    if (hits.length !== 1) fail(`[gradient ${id}] expected 1 match, found ${hits.length}.`);
    out = out.replace(re, () => markup);
}

// Mirror an absolute-coordinate path around x = 450 (M L H V C Q Z only)
function mirror(d) {
    return d.replace(/([MLHVCQZ])([^MLHVCQZ]*)/g, (m, c, args) => {
        if (c === 'Z' || c === 'V') return m;
        const n = args.trim().split(/[\s,]+/).filter(Boolean).map(Number);
        if (c === 'H') return 'H' + (900 - n[0]);
        const o = [];
        for (let i = 0; i < n.length; i += 2) o.push((900 - n[i]) + ' ' + n[i + 1]);
        return c + o.join(' ');
    });
}

const ACC_STROKE = 'style="stroke:rgb(var(--jc-accent))"';
const ACC_FILL = 'style="fill:rgb(var(--jc-accent))"';

// ---------------------------------------------------------------------------
// 1. Dark hair
// ---------------------------------------------------------------------------
replaceGradient('jc-hair', `<linearGradient id="jc-hair" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0"  stop-color="#3a4250" stop-opacity=".98" />
                    <stop offset=".5" stop-color="#12161d" stop-opacity=".98" />
                    <stop offset="1"  stop-color="#07090c" stop-opacity=".95" />
                </linearGradient>`);
replaceGradient('jc-hair-line', `<linearGradient id="jc-hair-line" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#8fa3c2" stop-opacity=".7" />
                    <stop offset="1" stop-color="#3b4a63" stop-opacity=".15" />
                </linearGradient>`);

// Slicked-back silhouette (hair pulled behind the ears, high hairline)
replaceOnce(
    'M326 236 C312 130 372 70 450 70 C528 70 588 130 574 236 C592 292 588 352 564 398 Q548 400 540 384 L360 384 Q352 400 336 398 C312 352 308 292 326 236Z',
    'M336 236 C322 128 378 70 450 70 C522 70 578 128 564 236 C562 252 558 262 554 270 L346 270 C342 262 338 252 336 236Z',
    'hair back'
);
replaceOnce(
    'M328 232 C322 150 376 96 450 92 C524 96 578 150 572 232 C560 190 540 152 500 132 C480 150 440 158 404 150 C372 168 344 196 328 232Z',
    'M338 236 C328 150 384 96 450 92 C516 96 572 150 562 236 C556 190 520 134 450 128 C380 134 344 190 338 236Z',
    'hair front'
);

// Symmetrical brushed-back strands fanning from the hairline
const strandsLeft = [
    'M450 128 V96',
    'M420 132 C418 118 424 104 432 94',
    'M392 148 C388 130 398 112 412 98',
    'M362 178 C356 150 372 122 392 104',
];
const strands = strandsLeft.map(d => `<path d="${d}" />`).join('\n                            ')
    + '\n                            '
    + strandsLeft.slice(1).map(d => `<path d="${mirror(d)}" />`).join('\n                            ');
const strandRe = /<g fill="none" stroke="url\(#jc-hair-line\)"[^>]*>[\s\S]*?<\/g>/;
if ((out.match(new RegExp(strandRe.source, 'g')) || []).length !== 1) fail('[hair strands] expected 1 match.');
out = out.replace(strandRe, () => `<g fill="none" stroke="url(#jc-hair-line)" stroke-width="1.1" opacity=".8">
                            ${strands}
                        </g>`);

// ---------------------------------------------------------------------------
// 2. Cooler, natural android skin
// ---------------------------------------------------------------------------
replaceGradient('jc-face-glass', `<radialGradient id="jc-face-glass" cx="50%" cy="34%" r="76%">
                    <stop offset="0"   stop-color="#f3ddd0" />
                    <stop offset=".24" stop-color="#e2c1ac" />
                    <stop offset=".58" stop-color="#bb9481" />
                    <stop offset="1"   stop-color="#7d5f57" />
                </radialGradient>`);
replaceGradient('jc-skin-neck', `<linearGradient id="jc-skin-neck" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0"   stop-color="#7d5f57" />
                    <stop offset=".22" stop-color="#bb9481" />
                    <stop offset=".46" stop-color="#d4b09d" />
                    <stop offset=".72" stop-color="#bb9481" />
                    <stop offset="1"   stop-color="#7d5f57" />
                </linearGradient>`, true);
replaceGradient('jc-skin-neck-shade', `<linearGradient id="jc-skin-neck-shade" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0"   stop-color="#3a2c2c" stop-opacity=".6" />
                    <stop offset=".3"  stop-color="#3a2c2c" stop-opacity=".26" />
                    <stop offset="1"   stop-color="#3a2c2c" stop-opacity=".06" />
                </linearGradient>`, true);
replaceGradient('jc-cheek', `<radialGradient id="jc-cheek" cx="50%" cy="50%" r="50%">
                    <stop offset="0"  stop-color="#d9948f" stop-opacity=".2" />
                    <stop offset=".5" stop-color="#d9948f" stop-opacity=".09" />
                    <stop offset="1"  stop-color="#d9948f" stop-opacity="0" />
                </radialGradient>`);
replaceGradient('jc-lip', `<linearGradient id="jc-lip" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0"  stop-color="#cc9088" stop-opacity=".97" />
                    <stop offset=".5" stop-color="#c08078" stop-opacity=".92" />
                    <stop offset="1"  stop-color="#9f6a66" stop-opacity=".95" />
                </linearGradient>`);
// Cyan city-light rim on both edges of the face
replaceGradient('jc-face-highlight', `<linearGradient id="jc-face-highlight" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0"   stop-color="#7fd4ff" stop-opacity=".4" />
                    <stop offset=".22" stop-color="#ffffff" stop-opacity="0" />
                    <stop offset=".5"  stop-color="#fffaf4" stop-opacity=".14" />
                    <stop offset=".78" stop-color="#ffffff" stop-opacity="0" />
                    <stop offset="1"   stop-color="#7fd4ff" stop-opacity=".4" />
                </linearGradient>`);

// ---------------------------------------------------------------------------
// 3. Eyes: electric blue with a tech ring, plus a soft glow around each eye
// ---------------------------------------------------------------------------
replaceGradient('jc-iris', `<radialGradient id="jc-iris" cx="50%" cy="50%" r="50%">
                    <stop offset="0"   stop-color="#eaffff" />
                    <stop offset=".3"  stop-color="#5fd6ff" />
                    <stop offset=".62" stop-color="#1b8cff" />
                    <stop offset=".86" stop-color="#0a52c9" />
                    <stop offset="1"   stop-color="#04246b" />
                </radialGradient>`);

replaceOnce(
    '<circle class="jc-pupil" cx="391" cy="267" r="4.6" fill="#03152e" stroke="none" />',
    '<circle cx="391" cy="267" r="7.6" fill="none" stroke="#c9f6ff" stroke-opacity=".75" stroke-width=".7" stroke-dasharray="1.2 1.6" />\n                                <circle class="jc-pupil" cx="391" cy="267" r="4.6" fill="#03152e" stroke="none" />',
    'left iris ring'
);
replaceOnce(
    '<circle class="jc-pupil" cx="509" cy="267" r="4.6" fill="#03152e" stroke="none" />',
    '<circle cx="509" cy="267" r="7.6" fill="none" stroke="#c9f6ff" stroke-opacity=".75" stroke-width=".7" stroke-dasharray="1.2 1.6" />\n                                <circle class="jc-pupil" cx="509" cy="267" r="4.6" fill="#03152e" stroke="none" />',
    'right iris ring'
);

replaceOnce(
    '<g class="jc-avatar-eyes">',
    `<ellipse cx="391" cy="267" rx="36" ry="17" fill="url(#jc-eye-glow)" stroke="none" style="mix-blend-mode:screen" />
                    <ellipse cx="509" cy="267" rx="36" ry="17" fill="url(#jc-eye-glow)" stroke="none" style="mix-blend-mode:screen" />
                    <g class="jc-avatar-eyes">`,
    'eye glow'
);

// ---------------------------------------------------------------------------
// 4. Defs: eye glow + plate glass
// ---------------------------------------------------------------------------
replaceOnce(
    '{{-- New: iris, cheeks, lips, hair --}}',
    `{{-- Cyber android: eye glow and translucent circuit plate --}}
                <radialGradient id="jc-eye-glow" cx="50%" cy="50%" r="50%">
                    <stop offset="0"  stop-color="#8fe8ff" stop-opacity=".5" />
                    <stop offset=".5" stop-color="#3aa8ff" stop-opacity=".18" />
                    <stop offset="1"  stop-color="#3aa8ff" stop-opacity="0" />
                </radialGradient>
                <linearGradient id="jc-plate-glass" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#9ff0ff" stop-opacity=".24" />
                    <stop offset="1" stop-color="#3ab8ff" stop-opacity=".08" />
                </linearGradient>

                {{-- New: iris, cheeks, lips, hair --}}`,
    'defs additions'
);

// ---------------------------------------------------------------------------
// 5. Ears with earpieces (behind the face, above the hair)
// ---------------------------------------------------------------------------
const earL = 'M343 252 C326 244 317 262 319 284 C321 302 334 308 345 298Z';
const piece = 'M334 262 C321 262 315 276 318 292 C320 304 331 306 338 298Z';
const wire = 'M322 300 C324 316 334 326 350 332';
replaceOnce(
    '{{-- Face --}}',
    `{{-- Ears and earpieces --}}
                    <path d="${earL}" fill="url(#jc-face-glass)" stroke="none" />
                    <path d="${mirror(earL)}" fill="url(#jc-face-glass)" stroke="none" />
                    <path d="${piece}" fill="#0d131b" stroke-width="1.2" ${ACC_STROKE} />
                    <path d="${mirror(piece)}" fill="#0d131b" stroke-width="1.2" ${ACC_STROKE} />
                    <path d="${wire}" fill="none" stroke="#0d131b" stroke-width="2.4" />
                    <path d="${mirror(wire)}" fill="none" stroke="#0d131b" stroke-width="2.4" />
                    <circle cx="326" cy="283" r="2.2" stroke="none" ${ACC_FILL} />
                    <circle cx="574" cy="283" r="2.2" stroke="none" ${ACC_FILL} />

                    {{-- Face --}}`,
    'ears'
);

// ---------------------------------------------------------------------------
// 6. Circuit plate: glass forehead cap + temple flaps, traces, flowing light
// ---------------------------------------------------------------------------
const cap = 'M350 214 C348 164 392 126 450 126 C508 126 552 164 550 214 C520 204 486 200 450 200 C414 200 380 204 350 214Z';
const flap = 'M350 214 C346 250 348 290 360 322 L370 318 C360 288 358 252 362 220Z';

const traces = [
    'M450 150 H420 L410 160 H384',
    'M450 168 H436 L426 178 H398 L390 186 H372',
    'M450 186 H442 L436 192 H412',
    'M382 160 V172 L374 180',
    'M366 204 H396 L404 196 H430',
    'M400 140 H430 L438 148',
    'M372 152 L384 140 H404',
    'M356 232 V262 L362 270 V300 L368 308',
    'M366 226 V248',
];
const flowIdx = [0, 1, 7];
const nodes = [[384, 160], [372, 186], [412, 192], [430, 196], [438, 148], [404, 140], [374, 180], [356, 232], [368, 308], [366, 248]];

const both = (fn) => fn(false) + '\n                        ' + fn(true);
const shape = (d, attrs) => both((m) => `<path d="${m ? mirror(d) : d}" ${attrs} />`);

const solidTraces = traces
    .map((d) => shape(d, `stroke-width="1.1" stroke-opacity=".85" ${ACC_STROKE}`))
    .join('\n                        ');
const flowTraces = flowIdx
    .map((i) => shape(traces[i], `class="jc-hud-line" stroke="#eaffff" stroke-width="1.6" stroke-opacity=".9"`))
    .join('\n                        ');
const nodeDots = nodes
    .map(([x, y]) => `<circle cx="${x}" cy="${y}" r="1.9" ${ACC_FILL} /><circle cx="${900 - x}" cy="${y}" r="1.9" ${ACC_FILL} />`)
    .join('\n                        ');

replaceOnce(
    '{{-- Hair, front layer: swept fringe with light strands --}}',
    `{{-- Translucent circuit plate (forehead and temples) --}}
                    <g id="jc-circuit-plate" clip-path="url(#jc-face-clip)" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="${cap}" fill="url(#jc-plate-glass)" stroke="none" />
                        <path d="${flap}" fill="url(#jc-plate-glass)" stroke="none" />
                        <path d="${mirror(flap)}" fill="url(#jc-plate-glass)" stroke="none" />
                        <path d="${cap}" stroke-width="5" stroke-opacity=".16" ${ACC_STROKE} />
                        <path d="${cap}" stroke-width="1.4" stroke-opacity=".9" ${ACC_STROKE} />
                        <path d="${flap}" stroke-width="1.2" stroke-opacity=".85" ${ACC_STROKE} />
                        <path d="${mirror(flap)}" stroke-width="1.2" stroke-opacity=".85" ${ACC_STROKE} />
                        ${solidTraces}
                        ${flowTraces}
                        <g stroke="none">
                        ${nodeDots}
                        </g>
                    </g>

                    {{-- Hair, front layer: swept fringe with light strands --}}`,
    'circuit plate'
);

// ---------------------------------------------------------------------------
// 7. Cybernetic neck (drawn after the skin neck, before the head)
// ---------------------------------------------------------------------------
const cableL = 'M410 420 Q406 446 412 468';
replaceOnce(
    '{{-- Head. The outer group',
    `{{-- Cyber neck --}}
            <path d="M424 418 H476 L472 466 Q450 476 428 466Z" fill="#0b1118" fill-opacity=".92" stroke="none" />
            <path d="${cableL}" fill="none" stroke="#0b1118" stroke-width="3.2" stroke-linecap="round" />
            <path d="${mirror(cableL)}" fill="none" stroke="#0b1118" stroke-width="3.2" stroke-linecap="round" />
            <g fill="none" stroke-linecap="round" ${ACC_STROKE}>
                <path d="M429 434 H471" stroke-width="1.6" stroke-opacity=".85" />
                <path d="M429 446 H471" stroke-width="1.6" stroke-opacity=".85" />
                <path d="M430 458 H470" stroke-width="1.6" stroke-opacity=".85" />
                <path d="M450 420 V470" stroke-width="1" stroke-opacity=".5" />
                <path d="${cableL}" stroke-width="1" stroke-opacity=".8" />
                <path d="${mirror(cableL)}" stroke-width="1" stroke-opacity=".8" />
                <path d="M404 452 Q450 478 496 452" stroke-width="1.6" stroke-opacity=".7" />
            </g>

            {{-- Head. The outer group`,
    'cyber neck'
);

// ---------------------------------------------------------------------------
// 8. CSS: dark brows and cool cinematic glow around the head
// ---------------------------------------------------------------------------
replaceOnce(
    '</head>',
    `<style>
    /* Cyber android: dark brows and a cool cyan bloom */
    #jc-avatar-portrait .jc-brow { stroke: #1b1511; }
    #jc-avatar-portrait .jc-avatar-head {
        filter: drop-shadow(0 0 16px rgba(70, 190, 255, 0.30))
                drop-shadow(0 6px 14px rgba(0, 10, 25, 0.5));
    }
</style>
</head>`,
    'css block'
);

fs.copyFileSync(file, file + '.pre-cyber.bak');
fs.writeFileSync(file, out, 'utf8');
console.log(`Done. Cyber android styling applied. Backup: ${file}.pre-cyber.bak`);
