"""PDF text extractor - port of PHP `PdfTextExtractor`.

Dependency-free: stream scanning, Flate/ASCII85/Hex filters, UTF-16BE
strings, WinAnsi mapping and the geometry-aware text reassembly that joins
per-glyph fragments correctly. Not an OCR engine.
"""

import re
import zlib

_TOKEN_RE = re.compile(
    r"\((?:[^()\\]|\\.)*\)"          # literal string
    r"|<([0-9A-Fa-f\s]{2,})>"        # hex string
    r"|\[([^\]]*)\]"                 # TJ array
    r"|(-?\d+(?:\.\d+)?)"            # number
    r"|(Tm|Td|TD|Tf|T\*|Tj|TJ|BT|ET)",  # operator
    re.S,
)
_ARRAY_ITEM_RE = re.compile(r"\((?:[^()\\]|\\.)*\)|<([0-9A-Fa-f\s]{2,})>|(-?\d+(?:\.\d+)?)", re.S)
_STREAM_RE = re.compile(rb"(?:\r\n|\n|\r)stream(?:\r\n|\n|\r)")

# Minimal WinAnsi (cp1252) -> Unicode mapping for bytes 0x80..0xFF (same as PHP).
_WIN_ANSI = {
    0x80: 0x20AC, 0x82: 0x201A, 0x83: 0x0192, 0x84: 0x201E, 0x85: 0x2026,
    0x86: 0x2020, 0x87: 0x2021, 0x88: 0x02C6, 0x89: 0x2030, 0x8A: 0x0160,
    0x8B: 0x2039, 0x8C: 0x0152, 0x8E: 0x017D, 0x91: 0x2018, 0x92: 0x2019,
    0x93: 0x201C, 0x94: 0x201D, 0x95: 0x2022, 0x96: 0x2013, 0x97: 0x2014,
    0x98: 0x02DC, 0x99: 0x2122, 0x9A: 0x0161, 0x9B: 0x203A, 0x9C: 0x0153,
    0x9E: 0x017E, 0x9F: 0x0178,
}
for _b in range(0xA0, 0x100):
    _WIN_ANSI.setdefault(_b, _b)


class PdfExtractionError(Exception):
    pass


def _to_utf8(data: bytes) -> str:
    out = []
    for b in data:
        if b < 0x80:
            out.append(chr(b))
        elif b in _WIN_ANSI:
            out.append(chr(_WIN_ANSI[b]))
        # unmappable bytes are dropped, not replaced with '?'
    return "".join(out)


def _maybe_utf16(data: bytes) -> bytes:
    if data.startswith(b"\xfe\xff"):
        try:
            return data[2:].decode("utf-16-be").encode("utf-8")
        except UnicodeDecodeError:
            return data
    if len(data) >= 4 and data[0:1] == b"\0" and data[2:3] == b"\0":
        try:
            return data.decode("utf-16-be").encode("utf-8")
        except UnicodeDecodeError:
            return data
    return data


def _decode_literal(s: str) -> str:
    out = []
    i = 0
    n = len(s)
    while i < n:
        ch = s[i]
        if ch == "\\" and i + 1 < n:
            nxt = s[i + 1]
            if nxt in "nrtbf()\\":
                out.append({"n": "\n", "r": "\r", "t": "\t", "b": "\x08", "f": "\x0c",
                            "(": "(", ")": ")", "\\": "\\"}[nxt])
                i += 2
                continue
            m = re.match(r"[0-7]{1,3}", s[i + 1:])
            if m:
                out.append(chr(int(m.group(0), 8) & 0xFF))
                i += 1 + len(m.group(0))
                continue
            out.append(nxt)
            i += 2
        else:
            out.append(ch)
            i += 1
    return _to_utf8(_maybe_utf16("".join(out).encode("latin-1", "ignore")))


def _decode_hex(hexstr: str) -> str:
    hexstr = re.sub(r"\s+", "", hexstr)
    if len(hexstr) % 2 != 0:
        hexstr += "0"
    try:
        raw = bytes.fromhex(hexstr)
    except ValueError:
        return ""
    return _to_utf8(_maybe_utf16(raw))


def _decode_ascii85(data: bytes) -> bytes | None:
    s = re.sub(rb"\s+", b"", data).decode("latin-1")
    if s.endswith("~>"):
        s = s[:-2]
    out = bytearray()
    for i in range(0, len(s), 5):
        grp = s[i:i + 5]
        if grp == "z":
            out += b"\0\0\0\0"
            continue
        n = len(grp)
        if n == 1:
            return None
        grp = grp.ljust(5, "u")
        val = 0
        for ch in grp:
            c = ord(ch) - 33
            if c < 0 or c > 85:
                return None
            val = val * 85 + c
        out += val.to_bytes(4, "big")[: n - 1]
    return bytes(out)


def _decode_ascii_hex(data: bytes) -> bytes | None:
    s = data.decode("latin-1")
    pos = s.find(">")
    if pos != -1:
        s = s[:pos]
    hexstr = re.sub(r"[^0-9A-Fa-f]", "", s)
    if not hexstr:
        return None
    if len(hexstr) % 2 != 0:
        hexstr += "0"
    return bytes.fromhex(hexstr)


def _flate(data: bytes) -> bytes | None:
    try:
        return zlib.decompress(data)
    except zlib.error:
        pass
    try:
        return zlib.decompress(data, -15)
    except zlib.error:
        return None


class PdfTextExtractor:
    def extract(self, pdf_bytes: bytes) -> str:
        if not pdf_bytes or b"%PDF" not in pdf_bytes[:1024]:
            raise PdfExtractionError("The uploaded file is not a valid PDF.")

        streams = self._collect_streams(pdf_bytes)

        text_parts: list[str] = []
        for s in streams:
            if self._looks_like_content(s):
                text_parts.append(self._extract_content_text(s))

        if not "".join(text_parts).strip():
            for s in streams:
                if self._printable_ratio(s) >= 0.6:
                    text_parts.append(self._extract_content_text(s))

        text = re.sub(r"\s+", " ", " ".join(text_parts).strip())
        text = text.strip()
        if text == "":
            raise PdfExtractionError("No extractable text was found in this PDF.")
        return text[:60000]

    # ------------------------------------------------------------------

    def _collect_streams(self, raw: bytes) -> list[str]:
        decoded: list[str] = []
        for m in _STREAM_RE.finditer(raw):
            start = m.end()
            end = raw.find(b"endstream", start)
            if end == -1:
                continue
            data = raw[start:end]
            data = re.sub(rb"(?:\r\n|\n|\r)$", b"", data)

            window = raw[max(0, m.start() - 600):m.start()].decode("latin-1")
            if b"/ASCII85Decode" in window.encode("latin-1") or "/ASCII85Decode" in window:
                d = _decode_ascii85(data)
                if d is not None:
                    data = d
            if "/ASCIIHexDecode" in window:
                d = _decode_ascii_hex(data)
                if d is not None:
                    data = d
            if "/FlateDecode" in window:
                d = _flate(data)
                if d is not None:
                    data = d
            decoded.append(data.decode("latin-1"))
        return decoded

    @staticmethod
    def _printable_ratio(s: str) -> float:
        if not s:
            return 0.0
        sample = s[:4096]
        printable = len(re.findall(r"[\x09\x0A\x0D\x20-\x7E\xA0-\xFF]", sample))
        return printable / len(sample)

    def _looks_like_content(self, s: str) -> bool:
        if not s:
            return False
        if self._printable_ratio(s) < 0.85:
            return False
        return bool(re.search(r"(?:Tj|TJ|\bBT\b)", s))

    def _extract_content_text(self, content: str) -> str:
        out: list[str] = []
        state = {"x": 0.0, "y": 0.0, "fs": 12.0, "leading": 0.0,
                 "has_pos": False, "has_show": False,
                 "last_x": None, "last_y": None}

        def show(s: str) -> None:
            if s == "":
                return
            if state["has_show"] and state["last_y"] is not None:
                if abs(state["y"] - state["last_y"]) > 2:
                    while out and out[-1] == " ":
                        out.pop()
                    out.append("\n")
                elif (
                    (state["x"] - state["last_x"]) >= 1.1 * state["fs"]
                    and not (out and out[-1].endswith((" ", "\n")))
                    and not s.startswith(" ")
                ):
                    out.append(" ")
            out.append(s)
            state["has_show"] = True
            state["last_x"] = state["x"]
            state["last_y"] = state["y"]

        stack: list = []
        for m in _TOKEN_RE.finditer(content):
            tok = m.group(0)
            if tok.startswith("("):
                stack.append(("str", _decode_literal(tok[1:-1])))
                continue
            if tok.startswith("<"):
                stack.append(("str", _decode_hex(tok[1:-1])))
                continue
            if tok.startswith("["):
                frag = []
                for am in _ARRAY_ITEM_RE.finditer(tok):
                    a = am.group(0)
                    if a.startswith("("):
                        frag.append(_decode_literal(a[1:-1]))
                    elif a.startswith("<"):
                        frag.append(_decode_hex(a[1:-1]))
                    elif float(a) <= -100:
                        frag.append(" ")
                stack.append(("str", "".join(frag)))
                continue
            if m.group(3) is not None:
                stack.append(float(m.group(3)))
                continue
            # operator
            if tok == "Tf":
                if len(stack) >= 2 and isinstance(stack[-1], float):
                    fs = stack[-1]
                    state["fs"] = fs if fs > 0 else 12.0
                stack = []
            elif tok == "Tm":
                if len(stack) >= 6:
                    state["x"] = stack[4]
                    state["y"] = stack[5]
                    state["has_pos"] = True
                stack = []
            elif tok in ("Td", "TD"):
                if len(stack) >= 2:
                    state["x"] += stack[-2]
                    state["y"] += stack[-1]
                    if tok == "TD":
                        state["leading"] = -stack[-1]
                    state["has_pos"] = True
                stack = []
            elif tok == "T*":
                state["y"] -= state["leading"] if state["leading"] > 0 else state["fs"]
                state["has_pos"] = True
            elif tok in ("Tj", "'", '"'):
                if tok != "Tj":
                    state["y"] -= state["leading"] if state["leading"] > 0 else state["fs"]
                for item in stack:
                    if isinstance(item, tuple):
                        show(item[1])
                stack = []
            elif tok == "TJ":
                for item in stack:
                    if isinstance(item, tuple):
                        show(item[1])
                stack = []
            elif tok in ("BT", "ET"):
                stack = []
            else:
                stack = []

        if not state["has_pos"] and not state["has_show"]:
            return self._extract_naive(content)
        return "".join(out)

    @staticmethod
    def _extract_naive(content: str) -> str:
        parts = []
        for m in re.finditer(r"\((?:[^()\\]|\\.)*\)", content, re.S):
            parts.append(_decode_literal(m.group(0)[1:-1]))
        for m in re.finditer(r"<([0-9A-Fa-f\s]{2,})>", content):
            parts.append(_decode_hex(m.group(1)))
        return " ".join(parts)
