"""Study-material cleaner - port of PHP `StudyMaterialCleaner`.

Context-aware cleaning of extracted PDF/pasted text before it reaches the AI:
removes corrupted characters, symbol runs and garbage tokens while preserving
words, sentences, formulas, code and technical terms. Also provides the
readability gate and the AI-output quality helpers.
"""

import re

MIN_MEANINGFUL_WORDS = 40
MIN_WORD_RATIO = 0.5

_LET = r"[^\W\d_]"  # unicode letter
_WORD = (
    r"^(?:[^\W\d_]|\d)+(?:['’`.\-–—:°±](?:[^\W\d_]|\d)+)*[.,;:!?%°)\]}\"']{0,3}$"
)
_FORMULA = (
    r"^(?:[^\W\d_]|\d|[_$#(])"
    r"(?:[^\W\d_]|\d|[_$#=.+\-*\/^%<>!&|@(),\[\]{}°±×÷;:])*$"
)


def _alnum_count(token: str) -> int:
    return len(re.findall(r"[^\W\d_]", token)) + len(re.findall(r"\d", token))


def _keep_token(token: str) -> bool:
    if re.match(_WORD, token):
        return True
    if re.match(r"^[^\W\d_]$|^\d$", token):
        return True
    if re.match(r"^[,.;:!?()\-–—%&+=*\/<>\"'‘’“”]{1,2}$", token):
        return True
    if re.match(_FORMULA, token):
        alnum = _alnum_count(token)
        if alnum >= 2 and (len(token) - alnum) / len(token) <= 0.5:
            return True
    return False


def _keep_line(line: str) -> bool:
    line = line.strip()
    if line == "":
        return False
    stats = _stats(line)
    if stats["words"] < 2:
        return False
    length = len(line)
    alnum = _alnum_count(line)
    if length > 0 and (length - alnum) / length > 0.4:
        return False
    return True


def _stats(text: str) -> dict:
    tokens = [t for t in re.split(r"\s+", text.strip()) if t]
    total = len(tokens)
    if total == 0:
        return {"words": 0, "ratio": 0.0}
    words = sum(1 for t in tokens if re.search(r"[^\W\d_]{2}", t))
    return {"words": words, "ratio": words / total}


class StudyMaterialCleaner:
    def clean(self, text: str) -> str:
        # 1. Control chars / BOM / replacement chars.
        text = re.sub(r"[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\uFEFF|\uFFFD", " ", text)
        # 2. Runs of 3+ symbols (not letters/digits; includes underscores).
        text = re.sub(r"(?:[^\s\w]|_){3,}", " ", text)
        # 3. Question-mark artifacts from lossy decoding.
        text = re.sub(r"\?{2,}", " ", text)
        # 4. Token-level garbage filtering.
        text = re.sub(r"\S+", lambda m: m.group(0) if _keep_token(m.group(0)) else " ", text)
        # 5. Line-level filtering (only when lines exist).
        if "\n" in text:
            text = "\n".join(
                line if _keep_line(line) else "" for line in text.split("\n")
            )
        # 6. Normalise whitespace.
        text = re.sub(r"[ \t]+", " ", text)
        text = re.sub(r" ?\n ?", "\n", text)
        text = re.sub(r"\n{3,}", "\n\n", text)
        text = re.sub(r" {2,}", " ", text)
        return text.strip()

    def is_meaningful(self, cleaned: str) -> bool:
        stats = _stats(cleaned)
        return (
            stats["words"] >= MIN_MEANINGFUL_WORDS
            and stats["ratio"] >= MIN_WORD_RATIO
        )

    @staticmethod
    def is_clean_text(s: str) -> bool:
        s = s.strip()
        if s == "":
            return False
        if re.search(r"[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\uFFFD", s):
            return False
        if re.search(r"(?:[^\s\w]|_){3,}", s):
            return False
        length = len(s)
        alnum = _alnum_count(s)
        if length == 0 or alnum < 2:
            return False
        if (length - alnum) / length > 0.35:
            return False
        return True

    @staticmethod
    def is_pattern_question(question: str) -> bool:
        q = question
        if re.search(
            r"\b(letter|character|symbol|glyph|sequence|pattern|bracket|punctuation)\b.{0,60}\b(appears?|occurs?|found|repeats?|follows?|present|contains?)\b",
            q,
            re.I,
        ):
            return True
        if re.search(
            r"\b(appears?|found|occurs?|repeats?)\b.{0,60}\b(throughout|in)\b.{0,40}\b(text|document|material|passage|page)\b",
            q,
            re.I,
        ) and re.search(r"\b(letter|character|symbol|sequence|pattern|bracket|word\s+pattern)\b", q, re.I):
            return True
        return False
