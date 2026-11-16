"""ReportAnalysisPipeline - orchestrates every analysis component.

    Image                      -> ImageService        (quality, hash, category)
    Text                       -> TextService         (language, category, embedding)
    Image + Text               -> category fusion + ConsistencyService
    Text + Image + Metadata    -> SpamService
    Text                       -> SeverityService
    Report + Candidates        -> DuplicateService
    All results                -> ValidityScorer

When the multimodal screening model is loaded it assesses photo + text in a
single pass and supplies category, spam, severity and consistency; if it is
not loaded (or fails) the per-component services above are used instead.

Each component is isolated: a failure produces ``status="error"`` for that
component only. The pipeline is stateless and never stores, deletes or
rejects reports - it only returns advisory flags.
"""

from __future__ import annotations

import time
from collections.abc import Callable
from datetime import datetime, timezone
from typing import TypeVar

from app.core.config import Settings
from app.core.logging import get_logger
from app.models.multimodal_model import MultimodalAssessment
from app.models.registry import ModelRegistry
from app.schemas.analysis import (
    AnalysisMeta,
    AnalysisResponse,
    CategoryResult,
    ComponentResult,
    ComponentStatus,
    ConsistencyResult,
    DuplicateResult,
    ImageAnalysis,
    SeverityResult,
    SpamResult,
    TextAnalysis,
)
from app.schemas.report import ReportInput
from app.services.consistency_service import ConsistencyService
from app.services.duplicate_service import DuplicateCandidateProvider, DuplicateService, NullCandidateProvider
from app.services.image_service import ImageService
from app.services.severity_service import SeverityService
from app.services.spam_service import SpamService
from app.services.text_service import TextService
from app.services.validity_scorer import ValidityInputs, ValidityScorer, WeightedValidityScorer
from app.utils.image import LoadedImage

logger = get_logger(__name__)

# Category fusion weights when both image and text models are loaded. Equal
# weighting is the neutral default; tune on the validation set once both
# models are trained (the image is usually more reliable for visual issues).
CATEGORY_FUSION_WEIGHTS = {"image": 0.5, "text": 0.5}

R = TypeVar("R", bound=ComponentResult)


def fuse_category(image: ImageAnalysis, text: TextAnalysis) -> CategoryResult:
    sources: dict[str, dict[str, float]] = {}
    if image.status == ComponentStatus.OK and image.scores:
        sources["image"] = image.scores
    if text.status == ComponentStatus.OK and text.scores:
        sources["text"] = text.scores

    if not sources:
        if image.status == ComponentStatus.OK and image.detections == 0:
            return CategoryResult(status=ComponentStatus.INSUFFICIENT_DATA,
                                  message="No issue detected in the image and no text model loaded.")
        return CategoryResult(status=ComponentStatus.MODEL_NOT_LOADED,
                              message="Neither the image nor the text category model is loaded.")

    fused: dict[str, float] = {}
    total_w = sum(CATEGORY_FUSION_WEIGHTS[s] for s in sources)
    for source, scores in sources.items():
        norm = sum(scores.values()) or 1.0
        for label, value in scores.items():
            fused[label] = fused.get(label, 0.0) + CATEGORY_FUSION_WEIGHTS[source] / total_w * value / norm
    label = max(fused, key=fused.get)  # type: ignore[arg-type]
    methods = [m for m in (image.method if "image" in sources else None, text.method if "text" in sources else None) if m]
    return CategoryResult(
        status=ComponentStatus.OK,
        method=" + ".join(methods),
        label=label,
        confidence=round(max(0.0, min(1.0, fused[label])), 4),
        source="+".join(sources),
    )


class ReportAnalysisPipeline:
    def __init__(
        self,
        settings: Settings,
        registry: ModelRegistry,
        validity_scorer: ValidityScorer | None = None,
    ) -> None:
        self.settings = settings
        self.registry = registry
        self.image_service = ImageService(registry.image, settings)
        self.text_service = TextService(registry.text, registry.embedding, settings)
        self.spam_service = SpamService(registry.spam, settings)
        self.severity_service = SeverityService(registry.severity, settings)
        self.consistency_service = ConsistencyService(self.text_service, settings)
        self.duplicate_service = DuplicateService(self.text_service, settings)
        self.validity_scorer = validity_scorer or WeightedValidityScorer(enabled=settings.enable_heuristic_fallbacks)

    def analyze(
        self,
        report: ReportInput,
        image: LoadedImage,
        candidate_provider: DuplicateCandidateProvider | None = None,
    ) -> AnalysisResponse:
        started = time.perf_counter()
        s = self.settings
        provider = candidate_provider or NullCandidateProvider()
        text = self.text_service.prepare(report.description)

        image_result = _guard(lambda: self.image_service.analyze(image), ImageAnalysis, "image")
        try:
            text_result, embedding = self.text_service.analyze(text, report.language_hint)
        except Exception as exc:  # noqa: BLE001
            logger.exception("Component 'text' failed")
            text_result, embedding = TextAnalysis(status=ComponentStatus.ERROR, message=f"text failed: {type(exc).__name__}"), None

        assessment = self._assess_multimodal(image, text, report)
        if assessment is not None:
            method = self.registry.multimodal.name
            image_result = image_result.model_copy(update={
                "status": ComponentStatus.OK,
                "method": method,
                "message": None,
                "label": assessment.category,
                "confidence": assessment.category_confidence,
                "scores": {assessment.category: assessment.category_confidence},
                "detections": 1 if assessment.image_shows_issue else 0,
            })
            category = CategoryResult(
                status=ComponentStatus.OK, method=method, label=assessment.category,
                confidence=assessment.category_confidence, source="image+text",
            )
            consistency = ConsistencyResult(
                status=ComponentStatus.OK, method=method, score=assessment.consistency_score,
                is_consistent=assessment.consistency_score >= s.consistency_threshold,
                threshold=s.consistency_threshold,
            )
            spam = SpamResult(
                status=ComponentStatus.OK, method=method, probability=assessment.spam_probability,
                is_suspicious=assessment.spam_probability >= s.spam_threshold,
                threshold=s.spam_threshold, reasons=assessment.spam_reasons,
                message=assessment.summary or None,
            )
            severity = SeverityResult(
                status=ComponentStatus.OK, method=method, label=assessment.severity,
                confidence=assessment.severity_confidence,
            )
        else:
            category = _guard(lambda: fuse_category(image_result, text_result), CategoryResult, "category")
            consistency = _guard(
                lambda: self.consistency_service.analyze(image_result, text_result, embedding),
                ConsistencyResult, "consistency", threshold=s.consistency_threshold,
            )
            spam = _guard(
                lambda: self.spam_service.analyze(text, report, image_result, text_result.language),
                SpamResult, "spam", threshold=s.spam_threshold,
            )
            severity = _guard(
                lambda: self.severity_service.analyze(text, text_result.language, category.label),
                SeverityResult, "severity",
            )
        duplicate = _guard(
            lambda: self.duplicate_service.analyze(report, text, image_result.perceptual_hash, provider, embedding),
            DuplicateResult, "duplicate", threshold=s.duplicate_threshold,
        )
        validity = self.validity_scorer.score(
            ValidityInputs(spam=spam, consistency=consistency, category=category, image=image_result)
        )

        review_reasons: list[str] = []
        if spam.is_suspicious:
            review_reasons.append("possible_spam")
        if duplicate.is_duplicate:
            review_reasons.append("possible_duplicate")
        if consistency.is_consistent is False:
            review_reasons.append("image_text_mismatch")
        if validity.score is not None and validity.score < s.validity_review_threshold:
            review_reasons.append("low_validity_score")

        components = (image_result, text_result, category, consistency, spam, severity, duplicate, validity)
        response = AnalysisResponse(
            report_id=report.report_id,
            category=category,
            spam=spam,
            severity=severity,
            consistency=consistency,
            duplicate=duplicate,
            validity=validity,
            validity_score=validity.score,
            requires_human_review=bool(review_reasons),
            review_reasons=review_reasons,
            image=image_result,
            text=text_result,
            meta=AnalysisMeta(
                service=s.service_name,
                version=s.service_version,
                analyzed_at=datetime.now(timezone.utc),
                processing_ms=round((time.perf_counter() - started) * 1000, 2),
                models=self.registry.statuses(),
                uses_development_fallbacks=any(c.status == ComponentStatus.HEURISTIC for c in components),
            ),
        )
        logger.info(
            "Analyzed report %s in %.1f ms (review=%s)",
            report.report_id, response.meta.processing_ms, response.requires_human_review,
        )
        return response

    def _assess_multimodal(self, image: LoadedImage, text: str, report: ReportInput) -> MultimodalAssessment | None:
        model = self.registry.multimodal
        if not model.is_ready():
            return None
        s = self.settings
        in_area = (
            s.service_area_min_lat <= report.latitude <= s.service_area_max_lat
            and s.service_area_min_lon <= report.longitude <= s.service_area_max_lon
        )
        context = f"Location {'inside' if in_area else 'OUTSIDE'} the expected service area (Sri Lanka)."
        try:
            return model.assess(image.image, text, context)
        except Exception as exc:  # noqa: BLE001 - fall back to the per-component models
            logger.warning("Multimodal model failed, falling back: %s", type(exc).__name__)
            return None


def _guard(fn: Callable[[], R], result_type: type[R], name: str, **defaults) -> R:
    try:
        return fn()
    except Exception as exc:  # noqa: BLE001 - one failing component must not fail the whole analysis
        logger.exception("Component '%s' failed", name)
        return result_type(status=ComponentStatus.ERROR, message=f"{name} failed: {type(exc).__name__}", **defaults)
