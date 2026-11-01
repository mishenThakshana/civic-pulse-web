"""Spam / abuse detection. The AI service only FLAGS reports - it never deletes them.

Signals combine text features, image features and report metadata. When a
trained SpamModel is loaded its probability is used; otherwise (and only if
ENABLE_HEURISTIC_FALLBACKS=true) a documented rule-based development fallback
is used and the result is labelled ``status="heuristic"``.

Development heuristic (``rule_based_v1``)
------------------------------------------
Each triggered rule i has a weight w_i in (0, 1) and rules are combined with
a noisy-OR:  p = 1 - (1 - PRIOR) * prod_i (1 - w_i).
Noisy-OR keeps p in [0, 1], lets independent weak signals accumulate and makes
every rule's contribution explicit. Weights are hand-set starting points, NOT
learned; they must be replaced by a trained model (or re-tuned on the labelled
spam dataset) before production use. The output is a score, not a calibrated
probability.
"""

from __future__ import annotations

import re
from typing import Any

from app.core.config import Settings
from app.core.logging import get_logger
from app.models.spam_model import SPAM_TEXT_COLUMN, SpamModel
from app.schemas.analysis import ComponentStatus, ImageAnalysis, SpamResult
from app.schemas.report import ReportInput
from app.utils.text import (
    contains_email,
    contains_phone_number,
    contains_url,
    has_long_character_run,
    letter_ratio,
    tokenize,
    unique_token_ratio,
)

logger = get_logger(__name__)

HEURISTIC_METHOD = "rule_based_v1 (development fallback, not a trained model)"
PRIOR = 0.02

# rule name -> weight. Documented here so they can be reviewed and tuned.
RULE_WEIGHTS: dict[str, float] = {
    "description_too_short": 0.35,  # < 10 characters rarely describes an issue
    "mostly_non_letter_characters": 0.35,  # keyboard mashing, emoji/number spam
    "highly_repetitive_text": 0.40,  # same words repeated
    "long_character_run": 0.30,  # "aaaaaaa", "!!!!!!!"
    "contains_url": 0.45,  # links are the strongest promotional signal
    "contains_contact_details": 0.25,  # phone/e-mail typical of adverts (and PII)
    "promotional_keyword": 0.30,  # applied once per distinct keyword, max 3
    "image_blurry": 0.10,  # weak: genuine reports can be blurry
    "image_too_dark": 0.10,
    "outside_service_area": 0.30,  # coordinates outside the configured area
    "null_island_coordinates": 0.50,  # (0, 0) almost always a client bug or fake
}
MAX_PROMO_HITS = 3

# English promotional vocabulary. TODO: extend with Sinhala / Tamil terms
# collected during dataset annotation.
PROMO_KEYWORDS: tuple[str, ...] = (
    "buy now", "discount", "offer", "promo", "sale", "click here", "subscribe", "follow me",
    "whatsapp", "call now", "casino", "lottery", "loan", "earn money", "crypto", "bitcoin",
    "free gift", "win a", "cheap", "best price",
)
_PROMO_RES = [re.compile(rf"(?<!\w){re.escape(k)}(?!\w)", re.IGNORECASE) for k in PROMO_KEYWORDS]


class SpamService:
    def __init__(self, model: SpamModel, settings: Settings) -> None:
        self.model = model
        self.settings = settings

    def extract_features(
        self,
        text: str,
        report: ReportInput,
        image: ImageAnalysis | None,
    ) -> dict[str, Any]:
        s = self.settings
        tokens = tokenize(text)
        quality = image.quality if image else None
        in_area = (
            s.service_area_min_lat <= report.latitude <= s.service_area_max_lat
            and s.service_area_min_lon <= report.longitude <= s.service_area_max_lon
        )
        return {
            SPAM_TEXT_COLUMN: text,
            "text_length": len(text),
            "token_count": len(tokens),
            "unique_token_ratio": round(unique_token_ratio(text), 4),
            "letter_ratio": round(letter_ratio(text), 4),
            "has_url": int(contains_url(text)),
            "has_phone": int(contains_phone_number(text)),
            "has_email": int(contains_email(text)),
            "has_long_char_run": int(has_long_character_run(text)),
            "promo_keyword_hits": sum(1 for r in _PROMO_RES if r.search(text)),
            "image_available": int(quality is not None),
            "image_quality_score": quality.quality_score if quality else 0.0,
            "image_is_blurry": int(bool(quality and quality.is_blurry)),
            "image_is_too_dark": int(bool(quality and quality.is_too_dark)),
            "image_category_confidence": (image.confidence or 0.0) if image else 0.0,
            "outside_service_area": int(not in_area),
            "null_island": int(abs(report.latitude) < 0.01 and abs(report.longitude) < 0.01),
        }

    @staticmethod
    def heuristic_score(features: dict[str, Any]) -> tuple[float, list[str]]:
        triggered: list[str] = []
        if features["text_length"] < 10:
            triggered.append("description_too_short")
        if features["letter_ratio"] < 0.5:
            triggered.append("mostly_non_letter_characters")
        if features["token_count"] >= 6 and features["unique_token_ratio"] < 0.3:
            triggered.append("highly_repetitive_text")
        if features["has_long_char_run"]:
            triggered.append("long_character_run")
        if features["has_url"]:
            triggered.append("contains_url")
        if features["has_phone"] or features["has_email"]:
            triggered.append("contains_contact_details")
        promo_hits = min(int(features["promo_keyword_hits"]), MAX_PROMO_HITS)
        triggered.extend(["promotional_keyword"] * promo_hits)
        if features["image_is_blurry"]:
            triggered.append("image_blurry")
        if features["image_is_too_dark"]:
            triggered.append("image_too_dark")
        if features["null_island"]:
            triggered.append("null_island_coordinates")
        elif features["outside_service_area"]:
            triggered.append("outside_service_area")

        keep = 1.0 - PRIOR
        for rule in triggered:
            keep *= 1.0 - RULE_WEIGHTS[rule]
        reasons = sorted(set(triggered), key=triggered.index)
        return round(1.0 - keep, 4), reasons

    def analyze(
        self,
        text: str,
        report: ReportInput,
        image: ImageAnalysis | None,
    ) -> SpamResult:
        threshold = self.settings.spam_threshold
        features = self.extract_features(text, report, image)

        if self.model.is_ready():
            try:
                probability = max(0.0, min(1.0, self.model.predict(features)))
                return SpamResult(
                    status=ComponentStatus.OK,
                    method=self.model.name,
                    probability=round(probability, 4),
                    is_suspicious=probability >= threshold,
                    threshold=threshold,
                )
            except Exception as exc:  # noqa: BLE001
                logger.warning("Spam model failed, falling back: %s", type(exc).__name__)

        if not self.settings.enable_heuristic_fallbacks:
            return SpamResult(status=ComponentStatus.MODEL_NOT_LOADED, threshold=threshold,
                              message="Spam model not loaded.")

        probability, reasons = self.heuristic_score(features)
        return SpamResult(
            status=ComponentStatus.HEURISTIC,
            method=HEURISTIC_METHOD,
            probability=probability,
            is_suspicious=probability >= threshold,
            threshold=threshold,
            reasons=reasons,
            message="Spam model not loaded; rule-based development fallback used.",
        )
