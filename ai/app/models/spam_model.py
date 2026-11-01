"""Spam model interface and the scikit-learn implementation.

A trained spam model receives ONE row of features combining text, image and
metadata signals (see ``SPAM_FEATURE_COLUMNS``) plus the normalised
description, and returns P(spam). The expected training artefact is a
scikit-learn ``Pipeline`` (e.g. ColumnTransformer[TF-IDF(description) +
passthrough numeric features] -> LogisticRegression / GradientBoosting)
saved with ``joblib.dump`` to SPAM_MODEL_PATH.
"""

from __future__ import annotations

from abc import abstractmethod
from collections.abc import Mapping
from pathlib import Path
from typing import Any

from app.models.base import BaseModelWrapper

#: Numeric feature columns produced by ``SpamService.extract_features``. A
#: trained pipeline must be fitted on exactly these columns + ``description``.
SPAM_FEATURE_COLUMNS: tuple[str, ...] = (
    # text
    "text_length",
    "token_count",
    "unique_token_ratio",
    "letter_ratio",
    "has_url",
    "has_phone",
    "has_email",
    "has_long_char_run",
    "promo_keyword_hits",
    # image
    "image_available",
    "image_quality_score",
    "image_is_blurry",
    "image_is_too_dark",
    "image_category_confidence",
    # metadata
    "outside_service_area",
    "null_island",
)
SPAM_TEXT_COLUMN = "description"


class SpamModel(BaseModelWrapper):
    role = "spam"

    @abstractmethod
    def predict(self, features: Mapping[str, Any]) -> float:
        """Return the probability (0-1) that the report is spam."""


class SklearnSpamModel(SpamModel):
    def __init__(self, model_path: Path | None) -> None:
        super().__init__(str(model_path) if model_path else None)
        self._path = model_path
        self._pipeline = None
        self._positive_index = 1

    def _load(self) -> None:
        assert self._path is not None
        if not self._path.is_file():
            raise FileNotFoundError(f"spam model not found at {self._path}")
        import joblib

        pipeline = joblib.load(self._path)
        if not hasattr(pipeline, "predict_proba"):
            raise TypeError("spam model must implement predict_proba")
        classes = [str(c).lower() for c in getattr(pipeline, "classes_", [0, 1])]
        for positive in ("1", "true", "spam"):
            if positive in classes:
                self._positive_index = classes.index(positive)
                break
        self._pipeline = pipeline

    def predict(self, features: Mapping[str, Any]) -> float:
        self._require_ready()
        import pandas as pd

        row = {col: features.get(col, 0) for col in (*SPAM_FEATURE_COLUMNS, SPAM_TEXT_COLUMN)}
        proba = self._pipeline.predict_proba(pd.DataFrame([row]))  # type: ignore[union-attr]
        return float(proba[0][self._positive_index])
