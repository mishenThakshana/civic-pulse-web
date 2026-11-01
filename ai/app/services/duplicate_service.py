"""Duplicate report detection.

The AI service has NO database. The Civi Pulse backend owns report storage and
supplies candidate reports through a ``DuplicateCandidateProvider``. Today the
only provider is ``RequestCandidateProvider`` (candidates sent as JSON in the
``candidates`` form field of /api/v1/analyze). A future provider could call a
backend endpoint instead; the service and API do not change.

Development scoring (``weighted_signals_v1``)
--------------------------------------------
For each candidate within DUPLICATE_RADIUS_METERS:

    signals  = proximity (1 at same spot -> 0 at radius)
               text similarity   (embedding cosine, or TF-IDF char n-grams)
               image similarity  (dHash similarity, if both hashes known)
               recency           (1 if same time -> 0 at DUPLICATE_TIME_WINDOW_HOURS)
    weights  = proximity 0.35, text 0.35, image 0.20, recency 0.10
    P(dup)   = sum(w_i * s_i) / sum(w_i)   over AVAILABLE signals only

Candidates beyond the radius get P(dup)=0. If neither text nor image
similarity is available, P(dup) is capped at LOCATION_ONLY_CAP (0.5): being
close in space/time alone never proves a duplicate. Rationale: location and content
are the primary evidence (equal weight); image hashes are strong but often
unavailable and sensitive to viewpoint; time is weak evidence because an
unresolved issue is legitimately re-reported. These weights are NOT learned -
replace them with a classifier trained on the duplicate-pair dataset.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Protocol

import numpy as np

from app.core.config import Settings
from app.core.logging import get_logger
from app.schemas.analysis import ComponentStatus, DuplicateMatch, DuplicateResult
from app.schemas.report import CandidateReport, ReportInput
from app.services.text_service import TextService
from app.utils.similarity import hash_similarity, haversine_meters, proximity_score, recency_score
from app.utils.text import normalize_text

logger = get_logger(__name__)

HEURISTIC_METHOD = "weighted_signals_v1 (development fallback, not a trained model)"
SIGNAL_WEIGHTS: dict[str, float] = {"proximity": 0.35, "text": 0.35, "image": 0.20, "recency": 0.10}
LOCATION_ONLY_CAP = 0.5
TOP_MATCHES = 5


class DuplicateCandidateProvider(Protocol):
    """Supplies existing reports to compare a new report against."""

    def get_candidates(self, report: ReportInput) -> list[CandidateReport]: ...


class NullCandidateProvider:
    def get_candidates(self, report: ReportInput) -> list[CandidateReport]:
        return []


class RequestCandidateProvider:
    """Candidates supplied by the backend in the analyze request."""

    def __init__(self, candidates: Sequence[CandidateReport]) -> None:
        self._candidates = list(candidates)

    def get_candidates(self, report: ReportInput) -> list[CandidateReport]:
        return [c for c in self._candidates if c.report_id != report.report_id]


class DuplicateService:
    def __init__(self, text_service: TextService, settings: Settings) -> None:
        self.text_service = text_service
        self.settings = settings

    def analyze(
        self,
        report: ReportInput,
        text: str,
        image_hash: str | None,
        provider: DuplicateCandidateProvider,
        text_embedding: np.ndarray | None = None,
    ) -> DuplicateResult:
        s = self.settings
        threshold = s.duplicate_threshold

        if not s.enable_heuristic_fallbacks:
            return DuplicateResult(status=ComponentStatus.MODEL_NOT_LOADED, threshold=threshold,
                                   message="Duplicate model not available.")

        candidates = provider.get_candidates(report)[: s.max_duplicate_candidates]
        if not candidates:
            return DuplicateResult(
                status=ComponentStatus.INSUFFICIENT_DATA,
                threshold=threshold,
                message="No candidate reports supplied; duplicate check skipped.",
            )

        nearby: list[tuple[CandidateReport, float]] = []
        for cand in candidates:
            distance = haversine_meters(report.latitude, report.longitude, cand.latitude, cand.longitude)
            if distance <= s.duplicate_radius_meters:
                nearby.append((cand, distance))

        text_sims: list[float | None] = [None] * len(nearby)
        text_method = "none"
        with_text = [i for i, (c, _) in enumerate(nearby) if c.description and c.description.strip()]
        if with_text:
            sims, text_method = self.text_service.similarities(
                text, [normalize_text(nearby[i][0].description or "") for i in with_text], text_embedding
            )
            for i, sim in zip(with_text, sims):
                text_sims[i] = sim

        matches: list[DuplicateMatch] = []
        for (cand, distance), text_sim in zip(nearby, text_sims):
            signals: dict[str, float] = {"proximity": proximity_score(distance, s.duplicate_radius_meters)}
            if text_sim is not None:
                signals["text"] = text_sim
            image_sim = hash_similarity(image_hash, cand.image_hash.lower()) if image_hash and cand.image_hash else None
            if image_sim is not None:
                signals["image"] = image_sim
            hours = None
            if report.created_at and cand.created_at:
                hours = (report.created_at - cand.created_at).total_seconds() / 3600.0
                signals["recency"] = recency_score(hours, s.duplicate_time_window_hours)

            total_w = sum(SIGNAL_WEIGHTS[k] for k in signals)
            probability = sum(SIGNAL_WEIGHTS[k] * v for k, v in signals.items()) / total_w
            if "text" not in signals and "image" not in signals:
                probability = min(probability, LOCATION_ONLY_CAP)
            matches.append(
                DuplicateMatch(
                    report_id=cand.report_id,
                    probability=round(max(0.0, min(1.0, probability)), 4),
                    distance_meters=round(distance, 1),
                    text_similarity=None if text_sim is None else round(text_sim, 4),
                    image_similarity=None if image_sim is None else round(image_sim, 4),
                    hours_apart=None if hours is None else round(hours, 2),
                )
            )

        matches.sort(key=lambda m: m.probability, reverse=True)
        best = matches[0] if matches else None
        probability = best.probability if best else 0.0
        return DuplicateResult(
            status=ComponentStatus.HEURISTIC,
            method=f"{HEURISTIC_METHOD}; text={text_method}",
            probability=probability,
            is_duplicate=probability >= threshold,
            threshold=threshold,
            matched_report_id=best.report_id if best and probability >= threshold else None,
            candidates_evaluated=len(candidates),
            top_matches=matches[:TOP_MATCHES],
            message=None if nearby else f"No candidates within {s.duplicate_radius_meters:.0f} m.",
        )
