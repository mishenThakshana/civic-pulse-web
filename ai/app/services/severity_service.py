"""Severity estimation: low / medium / high.

Uses a trained severity TextModel (SEVERITY_MODEL_NAME) when available. A
future multimodal severity model (image + text + category) can replace it by
implementing the same ``SeverityService.analyze`` contract.

Development heuristic (``keyword_rules_v1``) - English only
----------------------------------------------------------
* ``high``   if the text mentions an immediate safety risk (HIGH_TERMS).
* ``medium`` if it mentions scale/blockage (MEDIUM_TERMS) or the predicted
             category is one that typically disrupts access (MEDIUM_CATEGORIES).
* ``low``    otherwise.
Confidence is ``null`` because keyword rules produce no meaningful confidence.
For Sinhala/Tamil text the heuristic declines (model_not_loaded) rather than
guess.
"""

from __future__ import annotations

import re

from app.core.config import Settings
from app.core.logging import get_logger
from app.models.text_model import TextModel
from app.schemas.analysis import ComponentStatus, SeverityResult
from app.schemas.report import IssueCategory, SeverityLevel

logger = get_logger(__name__)

HEURISTIC_METHOD = "keyword_rules_v1 (development fallback, English only, not a trained model)"

HIGH_TERMS = (
    "accident", "danger", "dangerous", "injur", "hurt", "collaps", "live wire", "electrocut", "exposed wire",
    "fire", "emergency", "urgent", "life threatening", "sinkhole", "deep hole", "fallen on", "blocking the road",
    "road blocked", "flooded house", "sewage overflow",
)
MEDIUM_TERMS = (
    "large", "big", "huge", "overflow", "blocked", "broken", "damaged", "leak", "stagnant", "smell",
    "mosquito", "traffic", "several days", "weeks",
)
MEDIUM_CATEGORIES = {
    IssueCategory.FLOODING.value,
    IssueCategory.FALLEN_TREE.value,
    IssueCategory.BLOCKED_DRAIN.value,
}


def _matches(text: str, terms: tuple[str, ...]) -> list[str]:
    return [t for t in terms if re.search(rf"(?<!\w){re.escape(t)}", text, re.IGNORECASE)]


class SeverityService:
    def __init__(self, model: TextModel, settings: Settings) -> None:
        self.model = model
        self.settings = settings

    def analyze(self, text: str, language: str | None, category: str | None) -> SeverityResult:
        if self.model.is_ready():
            try:
                prediction = self.model.predict(text)
                if prediction.label not in SeverityLevel.values():
                    raise ValueError(f"model returned unknown severity label '{prediction.label}'")
                return SeverityResult(
                    status=ComponentStatus.OK,
                    method=self.model.name,
                    label=prediction.label,
                    confidence=round(max(0.0, min(1.0, prediction.confidence)), 4),
                )
            except Exception as exc:  # noqa: BLE001
                logger.warning("Severity model failed: %s", exc)
                return SeverityResult(status=ComponentStatus.ERROR, message=f"Severity model failed: {exc}")

        if not self.settings.enable_heuristic_fallbacks:
            return SeverityResult(status=ComponentStatus.MODEL_NOT_LOADED, message="Severity model not loaded.")
        if language != "en":
            return SeverityResult(
                status=ComponentStatus.MODEL_NOT_LOADED,
                message="Severity model not loaded and the development heuristic only supports English.",
            )

        high = _matches(text, HIGH_TERMS)
        medium = _matches(text, MEDIUM_TERMS)
        if high:
            label, why = SeverityLevel.HIGH, f"safety terms: {', '.join(high[:3])}"
        elif medium or category in MEDIUM_CATEGORIES:
            label, why = SeverityLevel.MEDIUM, (f"terms: {', '.join(medium[:3])}" if medium else f"category: {category}")
        else:
            label, why = SeverityLevel.LOW, "no severity terms found"
        return SeverityResult(
            status=ComponentStatus.HEURISTIC,
            method=HEURISTIC_METHOD,
            label=label.value,
            confidence=None,
            message=f"Severity model not loaded; heuristic used ({why}).",
        )
