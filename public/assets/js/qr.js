/* =========================================================================
 * Minimal QR encoder — byte mode, error-correction level M, versions 1–6.
 *
 * Capped at version 6 (≈106 bytes) deliberately: version 7 and above also
 * require an 18-bit version-information block, and emitting a code without one
 * would produce something that looks right but does not scan.
 *
 * Self-contained on purpose: the admin runs under a strict no-external-request
 * posture, so a share QR must be generated locally rather than fetched from an
 * image service that would also leak the funnel URL to a third party.
 *
 * window.LumeraQR.svg(text, options) -> SVG string
 * ========================================================================= */
(function () {
    'use strict';

    /* ------------------------------------------------------- galois field */
    var EXP = new Array(512);
    var LOG = new Array(256);

    (function () {
        var x = 1;
        for (var i = 0; i < 255; i++) {
            EXP[i] = x;
            LOG[x] = i;
            x <<= 1;
            if (x & 0x100) { x ^= 0x11d; }
        }
        for (var j = 255; j < 512; j++) { EXP[j] = EXP[j - 255]; }
    })();

    function mul(a, b) {
        if (a === 0 || b === 0) { return 0; }
        return EXP[LOG[a] + LOG[b]];
    }

    /** Reed-Solomon generator polynomial of the given degree. */
    function generator(degree) {
        var poly = [1];
        for (var i = 0; i < degree; i++) {
            var next = new Array(poly.length + 1).fill(0);
            for (var j = 0; j < poly.length; j++) {
                next[j] ^= poly[j];
                next[j + 1] ^= mul(poly[j], EXP[i]);
            }
            poly = next;
        }
        return poly;
    }

    function ecBytes(data, ecLen) {
        var gen = generator(ecLen);
        var res = new Array(ecLen).fill(0);

        for (var i = 0; i < data.length; i++) {
            var factor = data[i] ^ res[0];
            res.shift();
            res.push(0);
            for (var j = 0; j < ecLen; j++) {
                res[j] ^= mul(gen[j + 1], factor);
            }
        }
        return res;
    }

    /* --------------------------------------------------- version capacity */
    /* [total codewords, ec codewords per block, block counts] for level M. */
    var VERSIONS = [
        null,
        { total: 26,  ec: 10, g1: 1, d1: 16 },
        { total: 44,  ec: 16, g1: 1, d1: 28 },
        { total: 70,  ec: 26, g1: 1, d1: 44 },
        { total: 100, ec: 18, g1: 2, d1: 32 },
        { total: 134, ec: 24, g1: 2, d1: 43 },
        { total: 172, ec: 16, g1: 4, d1: 27 },
        { total: 196, ec: 18, g1: 4, d1: 31 },
        { total: 242, ec: 22, g1: 2, d1: 38, g2: 2, d2: 39 },
        { total: 292, ec: 22, g1: 3, d1: 36, g2: 2, d2: 37 },
        { total: 346, ec: 26, g1: 4, d1: 43, g2: 1, d2: 44 }
    ];

    var ALIGN = [
        null, [], [6, 18], [6, 22], [6, 26], [6, 30],
        [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]
    ];

    function dataCapacity(v) {
        var spec = VERSIONS[v];
        return spec.g1 * spec.d1 + (spec.g2 || 0) * (spec.d2 || 0);
    }

    /* ---------------------------------------------------------- encoding */
    function toBytes(text) {
        var out = [];
        for (var i = 0; i < text.length; i++) {
            var c = text.charCodeAt(i);
            if (c < 0x80) {
                out.push(c);
            } else if (c < 0x800) {
                out.push(0xc0 | (c >> 6), 0x80 | (c & 0x3f));
            } else {
                out.push(0xe0 | (c >> 12), 0x80 | ((c >> 6) & 0x3f), 0x80 | (c & 0x3f));
            }
        }
        return out;
    }

    function buildData(bytes, version) {
        var capacity = dataCapacity(version);
        var bits = [];

        function push(value, length) {
            for (var i = length - 1; i >= 0; i--) { bits.push((value >> i) & 1); }
        }

        push(4, 4);                                   // byte mode
        push(bytes.length, version < 10 ? 8 : 16);    // character count
        bytes.forEach(function (b) { push(b, 8); });

        // Terminator + byte alignment.
        var max = capacity * 8;
        for (var t = 0; t < 4 && bits.length < max; t++) { bits.push(0); }
        while (bits.length % 8 !== 0) { bits.push(0); }

        var words = [];
        for (var i = 0; i < bits.length; i += 8) {
            var byte = 0;
            for (var j = 0; j < 8; j++) { byte = (byte << 1) | bits[i + j]; }
            words.push(byte);
        }

        var pad = [0xec, 0x11];
        var p = 0;
        while (words.length < capacity) { words.push(pad[p++ % 2]); }

        return words;
    }

    function interleave(words, version) {
        var spec = VERSIONS[version];
        var blocks = [];
        var offset = 0;

        for (var i = 0; i < spec.g1; i++) {
            blocks.push(words.slice(offset, offset + spec.d1));
            offset += spec.d1;
        }
        for (var k = 0; k < (spec.g2 || 0); k++) {
            blocks.push(words.slice(offset, offset + spec.d2));
            offset += spec.d2;
        }

        var ecs = blocks.map(function (b) { return ecBytes(b, spec.ec); });

        var out = [];
        var maxData = Math.max.apply(null, blocks.map(function (b) { return b.length; }));

        for (var c = 0; c < maxData; c++) {
            blocks.forEach(function (b) { if (c < b.length) { out.push(b[c]); } });
        }
        for (var e = 0; e < spec.ec; e++) {
            ecs.forEach(function (b) { out.push(b[e]); });
        }

        return out;
    }

    /* ----------------------------------------------------------- matrix */
    function place(version, words) {
        var size = version * 4 + 17;
        var m = [];
        var reserved = [];

        for (var i = 0; i < size; i++) {
            m.push(new Array(size).fill(0));
            reserved.push(new Array(size).fill(false));
        }

        function finder(r, c) {
            for (var dr = -1; dr <= 7; dr++) {
                for (var dc = -1; dc <= 7; dc++) {
                    var rr = r + dr, cc = c + dc;
                    if (rr < 0 || cc < 0 || rr >= size || cc >= size) { continue; }
                    var on = (dr >= 0 && dr <= 6 && (dc === 0 || dc === 6))
                        || (dc >= 0 && dc <= 6 && (dr === 0 || dr === 6))
                        || (dr >= 2 && dr <= 4 && dc >= 2 && dc <= 4);
                    m[rr][cc] = on ? 1 : 0;
                    reserved[rr][cc] = true;
                }
            }
        }

        finder(0, 0);
        finder(0, size - 7);
        finder(size - 7, 0);

        // Timing patterns.
        for (var t = 8; t < size - 8; t++) {
            m[6][t] = t % 2 === 0 ? 1 : 0;
            m[t][6] = t % 2 === 0 ? 1 : 0;
            reserved[6][t] = true;
            reserved[t][6] = true;
        }

        // Alignment patterns.
        var centres = ALIGN[version] || [];
        centres.forEach(function (r) {
            centres.forEach(function (c) {
                if ((r <= 8 && c <= 8) || (r <= 8 && c >= size - 9) || (r >= size - 9 && c <= 8)) { return; }
                for (var dr = -2; dr <= 2; dr++) {
                    for (var dc = -2; dc <= 2; dc++) {
                        var on = Math.max(Math.abs(dr), Math.abs(dc)) !== 1;
                        m[r + dr][c + dc] = on ? 1 : 0;
                        reserved[r + dr][c + dc] = true;
                    }
                }
            });
        });

        // Dark module + format information areas.
        m[size - 8][8] = 1;
        reserved[size - 8][8] = true;

        for (var f = 0; f < 9; f++) {
            if (!reserved[8][f]) { reserved[8][f] = true; }
            if (!reserved[f][8]) { reserved[f][8] = true; }
        }
        for (var g = 0; g < 8; g++) {
            reserved[8][size - 1 - g] = true;
            reserved[size - 1 - g][8] = true;
        }

        // Zig-zag data placement.
        var bitIndex = 0;
        var total = words.length * 8;
        var upward = true;

        for (var col = size - 1; col > 0; col -= 2) {
            if (col === 6) { col--; }

            for (var step = 0; step < size; step++) {
                var row = upward ? size - 1 - step : step;

                for (var offset = 0; offset < 2; offset++) {
                    var cc2 = col - offset;
                    if (reserved[row][cc2]) { continue; }

                    var bit = 0;
                    if (bitIndex < total) {
                        bit = (words[bitIndex >> 3] >> (7 - (bitIndex & 7))) & 1;
                        bitIndex++;
                    }

                    // Mask 0: (row + col) % 2 === 0
                    if ((row + cc2) % 2 === 0) { bit ^= 1; }
                    m[row][cc2] = bit;
                }
            }
            upward = !upward;
        }

        // Format information: EC level M (00) with mask 000 encodes to 0x5412
        // after BCH(15,5) and the mandatory 0x5412 XOR mask.
        //
        // The two copies are placed exactly as ISO/IEC 18004 specifies — the
        // vertical arm carries bits 0-14 down the left of the top-left finder
        // and up from the bottom, the horizontal arm mirrors it. Transposing
        // these two arms produces a code that renders but will not scan.
        var format = 0x5412;

        for (var b = 0; b < 15; b++) {
            var v = (format >> b) & 1;

            // Vertical arm (column 8).
            if (b < 6) { m[b][8] = v; }
            else if (b < 8) { m[b + 1][8] = v; }
            else { m[size - 15 + b][8] = v; }

            // Horizontal arm (row 8).
            if (b < 8) { m[8][size - 1 - b] = v; }
            else if (b === 8) { m[8][7] = v; }
            else { m[8][14 - b] = v; }
        }

        return m;
    }

    function encode(text) {
        var bytes = toBytes(text);
        var version = 0;

        for (var v = 1; v <= 6; v++) {
            var headerBits = 4 + (v < 10 ? 8 : 16);
            if (bytes.length + Math.ceil(headerBits / 8) <= dataCapacity(v)) { version = v; break; }
        }

        if (version === 0) { return null; }

        return place(version, interleave(buildData(bytes, version), version));
    }

    /* -------------------------------------------------------------- svg */
    function svg(text, options) {
        options = options || {};

        var matrix = encode(String(text));
        if (!matrix) { return null; }

        var size = matrix.length;
        var quiet = options.quiet === undefined ? 3 : options.quiet;
        var span = size + quiet * 2;
        var dark = options.dark || '#0b1220';
        var light = options.light || '#ffffff';

        var path = '';
        for (var r = 0; r < size; r++) {
            for (var c = 0; c < size; c++) {
                if (matrix[r][c]) {
                    path += 'M' + (c + quiet) + ' ' + (r + quiet) + 'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + span + ' ' + span + '" '
            + 'shape-rendering="crispEdges" role="img" aria-label="QR code">'
            + '<rect width="' + span + '" height="' + span + '" fill="' + light + '"/>'
            + '<path d="' + path + '" fill="' + dark + '"/></svg>';
    }

    window.LumeraQR = { svg: svg, encode: encode };
})();
