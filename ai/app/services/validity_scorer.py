"""Validity score: how likely is this a genuine, actionable infrastructure report?

``ValidityScorer`` is the replaceable interface. The current implementation,
``WeightedValidityScorer``, is a DEVELOPMENT FALLBACK; once the labelled
dataset exists, replace it with a trained scorer (e.g. logistic regression /
gradient boosting over the same component signals, calibrated on validation
data) that implements ``ValidityScorer.score``.

Development formula (``weighted_average_v1``)
---------------------------------------------
    validity = sum(w_i * v_i) / sum(w_i)    over AVAILABLE components only

    component            v_i                         w_i   rationale
    -------------------  --------------------------  ----  -------------------------------------------
    not_spam             1 - spam.probability        0.40  spam/abuse is the main reason a report is invalid
    consistency          consistency.score           0.30  photo and text describing the same issue is
                                                           strong evidence of a genuine report
    category_confidence  category.confidence         0.15  a clearly recognisable issue is more actionable
    image_quality        image.quality.quality_score 0.15  unusable photos cannot be verified by staff

Duplicate probability is deliberately EXCLUDED: a duplicate report is still a
valid report of a real problem (it corroborates it). Duplicates are surfaced
separately via ``duplicate`` and ``requires_human_review``.

Only components whose status is ``ok`` or ``heuristic`` are used, and weights
are renormalised over them, so a missing model lowers ``coverage`` (the share
of total weight available) but does not bias the score toward 0. Consumers
should treat low-coverage scores with caution. Because the weights are hand-set, the result is always
labelled ``status="heuristic"``.
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass

from app.schemas.analysis import (
    CategoryResult,
    ComponentStatus,
    ConsistencyResult,
    ImageAnalysis,
    SpamResult,
    ValidityResult,
)

HEURISTIC_METHOD = "weighted_average_v1 (development fallback, not a trained model)"
COMPONENT_WEIGHTS: dict[str, float] = {
    "not_spam": 0.40,
    "consistency": 0.30,
    "category_confidence": 0.15,
    "image_quality": 0.15,
}


@dataclass
class ValidityInputs:
    spam: SpamResult
    consistency: ConsistencyResult
    category: CategoryResult
    image: ImageAnalysis


class ValidityScorer(ABC):
    @abstractmethod
    def score(self, inputs: ValidityInputs) -> ValidityResult: ...


class WeightedValidityScorer(ValidityScorer):
    def __init__(self, enabled: bool = True, weights: dict[str, float] | None = None) -> None:
        self.enabled = enabled
        self.weights = dict(weights or COMPONENT_WEIGHTS)
        if any(w < 0 for w in self.weights.values()) or sum(self.weights.values()) <= 0:
            raise ValueError("validity weights must be non-negative and not all zero")

    @staticmethod
    def collect_components(inputs: ValidityInputs) -> dict[str, float]:
        components: dict[str, float] = {}
        if inputs.spam.status.has_value and inputs.spam.probability is not None:
            components["not_spam"] = 1.0 - inputs.spam.probability
        if inputs.consistency.status.has_value and inputs.consistency.score is not None:
            components["consistency"] = inputs.consistency.score
        if inputs.category.status.has_value and inputs.category.confidence is not None:
            components["category_confidence"] = inputs.category.confidence
        if inputs.image.quality is not None:
            components["image_quality"] = inputs.image.quality.quality_score
        return components

    def score(self, inputs: ValidityInputs) -> ValidityResult:
        if not self.enabled:
            return ValidityResult(status=ComponentStatus.MODEL_NOT_LOADED, message="Validity model not loaded.")

        components = {k: v for k, v in self.collect_components(inputs).items() if self.weights.get(k, 0) > 0}
        if not components:
            return ValidityResult(
                status=ComponentStatus.INSUFFICIENT_DATA, message="No component signals available."
            )

        total = sum(self.weights[k] for k in components)
        applied = {k: round(self.weights[k] / total, 4) for k in components}
        score = sum(self.weights[k] * v for k, v in components.items()) / total
        missing = [k for k in self.weights if k not in components]
        return ValidityResult(
            status=ComponentStatus.HEURISTIC,
            method=HEURISTIC_METHOD,
            score=round(max(0.0, min(1.0, score)), 4),
            components={k: round(v, 4) for k, v in components.items()},
            weights=applied,
            coverage=round(total / sum(self.weights.values()), 4),
            message=f"Missing components: {', '.join(missing)}" if missing else None,
        )
