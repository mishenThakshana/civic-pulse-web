"""Multilingual (English / Sinhala / Tamil) text helpers.

Normalisation is deliberately conservative so it is safe for Sinhala and
Tamil: text is NFC-normalised, but ZERO WIDTH JOINER (U+200D) is preserved
because Sinhala uses it to form conjuncts (e.g. "ශ්‍රී").
"""

from __future__ import annotations

import re
import unicodedata

SINHALA_RANGE = (0x0D80, 0x0DFF)
TAMIL_RANGE = (0x0B80, 0x0BFF)

_ZWJ = "‍"
# Invisible characters removed during normalisation (ZWJ intentionally excluded).
_INVISIBLE = {"​", "‌", "⁠", "﻿", "­"}
_WHITESPACE_RE = re.compile(r"\s+")
_URL_RE = re.compile(r"(https?://|www\.)\S+", re.IGNORECASE)
_PHONE_RE = re.compile(r"(?<!\d)(?:\+?94|0)\s?\d{2}[\s-]?\d{3}[\s-]?\d{4}(?!\d)")
_EMAIL_RE = re.compile(r"[\w.+-]+@[\w-]+\.[\w.-]+")
_REPEAT_CHAR_RE = re.compile(r"(.)\1{5,}")


def normalize_text(text: str) -> str:
    """NFC-normalise, strip control/invisible characters and collapse whitespace."""
    text = unicodedata.normalize("NFC", text)
    cleaned = []
    for ch in text:
        if ch == _ZWJ:
            cleaned.append(ch)
        elif ch in _INVISIBLE:
            continue
        elif unicodedata.category(ch) in ("Cc", "Cf") and ch not in "\n\t\r":
            continue
        else:
            cleaned.append(ch)
    return _WHITESPACE_RE.sub(" ", "".join(cleaned)).strip()


def _in_range(ch: str, bounds: tuple[int, int]) -> bool:
    return bounds[0] <= ord(ch) <= bounds[1]


def script_ratios(text: str) -> dict[str, float]:
    """Share of letters written in Latin, Sinhala and Tamil scripts."""
    counts = {"latin": 0, "sinhala": 0, "tamil": 0, "other": 0}
    letters = 0
    for ch in text:
        if not (ch.isalpha() or unicodedata.category(ch).startswith("M")):
            continue
        letters += 1
        if _in_range(ch, SINHALA_RANGE):
            counts["sinhala"] += 1
        elif _in_range(ch, TAMIL_RANGE):
            counts["tamil"] += 1
        elif ch.isascii():
            counts["latin"] += 1
        else:
            counts["other"] += 1
    if letters == 0:
        return {k: 0.0 for k in counts}
    return {k: v / letters for k, v in counts.items()}


def detect_language(text: str) -> str:
    """Script-based language guess: ``en``, ``si``, ``ta``, ``mixed`` or ``unknown``.

    Romanised Sinhala/Tamil ("Singlish"/"Tanglish") is detected as ``en``; a
    trained language-id model can replace this later.
    """
    ratios = script_ratios(text)
    dominant = max(ratios, key=ratios.get)  # type: ignore[arg-type]
    if ratios[dominant] == 0.0:
        return "unknown"
    if ratios[dominant] < 0.7:
        return "mixed"
    return {"latin": "en", "sinhala": "si", "tamil": "ta"}.get(dominant, "unknown")


def contains_url(text: str) -> bool:
    return bool(_URL_RE.search(text))


def contains_phone_number(text: str) -> bool:
    return bool(_PHONE_RE.search(text))


def contains_email(text: str) -> bool:
    return bool(_EMAIL_RE.search(text))


def has_long_character_run(text: str) -> bool:
    return bool(_REPEAT_CHAR_RE.search(text))


def tokenize(text: str) -> list[str]:
    """Unicode-aware word tokenisation (keeps Sinhala/Tamil combining marks)."""
    return re.findall(r"[\w඀-෿஀-௿‍]+", text.lower())


def unique_token_ratio(text: str) -> float:
    tokens = tokenize(text)
    if not tokens:
        return 0.0
    return len(set(tokens)) / len(tokens)


def letter_ratio(text: str) -> float:
    """Share of non-space characters that are letters or combining marks."""
    chars = [c for c in text if not c.isspace()]
    if not chars:
        return 0.0
    letters = sum(1 for c in chars if c.isalpha() or unicodedata.category(c).startswith("M"))
    return letters / len(chars)
