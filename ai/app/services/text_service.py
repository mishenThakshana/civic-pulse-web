"""Text analysis: normalisation, language detection, classification and embeddings."""

from __future__ import annotations

from collections.abc import Sequence

import numpy as np

from app.core.config import Settings
from app.core.exceptions import InputValidationError
from app.core.logging import get_logger
from app.models.embedding_model import EmbeddingModel
from app.models.text_model import TextModel
from app.schemas.analysis import ComponentStatus, TextAnalysis
from app.utils.similarity import char_ngram_similarities, cosine_similarity
from app.utils.text import detect_language, normalize_text

logger = get_logger(__name__)

SUPPORTED_LANGUAGES = ("en", "si", "ta")


class TextService:
    def __init__(self, classifier: TextModel, embedder: EmbeddingModel, settings: Settings) -> None:
        self.classifier = classifier
        self.embedder = embedder
        self.settings = settings

    def prepare(self, raw: str | None) -> str:
        """Normalise and validate a description (raises InputValidationError)."""
        if raw is None:
            raise InputValidationError("description is required.")
        text = normalize_text(raw)
        if not text:
            raise InputValidationError("description must not be empty.")
        if len(text) > self.settings.max_description_length:
            raise InputValidationError(
                f"description exceeds {self.settings.max_description_length} characters.",
                details={"length": len(text)},
            )
        return text

    def analyze(self, text: str, language_hint: str | None = None) -> tuple[TextAnalysis, np.ndarray | None]:
        language = detect_language(text)
        if language_hint and language_hint.lower() in SUPPORTED_LANGUAGES and language in ("unknown", "mixed"):
            language = language_hint.lower()

        embedding = self._embed_one(text)
        base = {"language": language, "normalized_length": len(text), "has_embedding": embedding is not None}

        if not self.classifier.is_ready():
            return (
                TextAnalysis(
                    status=ComponentStatus.MODEL_NOT_LOADED,
                    message="Text classification model not loaded.",
                    **base,
                ),
                embedding,
            )
        try:
            prediction = self.classifier.predict(text)
        except Exception as exc:  # noqa: BLE001
            logger.warning("Text inference failed: %s", type(exc).__name__)
            return (
                TextAnalysis(status=ComponentStatus.ERROR, message=f"Text inference failed: {type(exc).__name__}", **base),
                embedding,
            )
        return (
            TextAnalysis(
                status=ComponentStatus.OK,
                method=self.classifier.name,
                label=prediction.label,
                confidence=max(0.0, min(1.0, prediction.confidence)),
                scores={k: round(max(0.0, min(1.0, v)), 6) for k, v in prediction.scores.items()},
                **base,
            ),
            embedding,
        )

    # --- embeddings / similarity -----------------------------------------

    def embed(self, texts: Sequence[str]) -> np.ndarray | None:
        if not self.embedder.is_ready() or not texts:
            return None
        try:
            return self.embedder.encode(texts)
        except Exception as exc:  # noqa: BLE001
            logger.warning("Embedding failed: %s", type(exc).__name__)
            return None

    def _embed_one(self, text: str) -> np.ndarray | None:
        vectors = self.embed([text])
        return None if vectors is None else vectors[0]

    def similarities(
        self, query: str, others: Sequence[str], query_embedding: np.ndarray | None = None
    ) -> tuple[list[float], str]:
        """Semantic similarity of ``query`` to each text in ``others`` (0-1).

        Uses the embedding model when loaded, otherwise TF-IDF character
        n-grams (lexical, language-agnostic development fallback).
        """
        if not others:
            return [], "none"
        if self.embedder.is_ready():
            vectors = self.embed([query, *others]) if query_embedding is None else self.embed(list(others))
            if vectors is not None:
                q = query_embedding if query_embedding is not None else vectors[0]
                rest = vectors if query_embedding is not None else vectors[1:]
                return [max(0.0, cosine_similarity(q, v)) for v in rest], "embedding_cosine"
        return char_ngram_similarities(query, others), "tfidf_char_ngram"
