"""Image/text consistency: do the photo and the description show the same issue?

Strategies, in order of preference:

1. ``label_distribution_overlap`` - image model and text model both loaded:
   score = Bhattacharyya coefficient between the image category distribution
   and the text category distribution (1 = identical, 0 = disjoint).
2. ``zero_shot_embedding`` - image model + embedding model loaded: the text is
   embedded and compared with a short prompt describing each category; a
   softmax over those similarities gives a text category distribution that is
   compared with the image distribution as in (1). Works across English,
   Sinhala and Tamil when the embedding model is multilingual.
3. Otherwise ``model_not_loaded``. (A future joint image-text model such as a
   fine-tuned multilingual CLIP can be added as strategy 0.)

Example: image=pothole, text="Large pothole near the school" -> high score;
image=broken_street_light, text="Garbage dumped beside the road" -> low score.
"""

from __future__ import annotations

import math

import numpy as np

from app.core.config import Settings
from app.core.logging import get_logger
from app.schemas.analysis import ComponentStatus, ConsistencyResult, ImageAnalysis, TextAnalysis
from app.schemas.report import IssueCategory
from app.services.text_service import TextService
from app.utils.similarity import bhattacharyya_coefficient, cosine_similarity

logger = get_logger(__name__)

CATEGORY_PROMPTS: dict[str, str] = {
    IssueCategory.ROAD_DAMAGE.value: "cracked or broken road surface",
    IssueCategory.POTHOLE.value: "a pothole in the road",
    IssueCategory.GARBAGE.value: "garbage and waste dumped on the street",
    IssueCategory.FLOODING.value: "flooded road or area with standing water",
    IssueCategory.BLOCKED_DRAIN.value: "a blocked or overflowing drain",
    IssueCategory.BROKEN_STREET_LIGHT.value: "a broken or non-working street light",
    IssueCategory.FALLEN_TREE.value: "a fallen tree or branch",
    IssueCategory.DAMAGED_SIGN.value: "a damaged or missing road sign",
    IssueCategory.DAMAGED_PUBLIC_PROPERTY.value: "damaged public property such as a bench, bus stop or wall",
    IssueCategory.OTHER.value: "a public infrastructure problem",
}
# Softmax temperature for zero-shot similarities. Sentence-embedding cosines
# lie in a narrow band, so a low temperature is needed to separate categories.
ZERO_SHOT_TEMPERATURE = 0.05


def _softmax(values: dict[str, float], temperature: float) -> dict[str, float]:
    peak = max(values.values())
    exps = {k: math.exp((v - peak) / temperature) for k, v in values.items()}
    total = sum(exps.values())
    return {k: v / total for k, v in exps.items()}


def _image_distribution(image: ImageAnalysis) -> dict[str, float] | None:
    if image.status != ComponentStatus.OK or not image.scores:
        return None
    total = sum(image.scores.values())
    return {k: v / total for k, v in image.scores.items()} if total > 0 else None


class ConsistencyService:
    def __init__(self, text_service: TextService, settings: Settings) -> None:
        self.text_service = text_service
        self.settings = settings
        self._prompt_embeddings: dict[str, np.ndarray] | None = None

    def _result(self, score: float, method: str) -> ConsistencyResult:
        score = round(max(0.0, min(1.0, score)), 4)
        return ConsistencyResult(
            status=ComponentStatus.OK,
            method=method,
            score=score,
            is_consistent=score >= self.settings.consistency_threshold,
            threshold=self.settings.consistency_threshold,
        )

    def analyze(
        self, image: ImageAnalysis, text: TextAnalysis, text_embedding: np.ndarray | None
    ) -> ConsistencyResult:
        threshold = self.settings.consistency_threshold

        if image.status == ComponentStatus.OK and image.detections == 0:
            return ConsistencyResult(
                status=ComponentStatus.INSUFFICIENT_DATA,
                threshold=threshold,
                message="Image model detected no issue in the photo.",
            )

        image_dist = _image_distribution(image)
        if image_dist is None:
            return ConsistencyResult(
                status=ComponentStatus.MODEL_NOT_LOADED,
                threshold=threshold,
                message="Consistency requires the image model plus the text or embedding model.",
            )

        if text.status == ComponentStatus.OK and text.scores:
            return self._result(bhattacharyya_coefficient(image_dist, text.scores), "label_distribution_overlap")

        if text_embedding is not None:
            prompts = self._get_prompt_embeddings()
            if prompts is not None:
                sims = {label: cosine_similarity(text_embedding, vec) for label, vec in prompts.items()}
                text_dist = _softmax(sims, ZERO_SHOT_TEMPERATURE)
                return self._result(bhattacharyya_coefficient(image_dist, text_dist), "zero_shot_embedding")

        return ConsistencyResult(
            status=ComponentStatus.MODEL_NOT_LOADED,
            threshold=threshold,
            message="Consistency requires the text or embedding model in addition to the image model.",
        )

    def _get_prompt_embeddings(self) -> dict[str, np.ndarray] | None:
        if self._prompt_embeddings is None:
            labels = list(CATEGORY_PROMPTS)
            vectors = self.text_service.embed([CATEGORY_PROMPTS[k] for k in labels])
            if vectors is None:
                return None
            self._prompt_embeddings = dict(zip(labels, vectors))
        return self._prompt_embeddings
