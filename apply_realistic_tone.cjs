#!/usr/bin/env node
/*
 * Upgrades the flat skin tone to a realistic, shaded AI-avatar look.
 * Run AFTER apply_steel_skin.cjs and apply_skin_tone.cjs.
 *
 * Usage:   node apply_realistic_tone.cjs <file> [tone] [hair]
 * Tone:    fair | light | medium | tan | brown | deep        (default: medium)
 * Hair:    keep | black | darkbrown | auburn | blonde        (default: keep)
 * Result:  file edited in place; original saved as <file>.pre-real.bak
 * Undo:    copy <file>.pre-real.bak back over <file>
 *
 * Use the same tone you used in apply_skin_tone.cjs.
 */
const fs = require('fs');

const TONES = {
    fair:   { hi: '#fdf0e6', lt: '#f3d3bd', md: '#dcae92', sh: '#a87a62', dk: '#7a4f3d', warm: '#e59a8f', lip1: '#dc918c', lip2: '#b8635f' },
    light:  { hi: '#fbe8d8', lt: '#efc7a8', md: '#d09b76', sh: '#9a6a50', dk: '#6f4432', warm: '#dc8f80', lip1: '#d2857e', lip2: '#ad5c58' },
    medium: { hi: '#f6d9bd', lt: '#e3ae84', md: '#c1875f', sh: '#875840', dk: '#5f3524', warm: '#cf7f6d', lip1: '#c6766d', lip2: '#9f524c' },
    tan:    { hi: '#e9bd96', lt: '#cd9468', md: '#aa7048', sh: '#724a33', dk: '#4e2a1b', warm: '#bb6d59', lip1: '#b3665d', lip2: '#8c4741' },
    brown:  { hi: '#c48b68', lt: '#a26748', md: '#7e4b33', sh: '#52301f', dk: '#36190f', warm: '#9c5843', lip1: '#96574e', lip2: '#753b36' },
    deep:   { hi: '#956349', lt: '#74472f', md: '#55321f', sh: '#361d12', dk: '#20100a', warm: '#7e4632', lip1: '#76413a', lip2: '#5a302b' },
};

const HAIR = {
    keep:      null,
    black:     { a: '#5a6070', b: '#1f232c', c: '#0a0c10', l1: '#aab4c8', l2: '#4a5568', brow: '#15181f' },
    darkbrown: { a: '#7a5540', b: '#3f2718', c: '#1e120b', l1: '#d0a27f', l2: '#6b4630', brow: '#2b1a10' },
    auburn:    { a: '#a4593b', b: '#5e2a19', c: '#2b120b', l1: '#e6a37e', l2: '#8a4a30', brow: '#4a2214' },
    blonde:    { a: '#f0d9a6', b: '#c9a463', c: '#7f6135', l1: '#fff3d1', l2: '#c9a463', brow: '#8a6a3c' },
};

const file = process.argv[2];
const toneName = (process.argv[3] || 'medium').toLowerCase();
const hairName = (process.argv[4] || 'keep').toLowerCase();

if (!file) {
    console.error('Usage: node apply_realistic_tone.cjs <file> [tone] [hair]');
    process.exit(1);
}
const t = TONES[toneName];
if (!t) {
    console.error(`Unknown tone "${toneName}". Choose: ${Object.keys(TONES).join(', ')}`);
    process.exit(1);
}
if (!(hairName in HAIR)) {
    console.error(`Unknown hair "${hairName}". Choose: ${Object.keys(HAIR).join(', ')}`);
    process.exit(1);
}
const h = HAIR[hairName];

let out = fs.readFileSync(file, 'utf8');

function fail(msg) {
    console.error(msg + ' No changes written.');
    process.exit(1);
}

if (!out.includes('id="jc-skin-neck"')) fail('Skin tone not found. Run apply_skin_tone.cjs first.');
if (out.includes('id="jc-real-vig"')) fail('Realistic tone already applied. Restore the .pre-real.bak to redo it.');

function replaceOnce(oldStr, newStr, label) {
    const n = out.split(oldStr).length - 1;
    if (n !== 1) fail(`[${label}] expected 1 match, found ${n}.`);
    out = out.split(oldStr).join(newStr);
}

function replaceRegexOnce(re, fn, label) {
    const hits = out.match(new RegExp(re.source, 'g')) || [];
    if (hits.length !== 1) fail(`[${label}] expected 1 match, found ${hits.length}.`);
    out = out.replace(re, fn);
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

// 1. Face: richer multi-stop skin, lit from the upper left
replaceGradient('jc-face-glass', `<radialGradient id="jc-face-glass" cx="44%" cy="32%" r="78%">
                    <stop offset="0"   stop-color="${t.hi}" />
                    <stop offset=".24" stop-color="${t.lt}" />
                    <stop offset=".58" stop-color="${t.md}" />
                    <stop offset="1"   stop-color="${t.sh}" />
                </radialGradient>`);

// 2. Cheeks: diffuse blush instead of a visible patch
replaceGradient('jc-cheek', `<radialGradient id="jc-cheek" cx="50%" cy="50%" r="50%">
                    <stop offset="0"  stop-color="${t.warm}" stop-opacity=".24" />
                    <stop offset=".5" stop-color="${t.warm}" stop-opacity=".1" />
                    <stop offset="1"  stop-color="${t.warm}" stop-opacity="0" />
                </radialGradient>`);

// 3. Lips: soft highlight through the middle
replaceGradient('jc-lip', `<linearGradient id="jc-lip" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0"  stop-color="${t.lip1}" stop-opacity=".97" />
                    <stop offset=".5" stop-color="${t.lip1}" stop-opacity=".9" />
                    <stop offset="1"  stop-color="${t.lip2}" stop-opacity=".95" />
                </linearGradient>`);

// 4. Neck: softer, lighter, blends into the jaw
replaceGradient('jc-skin-neck', `<linearGradient id="jc-skin-neck" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0"   stop-color="${t.sh}" />
                    <stop offset=".22" stop-color="${t.md}" />
                    <stop offset=".46" stop-color="${t.lt}" />
                    <stop offset=".72" stop-color="${t.md}" />
                    <stop offset="1"   stop-color="${t.sh}" />
                </linearGradient>`);
replaceGradient('jc-skin-neck-shade', `<linearGradient id="jc-skin-neck-shade" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0"   stop-color="${t.dk}" stop-opacity=".62" />
                    <stop offset=".3"  stop-color="${t.dk}" stop-opacity=".28" />
                    <stop offset="1"   stop-color="${t.dk}" stop-opacity=".06" />
                </linearGradient>`);

// 5. Eyes: natural blue with a dark limbal ring, off-white sclera
replaceGradient('jc-iris', `<radialGradient id="jc-iris" cx="50%" cy="50%" r="50%">
                    <stop offset="0"   stop-color="#e8f6ff" />
                    <stop offset=".3"  stop-color="#8cc8e8" />
                    <stop offset=".65" stop-color="#3f8fc4" />
                    <stop offset=".88" stop-color="#1c5f95" />
                    <stop offset="1"   stop-color="#0c3556" />
                </radialGradient>`);
replaceOnce('fill="#cfeeff" fill-opacity=".5"', 'fill="#f4f1ee" fill-opacity=".92"', 'sclera');

// 6. Defs: face vignette + pore texture
replaceOnce(
    '{{-- New: iris, cheeks, lips, hair --}}',
    `{{-- Realistic skin: roundness vignette + fine pore texture --}}
                <radialGradient id="jc-real-vig" cx="47%" cy="40%" r="62%">
                    <stop offset="0"   stop-color="${t.dk}" stop-opacity="0" />
                    <stop offset=".55" stop-color="${t.dk}" stop-opacity="0" />
                    <stop offset=".82" stop-color="${t.dk}" stop-opacity=".22" />
                    <stop offset="1"   stop-color="${t.dk}" stop-opacity=".5" />
                </radialGradient>
                <pattern id="jc-pores" width="17" height="13" patternUnits="userSpaceOnUse">
                    <circle cx="3"  cy="4"  r=".55" fill="#000" opacity=".22" />
                    <circle cx="11" cy="2"  r=".45" fill="#000" opacity=".16" />
                    <circle cx="8"  cy="9"  r=".5"  fill="#000" opacity=".2" />
                    <circle cx="15" cy="11" r=".4"  fill="#000" opacity=".14" />
                </pattern>

                {{-- New: iris, cheeks, lips, hair --}}`,
    'defs additions'
);

// 7. Face shading layers (under the cheeks, eyes, nose and mouth)
replaceOnce(
    '<ellipse cx="378" cy="326" rx="36" ry="22" fill="url(#jc-cheek)" stroke="none" />',
    `{{-- Realistic skin shading --}}
                    <g clip-path="url(#jc-face-clip)" stroke="none">
                        <rect x="315" y="90" width="270" height="340" fill="url(#jc-real-vig)" />
                        <rect x="315" y="90" width="270" height="340" fill="url(#jc-pores)" style="mix-blend-mode:soft-light" opacity=".5" />
                        <ellipse cx="440" cy="132" rx="70" ry="28" fill="${t.hi}" opacity=".22" />
                        <ellipse cx="391" cy="246" rx="30" ry="9" fill="${t.dk}" opacity=".1" />
                        <ellipse cx="509" cy="246" rx="30" ry="9" fill="${t.dk}" opacity=".1" />
                        <ellipse cx="391" cy="288" rx="24" ry="7" fill="${t.dk}" opacity=".13" />
                        <ellipse cx="509" cy="288" rx="24" ry="7" fill="${t.dk}" opacity=".13" />
                        <ellipse cx="462" cy="300" rx="7"  ry="30" fill="${t.dk}" opacity=".1" />
                        <ellipse cx="450" cy="326" rx="15" ry="10" fill="${t.warm}" opacity=".28" />
                        <ellipse cx="450" cy="357" rx="8"  ry="8"  fill="${t.dk}" opacity=".08" />
                        <ellipse cx="450" cy="413" rx="24" ry="7"  fill="${t.dk}" opacity=".16" />
                    </g>
                    <ellipse cx="378" cy="326" rx="36" ry="22" fill="url(#jc-cheek)" stroke="none" />`,
    'shading layers'
);

// 8. Tame the steel-era specular pools so skin doesn't look glossy
replaceRegexOnce(/(<ellipse cx="418" cy="150"[^>]*?opacity=")\.28(")/, (m, a, b) => a + '.16' + b, 'spec forehead');
replaceRegexOnce(/(<ellipse cx="444" cy="300"[^>]*?opacity=")\.22(")/, (m, a, b) => a + '.1' + b, 'spec nose');
replaceRegexOnce(/(<ellipse cx="378" cy="318"[^>]*?opacity=")\.2(")/, (m, a, b) => a + '.1' + b, 'spec cheek');
replaceRegexOnce(/(<ellipse cx="450" cy="408"[^>]*?opacity=")\.22(")/, (m, a, b) => a + '.1' + b, 'spec chin');

// 9. Natural lash line and nose contour instead of white outlines
replaceOnce(
    '<path d="M363 268 Q390 246 419 265 M481 265 Q510 246 537 268" fill="none" stroke-width="2.6" />',
    '<path d="M363 268 Q390 246 419 265 M481 265 Q510 246 537 268" fill="none" stroke="#241611" stroke-opacity=".9" stroke-width="2.6" />',
    'lash line'
);
replaceOnce(
    '<path d="M450 272 L441 326 Q450 334 459 326 M434 338 Q450 348 466 338" fill="none" stroke-width="1.6" opacity=".8" />',
    `<path d="M450 272 L441 326 Q450 334 459 326 M434 338 Q450 348 466 338" fill="none" stroke="${t.dk}" stroke-width="1.6" opacity=".5" />`,
    'nose contour'
);

// 10. Hair colour (optional)
if (h) {
    replaceGradient('jc-hair', `<linearGradient id="jc-hair" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0"  stop-color="${h.a}" stop-opacity=".97" />
                    <stop offset=".5" stop-color="${h.b}" stop-opacity=".96" />
                    <stop offset="1"  stop-color="${h.c}" stop-opacity=".9" />
                </linearGradient>`);
    replaceGradient('jc-hair-line', `<linearGradient id="jc-hair-line" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="${h.l1}" stop-opacity=".9" />
                    <stop offset="1" stop-color="${h.l2}" stop-opacity=".2" />
                </linearGradient>`);
}

// 11. Brows: match the hair instead of the white rim stroke
const browColor = h ? h.brow : '#6d7883';
replaceOnce(
    '</head>',
    `<style>
    /* Realistic tone: brows follow the hair colour */
    #jc-avatar-portrait .jc-brow { stroke: ${browColor}; }
</style>
</head>`,
    'brow css'
);

fs.copyFileSync(file, file + '.pre-real.bak');
fs.writeFileSync(file, out, 'utf8');
console.log(`Done. Realistic "${toneName}" tone applied (hair: ${hairName}). Backup: ${file}.pre-real.bak`);
