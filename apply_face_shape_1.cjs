#!/usr/bin/env node
/*
 * Refines the JARVIS face and adds an asymmetric cybernetic light treatment.
 *
 * Usage:
 *   node apply_face_shape_1.cjs <file> [tone]
 *
 * Tone: fair | light | medium | tan | brown | deep (default: medium)
 * The script accepts either the original face outline or the refined outline,
 * adds the cyber treatment once, and saves a .pre-face.bak backup.
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
    console.error('Usage: node apply_face_shape_1.cjs <file> [tone]');
    process.exit(1);
}

if (!TONES[toneName]) {
    console.error(`Unknown tone "${toneName}". Choose: ${Object.keys(TONES).join(', ')}`);
    process.exit(1);
}

let out = fs.readFileSync(file, 'utf8');

function fail(message) {
    console.error(`${message} No changes written.`);
    process.exit(1);
}

const CURRENT_FACE =
    'M450 92 C384 92 342 140 340 214 C339 250 340 282 348 314 C358 352 388 392 428 418 C438 424 444 427 450 427 C456 427 462 424 472 418 C512 392 542 352 552 314 C560 282 561 250 560 214 C558 140 516 92 450 92Z';

const FEMININE_FACE =
    'M450 88 C392 88 352 126 345 188 C341 224 341 260 348 292 C355 326 370 355 391 378 C407 396 427 411 441 419 C445 422 448 424 450 425 C452 424 455 422 459 419 C473 411 493 396 509 378 C530 355 545 326 552 292 C559 260 559 224 555 188 C548 126 508 88 450 88Z';

const marker = 'id="jc-face-cyber-etch"';
if (out.includes(marker)) {
    console.log('[face treatment] cybernetic face treatment is already applied. No changes written.');
    process.exit(0);
}

const currentFaceCount = out.split(CURRENT_FACE).length - 1;
const feminineFaceCount = out.split(FEMININE_FACE).length - 1;

if (currentFaceCount === 3) {
    out = out.split(CURRENT_FACE).join(FEMININE_FACE);
} else if (feminineFaceCount !== 3) {
    fail(
        `[face outline] expected 3 matches of the original or refined face outline, ` +
        `found ${currentFaceCount} original and ${feminineFaceCount} refined.`
    );
}

const faceGradient =
    /<radialGradient id="jc-face-glass"[^>]*>[\s\S]*?<\/radialGradient>/g;
const highlightGradient =
    /<linearGradient id="jc-face-highlight"[^>]*>[\s\S]*?<\/linearGradient>/g;

if ((out.match(faceGradient) || []).length !== 1) {
    fail('[face gradient] expected one jc-face-glass gradient.');
}
if ((out.match(highlightGradient) || []).length !== 1) {
    fail('[face highlight] expected one jc-face-highlight gradient.');
}
if (!out.includes('<g id="jc-circuit-plate"')) {
    fail('[cyber details] expected the existing jc-circuit-plate group.');
}

out = out.replace(faceGradient, `<radialGradient id="jc-face-glass" cx="46%" cy="32%" r="80%">
                    <stop offset="0"   stop-color="${TONES[toneName].hi}" />
                    <stop offset=".24" stop-color="#dfc9bc" />
                    <stop offset=".58" stop-color="#a88f88" />
                    <stop offset="1"   stop-color="${TONES[toneName].dk}" />
                </radialGradient>`);

out = out.replace(highlightGradient, `<linearGradient id="jc-face-highlight" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0"   stop-color="#ff8a35" stop-opacity=".3" />
                    <stop offset=".16" stop-color="#ffb15a" stop-opacity=".1" />
                    <stop offset=".43" stop-color="#fffaf4" stop-opacity=".08" />
                    <stop offset=".7"  stop-color="#55eaff" stop-opacity=".09" />
                    <stop offset="1"   stop-color="#28cfff" stop-opacity=".3" />
                </linearGradient>`);

const circuitTreatment = `{{-- Split-spectrum cybernetic detail: amber left rim, cyan right-side circuitry --}}
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

                    `;

const hairAnchor = '{{-- Hair, front layer: swept fringe with light strands --}}';
if (out.split(hairAnchor).length !== 2) {
    fail('[cyber details] expected one hair-front insertion point.');
}

out = out.replace(hairAnchor, circuitTreatment + hairAnchor);

let backupPath = `${file}.pre-face.bak`;
if (fs.existsSync(backupPath)) {
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    backupPath = `${file}.pre-face-${timestamp}.bak`;
}

fs.copyFileSync(file, backupPath);
fs.writeFileSync(file, out, 'utf8');

console.log('Done. Refined face shape and split-spectrum cybernetic detail applied.');
console.log(`Tone: ${toneName}`);
console.log(`Backup: ${backupPath}`);
console.log('Updated: face silhouette, cool skin shading, amber/cyan lighting and cheek circuitry');
