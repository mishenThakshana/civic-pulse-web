"""Sentence embedding model interface and the Sentence Transformers implementation.

Embeddings power semantic similarity (duplicate detection) and zero-shot
image/text consistency. Use a multilingual model that covers Sinhala and
Tamil, e.g. ``sentence-transformers/LaBSE`` or
``sentence-transformers/paraphrase-multilingual-mpnet-base-v2`` (verify
coverage on Sri Lankan data before adopting).
"""

from __future__ import annotations

from abc import abstractmethod
from collections.abc import Sequence

import numpy as np

from app.models.base import BaseModelWrapper, resolve_device


class EmbeddingModel(BaseModelWrapper):
    role = "embedding"

    @abstractmethod
    def encode(self, texts: Sequence[str]) -> np.ndarray:
        """Return an (n, d) float32 array of L2-normalised embeddings."""


class SentenceTransformerEmbeddingModel(EmbeddingModel):
    def __init__(self, model_name: str | None, *, device: str = "auto", allow_download: bool = False) -> None:
        super().__init__(model_name)
        self._device_pref = device
        self._allow_download = allow_download
        self._model = None

    def _load(self) -> None:
        from sentence_transformers import SentenceTransformer

        self._model = SentenceTransformer(
            self.source,
            device=resolve_device(self._device_pref),
            local_files_only=not self._allow_download,
        )

    def encode(self, texts: Sequence[str]) -> np.ndarray:
        self._require_ready()
        assert self._model is not None
        vectors = self._model.encode(list(texts), normalize_embeddings=True, convert_to_numpy=True)
        return np.asarray(vectors, dtype=np.float32)
