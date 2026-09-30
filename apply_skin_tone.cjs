#!/usr/bin/env node
/*
 * Applies a human skin tone to the JC avatar (run AFTER apply_steel_skin.cjs).
 *
 * Usage:   node apply_skin_tone.cjs <file> [tone]
 * Tones:   fair | light | medium | tan | brown | deep     (default: medium)
 * Result:  file edited in place; original saved as <file>.pre-skin.bak
 *
 * Recolors: face, lips, cheek blush, and adds a matching neck.
 * Keeps:    hair, clothing, pose, cool blue-white rim light, all animation hooks.
 * Softens:  the steel seams and brushed grain become faint skin contours.
 *
 * To undo: copy <file>.pre-skin.bak back over <file>.
 */
const fs = require('fs');

// hi = highlight, lt = light, md = mid, sh = shadow, lip1/lip2 = lip top/bottom
const TONES = {
    fair:   { hi: '#fdf0e6', lt: '#f3d3bd', md: '#dcae92', sh: '#a87a62', lip1: '#dc918c', lip2: '#b8635f' },
    light:  { hi: '#fbe8d8', lt: '#efc7a8', md: '#d09b76', sh: '#9a6a50', lip1: '#d2857e', lip2: '#ad5c58' },
    medium: { hi: '#f6d9bd', lt: '#e3ae84', md: '#c1875f', sh: '#875840', lip1: '#c6766d', lip2: '#9f524c' },
    tan:    { hi: '#e9bd96', lt: '#cd9468', md: '#aa7048', sh: '#724a33', lip1: '#b3665d', lip2: '#8c4741' },
    brown:  { hi: '#c48b68', lt: '#a26748', md: '#7e4b33', sh: '#52301f', lip1: '#96574e', lip2: '#753b36' },
    deep:   { hi: '#956349', lt: '#74472f', md: '#55321f', sh: '#361d12', lip1: '#76413a', lip2: '#5a302b' },
};

const file = process.argv[2];
const toneName = (process.argv[3] || 'medium').toLowerCase();

if (!file) {
    console.error('Usage: node apply_skin_tone.cjs <file> [fair|light|medium|tan|brown|deep]');
    process.exit(1);
}
const t = TONES[toneName];
if (!t) {
    console.error(`Unknown tone "${toneName}". Choose: ${Object.keys(TONES).join(', ')}`);
    process.exit(1);
}

let out = fs.readFileSync(file, 'utf8');

function fail(msg) {
    console.error(msg + ' No changes written.');
    process.exit(1);
}

if (out.includes('id="jc-skin-neck"')) {
    fail('A skin tone is already applied. Restore the .pre-skin.bak first to switch tones.');
}
if (!out.includes('id="jc-brushed"')) {
    fail('Steel restyle not found. Run apply_steel_skin.cjs first.');
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

// 1. Face skin: opaque, lit from the upper left
replaceGradient('jc-face-glass', `<radialGradient id="jc-face-glass" cx="42%" cy="30%" r="80%">
                    <stop offset="0"   stop-color="${t.hi}" />
                    <stop offset=".28" stop-color="${t.lt}" />
                    <stop offset=".62" stop-color="${t.md}" />
                    <stop offset="1"   stop-color="${t.sh}" />
                </radialGradient>`);

// 2. Lips
replaceGradient('jc-lip', `<linearGradient id="jc-lip" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="${t.lip1}" stop-opacity=".96" />
                    <stop offset="1" stop-color="${t.lip2}" stop-opacity=".92" />
                </linearGradient>`);

// 3. Cheek blush
replaceGradient('jc-cheek', `<radialGradient id="jc-cheek" cx="50%" cy="50%" r="50%">
                    <stop offset="0" stop-color="${t.lip1}" stop-opacity=".34" />
                    <stop offset="1" stop-color="${t.lip1}" stop-opacity="0" />
                </radialGradient>`);

// 4. Soft light on skin, cool blue rim kept on the right
replaceGradient('jc-face-highlight', `<linearGradient id="jc-face-highlight" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0"   stop-color="#fffaf4" stop-opacity=".34" />
                    <stop offset=".25" stop-color="#fff2e6" stop-opacity=".1" />
                    <stop offset=".55" stop-color="#ffffff" stop-opacity="0" />
                    <stop offset="1"   stop-color="#8ec8ff" stop-opacity=".3" />
                </linearGradient>`);

// 5. Neck gradients (added to <defs>)
replaceOnce(
    '{{-- New: iris, cheeks, lips, hair --}}',
    `{{-- Skin tone: neck shading --}}
                <linearGradient id="jc-skin-neck" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0"   stop-color="${t.sh}" />
                    <stop offset=".28" stop-color="${t.md}" />
                    <stop offset=".5"  stop-color="${t.lt}" />
                    <stop offset=".72" stop-color="${t.md}" />
                    <stop offset="1"   stop-color="${t.sh}" />
                </linearGradient>
                <linearGradient id="jc-skin-neck-shade" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0"   stop-color="#000000" stop-opacity=".42" />
                    <stop offset=".45" stop-color="#000000" stop-opacity=".1" />
                    <stop offset="1"   stop-color="#000000" stop-opacity="0" />
                </linearGradient>

                {{-- New: iris, cheeks, lips, hair --}}`,
    'defs additions'
);

// 6. Neck patch: drawn above the gunmetal bust, below the head (so the jaw overlaps it)
replaceOnce(
    '{{-- Head. The outer group',
    `{{-- Neck (skin tone) --}}
            <path d="M397 397 L397 458 Q450 486 503 458 L503 397Z" fill="url(#jc-skin-neck)" stroke="none" />
            <path d="M397 397 L397 458 Q450 486 503 458 L503 397Z" fill="url(#jc-skin-neck-shade)" stroke="none" />
            <path d="M397 397 L397 458 Q450 486 503 458 L503 397Z" fill="none" stroke="url(#jc-holo-stroke)" stroke-opacity=".55" stroke-width="1.4" />

            {{-- Head. The outer group`,
    'neck patch'
);

// 7. Soften steel seams and grain into faint skin contours
replaceOnce(
    '<use href="#jc-seams" stroke="#0c151e" stroke-opacity=".5" stroke-width="1" />',
    `<use href="#jc-seams" stroke="${t.sh}" stroke-opacity=".28" stroke-width="1" />`,
    'seam groove'
);
replaceOnce(
    '<use href="#jc-seams" transform="translate(0 1.3)" stroke="#ffffff" stroke-opacity=".38" stroke-width=".8" />',
    '<use href="#jc-seams" transform="translate(0 1.3)" stroke="#ffffff" stroke-opacity=".1" stroke-width=".8" />',
    'seam bevel'
);
replaceOnce(
    'fill="url(#jc-brushed)" style="mix-blend-mode:soft-light" opacity=".9"',
    'fill="url(#jc-brushed)" style="mix-blend-mode:soft-light" opacity=".22"',
    'face grain'
);
replaceOnce(
    '<g clip-path="url(#jc-face-clip)" fill="none" stroke="#0f1a24" stroke-width="1" opacity=".16">',
    `<g clip-path="url(#jc-face-clip)" fill="none" stroke="${t.sh}" stroke-width="1" opacity=".1">`,
    'face wireframe'
);

fs.copyFileSync(file, file + '.pre-skin.bak');
fs.writeFileSync(file, out, 'utf8');
console.log(`Done. "${toneName}" tone applied. Backup saved to ${file}.pre-skin.bak`);
