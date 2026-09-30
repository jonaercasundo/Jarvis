#!/usr/bin/env node
/*
 * Reshapes the avatar face into a symmetrical, refined oval:
 * defined cheekbones, tapered jaw, softer chin, evenly lit on both sides.
 * Run AFTER the earlier scripts (steel / skin tone / realistic tone).
 *
 * Usage:   node apply_face_shape.cjs <file> [tone]
 * Tone:    fair | light | medium | tan | brown | deep   (default: medium; use the one you picked before)
 * Result:  file edited in place; original saved as <file>.pre-face.bak
 * Undo:    copy <file>.pre-face.bak back over <file>
 *
 * Eyes, brows, nose, mouth, hair, clothing and every animation hook are unchanged.
 */
const fs = require('fs');

const TONES = {
    fair:   { hi: '#fdf0e6', dk: '#7a4f3d' },
    light:  { hi: '#fbe8d8', dk: '#6f4432' },
    medium: { hi: '#f6d9bd', dk: '#5f3524' },
    tan:    { hi: '#e9bd96', dk: '#4e2a1b' },
    brown:  { hi: '#c48b68', dk: '#36190f' },
    deep:   { hi: '#956349', dk: '#20100a' },
};

const file = process.argv[2];
const toneName = (process.argv[3] || 'medium').toLowerCase();

if (!file) {
    console.error('Usage: node apply_face_shape.cjs <file> [tone]');
    process.exit(1);
}
if (!TONES[toneName]) {
    console.error(`Unknown tone "${toneName}". Choose: ${Object.keys(TONES).join(', ')}`);
    process.exit(1);
}

let out = fs.readFileSync(file, 'utf8');

function fail(msg) {
    console.error(msg + ' No changes written.');
    process.exit(1);
}

const hasSkin = out.includes('id="jc-skin-neck"');
const t = hasSkin ? TONES[toneName] : { hi: '#ffffff', dk: '#0f1a24' };

// Mandatory: the face outline (used by the clip path, the fill and the highlight)
const OLD_FACE = 'M450 92 C378 92 330 145 328 225 C326 270 330 300 342 335 C356 378 396 410 450 424 C504 410 544 378 558 335 C570 300 574 270 572 225 C570 145 522 92 450 92Z';
const NEW_FACE = 'M450 92 C384 92 342 140 340 214 C339 250 340 282 348 314 C358 352 388 392 428 418 C438 424 444 427 450 427 C456 427 462 424 472 418 C512 392 542 352 552 314 C560 282 561 250 560 214 C558 140 516 92 450 92Z';

const faceCount = out.split(OLD_FACE).length - 1;
if (faceCount !== 3) {
    fail(`[face outline] expected 3 matches (clip, fill, highlight), found ${faceCount}. The shape may already be applied.`);
}
out = out.split(OLD_FACE).join(NEW_FACE);

function replaceGradient(id, markup) {
    const re = new RegExp('<(radialGradient|linearGradient)\\s+id="' + id + '"[^>]*>[\\s\\S]*?</\\1>', 'g');
    const hits = out.match(re) || [];
    if (hits.length !== 1) fail(`[gradient ${id}] expected 1 match, found ${hits.length}.`);
    out = out.replace(re, () => markup);
}

// Optional edits: skipped with a note if the earlier scripts were not run
function editOptional(re, fn, label) {
    const hits = out.match(new RegExp(re.source, 'g')) || [];
    if (hits.length === 0) { console.log(`  (skipped: ${label} not found)`); return; }
    if (hits.length > 1) fail(`[${label}] expected at most 1 match, found ${hits.length}.`);
    out = out.replace(re, fn);
}

// 1. Even, front-facing lighting (no left-right bias)
editOptional(
    /<radialGradient id="jc-face-glass" cx="[^"]*" cy="[^"]*" r="[^"]*">/,
    () => '<radialGradient id="jc-face-glass" cx="50%" cy="34%" r="76%">',
    'face gradient centre'
);
editOptional(
    /<radialGradient id="jc-real-vig" cx="[^"]*" cy="[^"]*" r="[^"]*">/,
    () => '<radialGradient id="jc-real-vig" cx="50%" cy="40%" r="62%">',
    'vignette centre'
);

// Symmetrical highlight: cool rim on both edges, soft light down the centre
replaceGradient('jc-face-highlight', `<linearGradient id="jc-face-highlight" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0"   stop-color="#8ec8ff" stop-opacity=".26" />
                    <stop offset=".22" stop-color="#ffffff" stop-opacity="0" />
                    <stop offset=".5"  stop-color="#fffaf4" stop-opacity=".16" />
                    <stop offset=".78" stop-color="#ffffff" stop-opacity="0" />
                    <stop offset="1"   stop-color="#8ec8ff" stop-opacity=".26" />
                </linearGradient>`);

// 2. Specular pools: centred or mirrored
editOptional(
    /<ellipse cx="418" cy="150"[^>]*\/>/,
    () => '<ellipse cx="450" cy="138" rx="60" ry="16" fill="#ffffff" opacity=".16" />',
    'forehead highlight'
);
editOptional(
    /<ellipse cx="444" cy="300"[^>]*\/>/,
    () => '<ellipse cx="450" cy="300" rx="4" ry="26" fill="#ffffff" opacity=".1" />',
    'nose-bridge highlight'
);
editOptional(
    /<ellipse cx="378" cy="318"[^>]*\/>/,
    () => `<ellipse cx="386" cy="314" rx="22" ry="8" fill="#ffffff" opacity=".1" transform="rotate(-20 386 314)" />
                        <ellipse cx="514" cy="314" rx="22" ry="8" fill="#ffffff" opacity=".1" transform="rotate(20 514 314)" />`,
    'cheek highlight'
);
editOptional(
    /<path d="M556 250 Q562 300 542 346"[^>]*\/>/,
    () => `<path d="M346 240 Q343 290 360 336" stroke="#9fd2ff" stroke-opacity=".4" stroke-width="3" fill="none" />
                        <path d="M554 240 Q557 290 540 336" stroke="#9fd2ff" stroke-opacity=".4" stroke-width="3" fill="none" />`,
    'rim light'
);

// 3. Realistic-tone shading layers: centre the forehead light, split the nose shadow
editOptional(
    /<ellipse cx="440" cy="132"[^>]*\/>/,
    (m) => m.replace('cx="440"', 'cx="450"'),
    'forehead light'
);
editOptional(
    /<ellipse cx="462" cy="300"[^>]*\/>/,
    (m) => {
        const fill = (m.match(/fill="([^"]+)"/) || [])[1] || t.dk;
        return `<ellipse cx="441" cy="300" rx="4" ry="28" fill="${fill}" opacity=".06" />
                        <ellipse cx="459" cy="300" rx="4" ry="28" fill="${fill}" opacity=".06" />`;
    },
    'nose shadow'
);

// 4. Cheek blush pulled inside the narrower face
function replaceOptionalExact(oldStr, newStr, label) {
    const n = out.split(oldStr).length - 1;
    if (n === 0) { console.log(`  (skipped: ${label} not found)`); return; }
    if (n > 1) fail(`[${label}] expected at most 1 match, found ${n}.`);
    out = out.split(oldStr).join(newStr);
}

replaceOptionalExact(
    '<ellipse cx="378" cy="326" rx="36" ry="22" fill="url(#jc-cheek)" stroke="none" />',
    `{{-- Face structure: cheekbone light and soft contour under the cheekbones, mirrored --}}
                    <g clip-path="url(#jc-face-clip)" stroke="none">
                        <ellipse cx="372" cy="352" rx="24" ry="12" fill="${t.dk}" opacity=".12" transform="rotate(38 372 352)" />
                        <ellipse cx="528" cy="352" rx="24" ry="12" fill="${t.dk}" opacity=".12" transform="rotate(-38 528 352)" />
                        <ellipse cx="386" cy="296" rx="22" ry="8" fill="${t.hi}" opacity=".14" transform="rotate(-20 386 296)" />
                        <ellipse cx="514" cy="296" rx="22" ry="8" fill="${t.hi}" opacity=".14" transform="rotate(20 514 296)" />
                    </g>
                    <ellipse cx="388" cy="320" rx="30" ry="19" fill="url(#jc-cheek)" stroke="none" />`,
    'left cheek'
);
replaceOptionalExact(
    '<ellipse cx="522" cy="326" rx="36" ry="22" fill="url(#jc-cheek)" stroke="none" />',
    '<ellipse cx="512" cy="320" rx="30" ry="19" fill="url(#jc-cheek)" stroke="none" />',
    'right cheek'
);

// 5. Cheek-edge nodes sit on the new outline
replaceOptionalExact(
    '<circle class="jc-holo-node" cx="337" cy="284" r="2.4" fill="#d8ffff" stroke="none" />',
    '<circle class="jc-holo-node" cx="341" cy="284" r="2.4" fill="#d8ffff" stroke="none" />',
    'left edge node'
);
replaceOptionalExact(
    '<circle class="jc-holo-node" cx="563" cy="284" r="2.4" fill="#d8ffff" stroke="none" />',
    '<circle class="jc-holo-node" cx="559" cy="284" r="2.4" fill="#d8ffff" stroke="none" />',
    'right edge node'
);

fs.copyFileSync(file, file + '.pre-face.bak');
fs.writeFileSync(file, out, 'utf8');
console.log(`Done. Face reshaped. Backup: ${file}.pre-face.bak`);
