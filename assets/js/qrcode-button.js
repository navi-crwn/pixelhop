(function(global){
/**
 * PixelHop - Offline QR Code Generator
 * Pure JavaScript QR code renderer. No external APIs, no fetch, no CDN.
 * Implements a minimal byte-mode QR encoder for alphanumeric/URL payloads.
 *
 * Usage:
 *   generateQR(text, document.getElementById('qr-container'));
 *
 * Supports QR versions 1-10, error correction levels L/M/Q/H.
 */


    'use strict';

    // Galois Field GF(256) tables
    const GF_EXP = new Uint8Array(512);
    const GF_LOG = new Uint8Array(256);
    (function initGF() {
        let x = 1;
        for (let i = 0; i < 255; i++) {
            GF_EXP[i] = x;
            GF_EXP[i + 255] = x;
            GF_LOG[x] = i;
            x <<= 1;
            if (x & 0x100) x ^= 0x11d;
        }
        GF_EXP[255] = 1;
        GF_LOG[0] = 0;
    })();

    function gfMul(a, b) {
        if (a === 0 || b === 0) return 0;
        return GF_EXP[GF_LOG[a] + GF_LOG[b]];
    }

    // Reed-Solomon generator polynomial
    function rsGeneratorPoly(n) {
        const g = [1];
        for (let i = 0; i < n; i++) {
            const next = [0];
            for (let j = 0; j < g.length; j++) next.push(g[j]);
            for (let j = 0; j < g.length; j++) {
                next[j] ^= gfMul(g[j], GF_EXP[i]);
            }
            for (let j = 0; j < g.length; j++) g[j] = next[j];
            g.push(next[g.length]);
        }
        return g;
    }

    function rsEncode(data, ecLen) {
        const g = rsGeneratorPoly(ecLen);
        const ec = new Uint8Array(ecLen);
        for (let i = 0; i < data.length; i++) {
            const coef = data[i] ^ ec[0];
            ec.copyWithin(0, 1);
            ec[ecLen - 1] = 0;
            for (let j = 0; j < ecLen; j++) {
                ec[j] ^= gfMul(g[j], coef);
            }
        }
        return ec;
    }

    // QR capacity table [version][L,M,Q,H] = total codewords
    const QR_CAPACITY = {
        1: [19, 16, 13, 9],
        2: [34, 28, 22, 16],
        3: [55, 44, 34, 26],
        4: [80, 64, 48, 36],
        5: [108, 86, 62, 46],
        6: [136, 108, 76, 60],
        7: [156, 124, 88, 66],
        8: [194, 154, 110, 86],
        9: [232, 182, 132, 100],
        10: [274, 216, 154, 122],
    };

    // EC codewords per block and group structure
    // [totalBlocks, ecPerBlock]
    const QR_EC_INFO = {
        1: { L: [1, 7], M: [1, 10], Q: [1, 13], H: [1, 17] },
        2: { L: [1, 10], M: [1, 16], Q: [1, 22], H: [1, 28] },
        3: { L: [1, 15], M: [1, 26], Q: [2, 18], H: [2, 22] },
        4: { L: [1, 20], M: [2, 18], Q: [2, 26], H: [4, 16] },
        5: { L: [1, 26], M: [2, 24], Q: [4, 18], H: [4, 24] },
        6: { L: [2, 18], M: [4, 16], Q: [4, 24], H: [4, 30] },
        7: { L: [2, 20], M: [4, 18], Q: [5, 20], H: [6, 18] },
        8: { L: [2, 24], M: [4, 22], Q: [6, 20], H: [6, 24] },
        9: { L: [2, 30], M: [5, 22], Q: [8, 20], H: [8, 22] },
        10: { L: [4, 18], M: [5, 26], Q: [8, 24], H: [8, 28] },
    };

    // Number of data codewords per block
    function getBlockInfo(version, level) {
        const info = QR_EC_INFO[version][level];
        const total = QR_CAPACITY[version][['L', 'M', 'Q', 'H'].indexOf(level)];
        const ecTotal = info[0] * info[1];
        const dataTotal = total - ecTotal;
        const base = Math.floor(dataTotal / info[0]);
        const extra = dataTotal - base * info[0];
        return { blocks: info[0], ecPerBlock: info[1], base, extra };
    }

    // Mode indicator for byte mode
    const MODE_BYTE = 0b0100;

    // Align pattern centers
    const ALIGNMENT_POSITIONS = [
        [],
        [],
        [6, 18],
        [6, 22],
        [6, 26],
        [6, 30],
        [6, 34],
        [6, 22, 38],
        [6, 24, 42],
        [6, 26, 46],
        [6, 28, 50],
    ];

    // Format info strings (format = EC level << 3 | mask)
    function formatBits(format) { let msg = format << 10; for (let i = 14; i >= 10; i--) { if ((msg >> i) & 1) msg ^= 0x537 << (i - 10); } return (((format << 10) | (msg & 0x3ff)) ^ 0x5412) >>> 0; }

    // Version info for version 7+
    function versionBits(version) {
        let v = version;
        let g = 0x1f25;
        v <<= 12;
        let b = v;
        while (b >> 12) b = (b >> 12) << 12 ^ g;
        return (v | b) >>> 0;
    }

    // BCH mask patterns
    const MASKS = [
        (i, j) => (i + j) % 2 === 0,
        (i, j) => i % 2 === 0,
        (i, j) => j % 3 === 0,
        (i, j) => (i + j) % 3 === 0,
        (i, j) => (Math.floor(i / 2) + Math.floor(j / 3)) % 2 === 0,
        (i, j) => (i * j) % 2 + (i * j) % 3 === 0,
        (i, j) => ((i * j) % 2 + (i * j) % 3) % 2 === 0,
        (i, j) => ((i + j) % 2 + (i * j) % 3) % 2 === 0,
    ];

    // Encode text to UTF-8 bytes
    function utf8Bytes(text) {
        return new TextEncoder().encode(text);
    }

    // Build raw data bit stream (byte mode)
    function encodeData(text, version, level) {
        const bits = [];
        const pushBits = (val, n) => {
            for (let i = n - 1; i >= 0; i--) bits.push((val >> i) & 1);
        };
        pushBits(MODE_BYTE, 4);
        const bytes = utf8Bytes(text);
        const cciBits = version >= 10 ? 16 : 8;
        pushBits(bytes.length, cciBits);
        for (const b of bytes) pushBits(b, 8);

        const totalCodewords = QR_CAPACITY[version][['L', 'M', 'Q', 'H'].indexOf(level)];
        const totalBits = totalCodewords * 8;
        const remainder = totalBits - bits.length;
        // terminator
        pushBits(0, Math.min(4, remainder));
        // pad to byte boundary
        while (bits.length % 8 !== 0) bits.push(0);
        // pad bytes
        const pad = [0b11101100, 0b00010001];
        for (let i = 0; bits.length < totalBits; i++) pushBits(pad[i % 2], 8);

        const codewords = [];
        for (let i = 0; i < bits.length; i += 8) {
            let byte = 0;
            for (let j = 0; j < 8; j++) byte = (byte << 1) | bits[i + j];
            codewords.push(byte);
        }
        return codewords;
    }

    // Create QR matrix
    function createMatrix(version) {
        const size = 17 + version * 4;
        return Array.from({ length: size }, () => new Int8Array(size).fill(-1));
    }

    function placeFinderPatterns(matrix) {
        const size = matrix.length;
        const positions = [[0, 0], [0, size - 7], [size - 7, 0]];
        for (const [row, col] of positions) {
            for (let r = -1; r <= 7; r++) {
                for (let c = -1; c <= 7; c++) {
                    const rr = row + r, cc = col + c;
                    if (rr < 0 || rr >= size || cc < 0 || cc >= size) continue;
                    if ((r >= 0 && r <= 6 && c >= 0 && c <= 6) &&
                        (r === 0 || r === 6 || c === 0 || c === 6 || (r >= 2 && r <= 4 && c >= 2 && c <= 4))) {
                        matrix[rr][cc] = 1;
                    } else {
                        matrix[rr][cc] = 0;
                    }
                }
            }
        }
    }

    function placeSeparators(matrix) {
        const size = matrix.length;
        const seps = [
            [0, 7, 0, 7], [0, 7, size - 8, size - 1], [size - 8, size - 1, 0, 7]
        ];
        for (const [r0, r1, c0, c1] of seps) {
            for (let r = r0; r <= r1; r++) {
                for (let c = c0; c <= c1; c++) matrix[r][c] = 0;
            }
        }
    }

    function placeTimingPatterns(matrix) {
        const size = matrix.length;
        for (let i = 8; i < size - 8; i++) {
            matrix[6][i] = i % 2 === 0 ? 1 : 0;
            matrix[i][6] = i % 2 === 0 ? 1 : 0;
        }
    }

    function placeAlignmentPatterns(matrix, version) {
        if (version < 2) return;
        const positions = ALIGNMENT_POSITIONS[version];
        for (const row of positions) {
            for (const col of positions) {
                if (matrix[row][col] !== -1) continue;
                for (let r = -2; r <= 2; r++) {
                    for (let c = -2; c <= 2; c++) {
                        const v = (Math.abs(r) === 2 || Math.abs(c) === 2 || (r === 0 && c === 0)) ? 1 : 0;
                        matrix[row + r][col + c] = v;
                    }
                }
            }
        }
    }

    function placeDarkModule(matrix, version) {
        matrix[4 * version + 9][8] = 1;
    }

    function placeFormatInfo(matrix, format) {
        const size = matrix.length;
        const bits = formatBits(format);
        for (let i = 0; i < 15; i++) {
            const bit = (bits >> i) & 1;
            if (i < 6) matrix[8][i] = bit;
            else if (i < 8) matrix[8][i + 1] = bit;
            else if (i === 8) matrix[7][8] = bit;
            else matrix[14 - i][8] = bit;
        }
        for (let i = 0; i < 15; i++) {
            const bit = (bits >> i) & 1;
            if (i < 8) {
                matrix[size - 1 - i][8] = bit;
            } else if (i === 8) {
                matrix[8][size - 7] = bit;
            } else {
                matrix[8][14 - i] = bit;
            }
        }
    }

    function placeVersionInfo(matrix, version) {
        if (version < 7) return;
        const bits = versionBits(version);
        const size = matrix.length;
        for (let i = 0; i < 18; i++) {
            const bit = (bits >> i) & 1;
            matrix[Math.floor(i / 3)][size - 11 + (i % 3)] = bit;
            matrix[size - 11 + (i % 3)][Math.floor(i / 3)] = bit;
        }
    }

    function reserveFormatAreas(matrix) {
        const size = matrix.length;
        // Top-left
        for (let i = 0; i < 9; i++) {
            if (matrix[8][i] === -1) matrix[8][i] = -2;
            if (i < 8 && matrix[i][8] === -1) matrix[i][8] = -2;
        }
        // Bottom-left and top-right
        for (let i = 0; i < 8; i++) {
            if (matrix[size - 1 - i][8] === -1) matrix[size - 1 - i][8] = -2;
            if (matrix[8][size - 1 - i] === -1) matrix[8][size - 1 - i] = -2;
        }
    }

    function placeData(matrix, data) {
        const size = matrix.length;
        let bitIndex = 0;
        for (let col = size - 1; col > 0; col -= 2) {
            if (col === 6) col--;
            const upward = ((size - col) % 4) < 2;
            for (let i = 0; i < size; i++) {
                const row = upward ? size - 1 - i : i;
                for (let c = 0; c < 2; c++) {
                    const cc = col - c;
                    if (matrix[row][cc] !== -1) continue;
                    const bit = bitIndex < data.length ? data[bitIndex] : 0;
                    matrix[row][cc] = bit;
                    bitIndex++;
                }
            }
        }
    }

    function applyMask(matrix, maskIndex) {
        const size = matrix.length;
        const mask = MASKS[maskIndex];
        for (let r = 0; r < size; r++) {
            for (let c = 0; c < size; c++) {
                if (matrix[r][c] >= 0 && matrix[r][c] <= 1 && mask(r, c)) {
                    matrix[r][c] ^= 1;
                }
            }
        }
    }

    function maskPenalty(matrix) {
        const size = matrix.length;
        let penalty = 0;
        // Rule 1: adjacent same-color modules
        for (let r = 0; r < size; r++) {
            let count = 1;
            for (let c = 1; c < size; c++) {
                if (matrix[r][c] === matrix[r][c - 1]) count++;
                else { if (count >= 5) penalty += count - 2; count = 1; }
            }
            if (count >= 5) penalty += count - 2;
        }
        for (let c = 0; c < size; c++) {
            let count = 1;
            for (let r = 1; r < size; r++) {
                if (matrix[r][c] === matrix[r - 1][c]) count++;
                else { if (count >= 5) penalty += count - 2; count = 1; }
            }
            if (count >= 5) penalty += count - 2;
        }
        // Rule 2: blocks of same color
        for (let r = 0; r < size - 1; r++) {
            for (let c = 0; c < size - 1; c++) {
                const v = matrix[r][c];
                if (matrix[r][c + 1] === v && matrix[r + 1][c] === v && matrix[r + 1][c + 1] === v) penalty += 3;
            }
        }
        // Rule 3: finder-like patterns
        const pattern1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        const pattern2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
        for (let r = 0; r < size; r++) {
            for (let c = 0; c <= size - 11; c++) {
                const row = [];
                for (let k = 0; k < 11; k++) row.push(matrix[r][c + k]);
                if (row.every((v, i) => v === pattern1[i]) || row.every((v, i) => v === pattern2[i])) penalty += 40;
            }
        }
        for (let c = 0; c < size; c++) {
            for (let r = 0; r <= size - 11; r++) {
                const col = [];
                for (let k = 0; k < 11; k++) col.push(matrix[r + k][c]);
                if (col.every((v, i) => v === pattern1[i]) || col.every((v, i) => v === pattern2[i])) penalty += 40;
            }
        }
        // Rule 4: balance
        let dark = 0;
        for (let r = 0; r < size; r++) for (let c = 0; c < size; c++) if (matrix[r][c] === 1) dark++;
        const percent = Math.abs((dark * 100 / (size * size)) - 50) / 5;
        penalty += Math.floor(percent) * 10;
        return penalty;
    }

    // Interleave data and EC codewords
    function interleave(version, level, dataCodewords) {
        const info = getBlockInfo(version, level);
        const blocks = [];
        let idx = 0;
        for (let b = 0; b < info.blocks; b++) {
            const len = b < info.extra ? info.base + 1 : info.base;
            blocks.push(dataCodewords.slice(idx, idx + len));
            idx += len;
        }
        const ecBlocks = blocks.map(b => Array.from(rsEncode(new Uint8Array(b), info.ecPerBlock)));
        const out = [];
        const maxData = Math.max(...blocks.map(b => b.length));
        for (let i = 0; i < maxData; i++) {
            for (const b of blocks) if (i < b.length) out.push(b[i]);
        }
        for (let i = 0; i < info.ecPerBlock; i++) {
            for (const b of ecBlocks) out.push(b[i]);
        }
        return out;
    }

    function buildQR(text, level) {
        // Pick smallest version that fits byte-mode data
        const bytes = utf8Bytes(text);
        let version = 0;
        const levelIndex = { L: 0, M: 1, Q: 2, H: 3 }[level] || 0;
        for (let v = 1; v <= 10; v++) {
            const capacity = QR_CAPACITY[v][levelIndex];
            const overhead = 4 + (v >= 10 ? 16 : 8);
            if (overhead + bytes.length * 8 <= capacity * 8) {
                version = v;
                break;
            }
        }
        if (!version) throw new Error('Text too long for QR');

        const dataCodewords = encodeData(text, version, level);
        const finalCodewords = interleave(version, level, dataCodewords);
        const dataBits = [];
        for (const cw of finalCodewords) {
            for (let i = 7; i >= 0; i--) dataBits.push((cw >> i) & 1);
        }
        const remainderBits = [0, 7, 7, 7, 7, 7, 0, 0, 0, 0][version] || 0;
        for (let i = 0; i < remainderBits; i++) dataBits.push(0);

        let bestMatrix = null;
        let bestPenalty = Infinity;
        let bestMask = 0;
        const format = (levelIndex << 3);
        for (let mask = 0; mask < 8; mask++) {
            const matrix = createMatrix(version);
            placeFinderPatterns(matrix);
            placeSeparators(matrix);
            placeTimingPatterns(matrix);
            placeAlignmentPatterns(matrix, version);
            placeDarkModule(matrix, version);
            reserveFormatAreas(matrix);
            placeData(matrix, dataBits);
            placeFormatInfo(matrix, format | mask);
            placeVersionInfo(matrix, version);
            applyMask(matrix, mask);
            const penalty = maskPenalty(matrix);
            if (penalty < bestPenalty) {
                bestPenalty = penalty;
                bestMatrix = matrix;
                bestMask = mask;
            }
        }
        return { matrix: bestMatrix, version, mask: bestMask };
    }

    // Render QR to canvas
    function renderQR(text, options) {
        const { matrix } = buildQR(text, options.level || 'M');
        const size = matrix.length;
        const margin = options.margin ?? 4;
        const moduleSize = options.size ? Math.floor(options.size / (size + margin * 2)) : 6;
        const canvasSize = moduleSize * (size + margin * 2);
        const canvas = document.createElement('canvas');
        canvas.width = canvasSize;
        canvas.height = canvasSize;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = options.bgColor || '#ffffff';
        ctx.fillRect(0, 0, canvasSize, canvasSize);
        ctx.fillStyle = options.fgColor || '#000000';
        for (let r = 0; r < size; r++) {
            for (let c = 0; c < size; c++) {
                if (matrix[r][c] === 1) {
                    ctx.fillRect((c + margin) * moduleSize, (r + margin) * moduleSize, moduleSize, moduleSize);
                }
            }
        }
        return canvas;
    }

    // Public API
    function generateQR(text, container, options) {
        if (!container) throw new Error('generateQR requires a container element');
        container.innerHTML = '';
        const opts = Object.assign({ size: 220, level: 'M', fgColor: '#000000', bgColor: '#ffffff' }, options || {});
        const canvas = renderQR(text, opts);
        container.appendChild(canvas);
        return canvas;
    }

    // Expose
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { generateQR };
    }
    global.generateQR = generateQR;

})((typeof window !== "undefined" ? window : this));