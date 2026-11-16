"""Response schemas for /api/v1/analyze.

Every component carries a ``status`` so consumers can always tell whether a
value came from a trained model, a development heuristic, or is missing:

* ``ok``               - produced by a loaded, trained model.
* ``heuristic``        - produced by a documented rule-based development
                         fallback. NOT a trained model; do not treat as AI output.
* ``model_not_loaded`` - the required model is not configured/available.
                         Value fields are ``null``.
* ``insufficient_data``- inputs needed for this component were missing
                         (e.g. no duplicate candidates supplied).
* ``error``            - the component failed; other components still ran.
"""

from __future__ import annotations

from datetime import datetime
from enum import Enum

from pydantic import BaseModel, Field


class ComponentStatus(str, Enum):
    OK = "ok"
    HEURISTIC = "heuristic"
    MODEL_NOT_LOADED = "model_not_loaded"
    INSUFFICIENT_DATA = "insufficient_data"
    UNSUPPORTED_LANGUAGE = "unsupported_language"
    ERROR = "error"

    @property
    def has_value(self) -> bool:
        return self in (ComponentStatus.OK, ComponentStatus.HEURISTIC)


class ComponentResult(BaseModel):
    status: ComponentStatus
    method: str | None = Field(
        default=None, description="Model name/version or heuristic identifier that produced the value."
    )
    message: str | None = None


# --- Image / text intermediate results ------------------------------------


class ImageQuality(BaseModel):
    width: int
    height: int
    blur_score: float = Field(description="Variance of the Laplacian; low values mean blurry.")
    brightness: float = Field(description="Mean grey level, 0-255.")
    is_blurry: bool
    is_too_dark: bool
    is_too_bright: bool
    quality_score: float = Field(ge=0.0, le=1.0)


class ImageAnalysis(ComponentResult):
    label: str | None = None
    confidence: float | None = Field(default=None, ge=0.0, le=1.0)
    scores: dict[str, float] = Field(default_factory=dict)
    detections: int | None = None
    quality: ImageQuality | None = None
    perceptual_hash: str | None = Field(
        default=None,
        description="64-bit dHash (hex). Store it and send it back as a duplicate candidate's image_hash.",
    )


class TextAnalysis(ComponentResult):
    language: str | None = Field(default=None, description="Detected script-based language: en, si, ta, mixed, unknown.")
    normalized_length: int | None = None
    label: str | None = None
    confidence: float | None = Field(default=None, ge=0.0, le=1.0)
    scores: dict[str, float] = Field(default_factory=dict)
    has_embedding: bool = False


# --- Public result blocks ---------------------------------------------------


class CategoryResult(ComponentResult):
    label: str | None = None
    confidence: float | None = Field(default=None, ge=0.0, le=1.0)
    source: str | None = Field(default=None, description="image, text or image+text")


class SpamResult(ComponentResult):
    probability: float | None = Field(default=None, ge=0.0, le=1.0)
    is_suspicious: bool | None = None
    threshold: float
    reasons: list[str] = Field(default_factory=list)


class SeverityResult(ComponentResult):
    label: str | None = None
    confidence: float | None = Field(default=None, ge=0.0, le=1.0)


class ConsistencyResult(ComponentResult):
    score: float | None = Field(default=None, ge=0.0, le=1.0)
    is_consistent: bool | None = None
    threshold: float


class DuplicateMatch(BaseModel):
    report_id: str
    probability: float = Field(ge=0.0, le=1.0)
    distance_meters: float
    text_similarity: float | None = None
    image_similarity: float | None = None
    hours_apart: float | None = None


class DuplicateResult(ComponentResult):
    probability: float | None = Field(default=None, ge=0.0, le=1.0)
    is_duplicate: bool | None = None
    threshold: float
    matched_report_id: str | None = None
    candidates_evaluated: int = 0
    top_matches: list[DuplicateMatch] = Field(default_factory=list)


class ValidityResult(ComponentResult):
    score: float | None = Field(default=None, ge=0.0, le=1.0)
    components: dict[str, float] = Field(
        default_factory=dict, description="Per-signal validity contributions (0-1) that were available."
    )
    weights: dict[str, float] = Field(default_factory=dict, description="Normalised weights actually applied.")
    coverage: float | None = Field(
        default=None,
        ge=0.0,
        le=1.0,
        description="Share of the total component weight that was available. Low coverage = less reliable score.",
    )


class ModelStatus(BaseModel):
    name: str
    ready: bool
    source: str | None = None
    error: str | None = None


class AnalysisMeta(BaseModel):
    service: str
    version: str
    analyzed_at: datetime
    processing_ms: float
    models: dict[str, ModelStatus]
    uses_development_fallbacks: bool = Field(
        description="True when any value came from a heuristic rather than a trained model."
    )


class AnalysisResponse(BaseModel):
    report_id: str
    category: CategoryResult
    spam: SpamResult
    severity: SeverityResult
    consistency: ConsistencyResult
    duplicate: DuplicateResult
    validity: ValidityResult
    validity_score: float | None = Field(
        default=None, ge=0.0, le=1.0, description="Shortcut for validity.score; null when not computable."
    )
    requires_human_review: bool = Field(
        description="Advisory flag only. The AI service never deletes or rejects reports."
    )
    review_reasons: list[str] = Field(default_factory=list)
    image: ImageAnalysis
    text: TextAnalysis
    meta: AnalysisMeta


class HealthResponse(BaseModel):
    status: str
    service: str


class ModelsResponse(BaseModel):
    models: dict[str, ModelStatus]
    heuristic_fallbacks_enabled: bool
