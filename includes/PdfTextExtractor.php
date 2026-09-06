<?php
/**
 * QuizSphere - Standalone PDF text extractor
 * -------------------------------------------------------------
 * A dependency-free extractor for ordinary, text-based PDFs. It parses the
 * PDF object streams, applies the stream filters (FlateDecode, ASCII85Decode,
 * ASCIIHexDecode), and pulls out the visible text-showing strings (Tj / TJ
 * operators) so the result can be fed to the AI quiz generator.
 *
 * Robustness strategy: a strict pass only accepts streams that look like
 * page content (printable + text operators). If that yields nothing, a
 * relaxed pass scans every mostly-printable stream — any residual garbage is
 * removed later by StudyMaterialCleaner, so loosening extraction is safe.
 *
 * It is intentionally NOT an OCR engine and will not recover text from
 * scanned/image-only documents. If extraction fails or returns nothing, the
 * caller should surface: "This PDF could not be processed. Please upload a
 * text-based PDF."
 */

declare(strict_types=1);

class PdfExtractionException extends RuntimeException
{
}

class PdfTextExtractor
{
    /**
     * Extract the text content of a PDF file.
     *
     * @throws PdfExtractionException when the file cannot be parsed or yields
     *                                no usable text.
     */
    public function extract(string $pdfPath): string
    {
        $raw = @file_get_contents($pdfPath);
        if ($raw === false || $raw === '') {
            throw new PdfExtractionException('Could not read the uploaded file.');
        }

        if (stripos(substr($raw, 0, 1024), '%PDF') === false) {
            throw new PdfExtractionException('The uploaded file is not a valid PDF.');
        }

        $streams = $this->collectStreams($raw);

        // Strict pass: only genuine page content streams.
        $text = '';
        foreach ($streams as $s) {
            if ($this->looksLikeContentStream($s['data'])) {
                $text .= $this->extractContentText($s['data']) . "\n";
            }
        }

        // Relaxed fallback: if the strict pass found nothing, scan every
        // mostly-printable stream. Downstream cleaning removes any noise.
        if (trim($text) === '') {
            foreach ($streams as $s) {
                if ($this->printableRatio($s['data']) >= 0.6) {
                    $text .= $this->extractContentText($s['data']) . "\n";
                }
            }
        }

        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        $text = trim((string) $text);

        if ($text === '') {
            throw new PdfExtractionException('No extractable text was found in this PDF.');
        }

        return mb_substr($text, 0, 60000);
    }

    /* ------------------------------------------------------------------
       Stream collection & filters
       ------------------------------------------------------------------ */

    /**
     * Locate every stream body in the file, applying the filters declared in
     * the preceding object dictionary.
     *
     * @return array<int, array{data: string}>
     */
    private function collectStreams(string $raw): array
    {
        $streams = [];
        if (!preg_match_all('/(?:\r\n|\n|\r)stream(?:\r\n|\n|\r)/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            return $streams;
        }

        foreach ($m[0] as $match) {
            $markerEnd = $match[1] + strlen($match[0]);
            $end = strpos($raw, 'endstream', $markerEnd);
            if ($end === false) {
                continue;
            }
            $data = substr($raw, $markerEnd, $end - $markerEnd);
            $data = (string) preg_replace('/(?:\r\n|\n|\r)$/', '', $data);

            $dictWindow = substr($raw, max(0, $match[1] - 600), 600);
            $streams[] = ['data' => $this->applyFilters($data, $dictWindow)];
        }

        return $streams;
    }

    /** Apply the filters named in the object dictionary, in order. */
    private function applyFilters(string $data, string $dictWindow): string
    {
        // Filter pipelines appear in decoding order, e.g.
        //   /Filter [/ASCII85Decode /FlateDecode]
        if (preg_match('/\/ASCII85Decode/', $dictWindow)) {
            $decoded = $this->decodeAscii85($data);
            if ($decoded !== false) {
                $data = $decoded;
            }
        }
        if (preg_match('/\/ASCIIHexDecode/', $dictWindow)) {
            $decoded = $this->decodeAsciiHex($data);
            if ($decoded !== false) {
                $data = $decoded;
            }
        }
        if (preg_match('/\/FlateDecode/', $dictWindow)) {
            $decoded = @zlib_decode($data);
            if ($decoded === false) {
                $decoded = @gzuncompress($data);
            }
            if ($decoded === false) {
                $decoded = @gzinflate($data);
            }
            if ($decoded !== false) {
                $data = $decoded;
            }
        }
        return $data;
    }

    /** Decode an ASCII85 (base-85) encoded payload. */
    private function decodeAscii85(string $s): string|false
    {
        $s = preg_replace('/\s+/', '', $s) ?? '';
        if (str_ends_with($s, '~>')) {
            $s = substr($s, 0, -2);
        }
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i += 5) {
            $grp = substr($s, $i, 5);
            if ($grp === 'z') {
                $out .= "\0\0\0\0";
                continue;
            }
            $n = strlen($grp);
            if ($n === 1) {
                return false; // malformed
            }
            if ($n < 5) {
                $grp = str_pad($grp, 5, 'u');
            }
            $val = 0;
            for ($j = 0; $j < 5; $j++) {
                $c = ord($grp[$j]) - 33;
                if ($c < 0 || $c > 85) {
                    return false;
                }
                $val = $val * 85 + $c;
            }
            $out .= substr(pack('N', $val), 0, $n - 1);
        }
        return $out;
    }

    /** Decode an ASCIIHex encoded payload. */
    private function decodeAsciiHex(string $s): string|false
    {
        $pos = strpos($s, '>');
        if ($pos !== false) {
            $s = substr($s, 0, $pos);
        }
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $s) ?? '';
        if ($hex === '') {
            return false;
        }
        if (strlen($hex) % 2 !== 0) {
            $hex .= '0';
        }
        return (string) hex2bin($hex);
    }

    /* ------------------------------------------------------------------
       Content detection
       ------------------------------------------------------------------ */

    /** Fraction of printable ASCII bytes in a sample of the stream. */
    private function printableRatio(string $data): float
    {
        if ($data === '') {
            return 0.0;
        }
        $sample = substr($data, 0, 4096);
        $len = strlen($sample);
        if ($len === 0) {
            return 0.0;
        }
        $printable = preg_match_all('/[\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/', $sample);
        return $printable / $len;
    }

    /**
     * Heuristic: a page content stream is (nearly) printable and uses text
     * operators (Tj / TJ / BT..ET). Binary image or font streams fail both.
     */
    private function looksLikeContentStream(string $decoded): bool
    {
        if ($decoded === '') {
            return false;
        }
        if ($this->printableRatio($decoded) < 0.85) {
            return false;
        }
        return (bool) preg_match('/(?:Tj|TJ|\bBT\b)/', $decoded);
    }

    /* ------------------------------------------------------------------
       Text extraction
       ------------------------------------------------------------------ */

    /**
     * Pull readable text out of a (decompressed) content stream.
     *
     * Many producers (design tools, slide exports) emit every word fragment
     * as its own positioned text block and draw spaces as explicit "( )"
     * fragments. Joining strings with spaces therefore destroys the words
     * ("G eo g r ap h y"). Instead we replay the text-positioning operators
     * (Tm, Td, TD, T-star, Tf) and concatenate fragments geometrically: same
     * line = direct concatenation, big X gap = space, Y change = new line.
     */
    private function extractContentText(string $content): string
    {
        $tokens = [];
        if (!preg_match_all(
            '/\((?:[^()\\\\]|\\\\.)*\)|<([0-9A-Fa-f\s]{2,})>|\[([^\]]*)\]|(-?\d+(?:\.\d+)?)|(Tm|Td|TD|Tf|T\*|Tj|TJ|BT|ET)/s',
            $content,
            $tm,
            PREG_SET_ORDER
        )) {
            return '';
        }

        $out = '';
        $stack = [];
        $x = 0.0; $y = 0.0; $fs = 12.0; $leading = 0.0;
        $hasPos = false; $hasShow = false;
        $lastX = null; $lastY = null;

        $show = function (string $s) use (&$out, &$hasShow, &$lastX, &$lastY, &$x, &$y, &$fs): void {
            if ($s === '') {
                return;
            }
            if ($hasShow && $lastY !== null) {
                if (abs($y - $lastY) > 2) {
                    $out = rtrim($out) . "\n";
                } elseif (($x - $lastX) >= 1.1 * $fs
                    && !preg_match('/\s$/', $out)
                    && !preg_match('/^\s/', $s)) {
                    $out .= ' ';
                }
            }
            $out .= $s;
            $hasShow = true;
            $lastX = $x;
            $lastY = $y;
        };

        foreach ($tm as $t) {
            $tok = $t[0];
            if ($tok === '' ) {
                continue;
            }
            if ($tok[0] === '(') { // literal string operand (used by Tj)
                $stack[] = ['str' => $this->decodeLiteral(substr($tok, 1, -1))];
                continue;
            }
            if ($tok[0] === '<') { // hex string operand
                $stack[] = ['str' => $this->decodeHex(substr($tok, 1, -1))];
                continue;
            }
            if ($tok[0] === '[') { // TJ array
                $frag = '';
                if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)|<([0-9A-Fa-f\s]{2,})>|(-?\d+(?:\.\d+)?)/s', $tok, $am, PREG_SET_ORDER)) {
                    foreach ($am as $a) {
                        if ($a[0][0] === '(') {
                            $frag .= $this->decodeLiteral(substr($a[0], 1, -1));
                        } elseif ($a[0][0] === '<') {
                            $frag .= $this->decodeHex(substr($a[0], 1, -1));
                        } elseif ((float) $a[0] <= -100) {
                            $frag .= ' '; // large negative kerning = word gap
                        }
                    }
                }
                $stack[] = ['str' => $frag];
                continue;
            }
            if (isset($t[3]) && $t[3] !== '') { // number
                $stack[] = (float) $t[3];
                continue;
            }
            // operator
            switch ($tok) {
                case 'Tf':
                    if (count($stack) >= 2) {
                        $fs = (float) $stack[count($stack) - 1];
                        if ($fs <= 0) { $fs = 12.0; }
                    }
                    $stack = [];
                    break;
                case 'Tm':
                    if (count($stack) >= 6) {
                        $x = (float) $stack[4];
                        $y = (float) $stack[5];
                        $hasPos = true;
                    }
                    $stack = [];
                    break;
                case 'Td':
                case 'TD':
                    if (count($stack) >= 2) {
                        $x += (float) $stack[count($stack) - 2];
                        $y += (float) $stack[count($stack) - 1];
                        if ($tok === 'TD') {
                            $leading = -((float) $stack[count($stack) - 1]);
                        }
                        $hasPos = true;
                    }
                    $stack = [];
                    break;
                case 'T*':
                    $y -= $leading > 0 ? $leading : $fs;
                    $hasPos = true;
                    break;
                case 'Tj':
                case "'":
                case '"':
                    if ($tok !== 'Tj') {
                        $y -= $leading > 0 ? $leading : $fs;
                    }
                    foreach ($stack as $s) {
                        if (is_array($s)) {
                            $show($s['str']);
                        }
                    }
                    $stack = [];
                    break;
                case 'TJ':
                    foreach ($stack as $s) {
                        if (is_array($s)) {
                            $show($s['str']);
                        }
                    }
                    $stack = [];
                    break;
                case 'BT':
                case 'ET':
                    $stack = [];
                    break;
                default:
                    $stack = [];
            }
        }

        // Fallback for streams without positioning operators: the legacy
        // space-joined extraction.
        if (!$hasPos && !$hasShow) {
            return $this->extractContentTextNaive($content);
        }

        return $out;
    }

    /** Legacy extraction: join every string operand with a space. */
    private function extractContentTextNaive(string $content): string
    {
        $parts = [];
        $positions = [];

        if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/s', $content, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $match) {
                $parts[] = $this->decodeLiteral(substr($match[0], 1, -1));
                $positions[] = (int) $match[1];
            }
        }
        if (preg_match_all('/<([0-9A-Fa-f\s]{2,})>/', $content, $hm, PREG_OFFSET_CAPTURE)) {
            foreach ($hm[1] as $i => $h) {
                $positions[] = (int) $hm[0][$i][1];
                $parts[] = $this->decodeHex($h[0]);
            }
        }
        if ($parts === []) {
            return '';
        }
        array_multisort($positions, $parts);
        return implode(' ', $parts);
    }

    /** Decode a PDF literal string's escape sequences. */
    private function decodeLiteral(string $s): string
    {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $nxt = $s[$i + 1];
                switch ($nxt) {
                    case 'n':  $out .= "\n"; $i++; break;
                    case 'r':  $out .= "\r"; $i++; break;
                    case 't':  $out .= "\t"; $i++; break;
                    case 'b':  $out .= "\x08"; $i++; break;
                    case 'f':  $out .= "\x0c"; $i++; break;
                    case '(':  $out .= '('; $i++; break;
                    case ')':  $out .= ')'; $i++; break;
                    case '\\': $out .= '\\'; $i++; break;
                    default:
                        // Octal escape \ddd
                        if (preg_match('/^[0-7]{1,3}/', substr($s, $i + 1), $om)) {
                            $out .= chr(octdec($om[0]));
                            $i += strlen($om[0]);
                        } else {
                            $out .= $nxt;
                            $i++;
                        }
                }
            } else {
                $out .= $ch;
            }
        }
        return $this->toUtf8($this->maybeUtf16($out));
    }

    /** Decode a PDF hex string <...> to its byte sequence. */
    private function decodeHex(string $hex): string
    {
        $hex = preg_replace('/\s+/', '', $hex) ?? '';
        if (strlen($hex) % 2 !== 0) {
            $hex .= '0';
        }
        $bytes = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $bytes .= chr((int) hexdec(substr($hex, $i, 2)));
        }
        return $this->toUtf8($this->maybeUtf16($bytes));
    }

    /** Convert UTF-16BE encoded strings (common in Unicode PDFs) to UTF-8. */
    private function maybeUtf16(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFE\xFF")) {
            $converted = @mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
            if ($converted !== false && $converted !== '') {
                return $converted;
            }
        }
        // Heuristic: leading NUL bytes in even positions = UTF-16BE text.
        if (strlen($bytes) >= 4 && $bytes[0] === "\0" && $bytes[2] === "\0") {
            $converted = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
            if ($converted !== false && $converted !== '') {
                return $converted;
            }
        }
        return $bytes;
    }

    /**
     * Convert PDF byte text to UTF-8. PDF default encoding is mostly ASCII;
     * high bytes are WinAnsi-encoded, so map them to their Unicode code points.
     */
    private function toUtf8(string $bytes): string
    {
        $out = '';
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($bytes[$i]);
            if ($o < 0x80) {
                $out .= $bytes[$i];
            } elseif (isset(self::WIN_ANSI[$o])) {
                $out .= mb_chr(self::WIN_ANSI[$o], 'UTF-8');
            }
            // Unmappable bytes are dropped, not replaced with '?'.
        }
        return $out;
    }

    /** Minimal WinAnsi (cp1252) -> Unicode mapping for bytes 0x80..0xFF. */
    private const WIN_ANSI = [
        0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E,
        0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6,
        0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152,
        0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C,
        0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
        0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
        0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
        0xA0 => 0x00A0, 0xA1 => 0x00A1, 0xA2 => 0x00A2, 0xA3 => 0x00A3,
        0xA4 => 0x00A4, 0xA5 => 0x00A5, 0xA6 => 0x00A6, 0xA7 => 0x00A7,
        0xA8 => 0x00A8, 0xA9 => 0x00A9, 0xAA => 0x00AA, 0xAB => 0x00AB,
        0xAC => 0x00AC, 0xAD => 0x00AD, 0xAE => 0x00AE, 0xAF => 0x00AF,
        0xB0 => 0x00B0, 0xB1 => 0x00B1, 0xB2 => 0x00B2, 0xB3 => 0x00B3,
        0xB4 => 0x00B4, 0xB5 => 0x00B5, 0xB6 => 0x00B6, 0xB7 => 0x00B7,
        0xB8 => 0x00B8, 0xB9 => 0x00B9, 0xBA => 0x00BA, 0xBB => 0x00BB,
        0xBC => 0x00BC, 0xBD => 0x00BD, 0xBE => 0x00BE, 0xBF => 0x00BF,
        0xC0 => 0x00C0, 0xC1 => 0x00C1, 0xC2 => 0x00C2, 0xC3 => 0x00C3,
        0xC4 => 0x00C4, 0xC5 => 0x00C5, 0xC6 => 0x00C6, 0xC7 => 0x00C7,
        0xC8 => 0x00C8, 0xC9 => 0x00C9, 0xCA => 0x00CA, 0xCB => 0x00CB,
        0xCC => 0x00CC, 0xCD => 0x00CD, 0xCE => 0x00CE, 0xCF => 0x00CF,
        0xD0 => 0x00D0, 0xD1 => 0x00D1, 0xD2 => 0x00D2, 0xD3 => 0x00D3,
        0xD4 => 0x00D4, 0xD5 => 0x00D5, 0xD6 => 0x00D6, 0xD7 => 0x00D7,
        0xD8 => 0x00D8, 0xD9 => 0x00D9, 0xDA => 0x00DA, 0xDB => 0x00DB,
        0xDC => 0x00DC, 0xDD => 0x00DD, 0xDE => 0x00DE, 0xDF => 0x00DF,
        0xE0 => 0x00E0, 0xE1 => 0x00E1, 0xE2 => 0x00E2, 0xE3 => 0x00E3,
        0xE4 => 0x00E4, 0xE5 => 0x00E5, 0xE6 => 0x00E6, 0xE7 => 0x00E7,
        0xE8 => 0x00E8, 0xE9 => 0x00E9, 0xEA => 0x00EA, 0xEB => 0x00EB,
        0xEC => 0x00EC, 0xED => 0x00ED, 0xEE => 0x00EE, 0xEF => 0x00EF,
        0xF0 => 0x00F0, 0xF1 => 0x00F1, 0xF2 => 0x00F2, 0xF3 => 0x00F3,
        0xF4 => 0x00F4, 0xF5 => 0x00F5, 0xF6 => 0x00F6, 0xF7 => 0x00F7,
        0xF8 => 0x00F8, 0xF9 => 0x00F9, 0xFA => 0x00FA, 0xFB => 0x00FB,
        0xFC => 0x00FC, 0xFD => 0x00FD, 0xFE => 0x00FE, 0xFF => 0x00FF,
    ];
}
