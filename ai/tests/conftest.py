"""Shared fixtures. No trained models are required: tests either run with every
model unloaded, or with small in-memory STUB models (defined here, test-only)
that exercise the "model loaded" code paths."""

from __future__ import annotations

import io
import zlib
from collections.abc import Mapping, Sequence
from typing import Any

import numpy as np
import pytest
from fastapi.testclient import TestClient
from PIL import Image, ImageDraw

from app.core.config import Settings
from app.main import create_app
from app.models.embedding_model import EmbeddingModel
from app.models.image_model import ImageModel, ImagePrediction
from app.models.registry import ModelRegistry
from app.models.spam_model import SpamModel
from app.models.text_model import TextModel, TextPrediction
from app.schemas.report import ReportInput
from app.services.pipeline import ReportAnalysisPipeline
from app.utils.image import LoadedImage


# --- settings / app ---------------------------------------------------------


def make_settings(**overrides: Any) -> Settings:
    base = {
        "app_env": "test",
        "text_model_name": None,
        "severity_model_name": None,
        "embedding_model_name": None,
        "image_model_path": None,
        "spam_model_path": None,
        "enable_heuristic_fallbacks": True,
        "log_level": "WARNING",
    }
    base.update(overrides)
    return Settings(_env_file=None, **base)


@pytest.fixture
def settings() -> Settings:
    return make_settings()


@pytest.fixture
def client(settings: Settings):
    with TestClient(create_app(settings=settings)) as test_client:
        yield test_client


# --- images -------------------------------------------------------------------


def make_image(size: tuple[int, int] = (320, 240), *, pattern: str = "noise", seed: int = 0) -> Image.Image:
    if pattern == "noise":
        rng = np.random.default_rng(seed)
        return Image.fromarray(rng.integers(0, 256, (size[1], size[0], 3), dtype=np.uint8))
    if pattern == "flat":
        return Image.new("RGB", size, (128, 128, 128))
    if pattern == "dark":
        return Image.new("RGB", size, (5, 5, 5))
    if pattern == "shapes":
        img = Image.new("RGB", size, (200, 200, 200))
        draw = ImageDraw.Draw(img)
        for i in range(0, size[0], 20):
            draw.rectangle([i, 10, i + 10, size[1] - 10], fill=(20, 20, 20))
        return img
    raise ValueError(pattern)


def image_bytes(fmt: str = "JPEG", **kwargs: Any) -> bytes:
    buffer = io.BytesIO()
    make_image(**kwargs).save(buffer, fmt)
    return buffer.getvalue()


@pytest.fixture
def jpeg_bytes() -> bytes:
    return image_bytes("JPEG")


def loaded_image(**kwargs: Any) -> LoadedImage:
    return LoadedImage(image=make_image(**kwargs), mime_type="image/jpeg", size_bytes=1)


def valid_form(**overrides: Any) -> dict[str, str]:
    form = {
        "description": "Large pothole near the school on Galle Road",
        "latitude": "6.9271",
        "longitude": "79.8612",
        "report_id": "report-123",
    }
    form.update(overrides)
    return form


def make_report(**overrides: Any) -> ReportInput:
    data = {
        "report_id": "report-1",
        "description": "Large pothole near the school",
        "latitude": 6.9271,
        "longitude": 79.8612,
    }
    data.update(overrides)
    return ReportInput(**data)


# --- stub models (TEST ONLY - not shipped, not trained) -------------------------


class StubImageModel(ImageModel):
    def __init__(self, scores: dict[str, float], detections: int | None = None) -> None:
        super().__init__("stub-image")
        self._scores = scores
        self._detections = detections

    def _load(self) -> None:
        pass

    def predict(self, image: Image.Image) -> ImagePrediction:
        self._require_ready()
        if not self._scores:
            return ImagePrediction(label=None, confidence=None, scores={}, detections=0, is_distribution=False)
        label = max(self._scores, key=self._scores.get)
        return ImagePrediction(label=label, confidence=self._scores[label], scores=self._scores,
                               detections=self._detections)


class StubTextModel(TextModel):
    def __init__(self, scores: dict[str, float], role: str = "text") -> None:
        super().__init__("stub-text")
        self.role = role
        self._scores = scores

    def _load(self) -> None:
        pass

    def predict(self, text: str) -> TextPrediction:
        self._require_ready()
        label = max(self._scores, key=self._scores.get)
        return TextPrediction(label=label, confidence=self._scores[label], scores=self._scores)


class StubEmbeddingModel(EmbeddingModel):
    """Deterministic bag-of-words hashing embedding."""

    def __init__(self, dim: int = 64) -> None:
        super().__init__("stub-embedding")
        self.dim = dim

    def _load(self) -> None:
        pass

    def encode(self, texts: Sequence[str]) -> np.ndarray:
        self._require_ready()
        out = np.zeros((len(texts), self.dim), dtype=np.float32)
        for i, text in enumerate(texts):
            for token in text.lower().split():
                out[i, zlib.crc32(token.encode()) % self.dim] += 1.0
            norm = np.linalg.norm(out[i])
            if norm:
                out[i] /= norm
        return out


class StubSpamModel(SpamModel):
    def __init__(self, probability: float) -> None:
        super().__init__("stub-spam")
        self.probability = probability
        self.last_features: Mapping[str, Any] | None = None

    def _load(self) -> None:
        pass

    def predict(self, features: Mapping[str, Any]) -> float:
        self._require_ready()
        self.last_features = features
        return self.probability


class FailingImageModel(StubImageModel):
    def __init__(self) -> None:
        super().__init__({"pothole": 1.0})

    def predict(self, image: Image.Image) -> ImagePrediction:
        raise RuntimeError("boom")




def build_registry(settings: Settings, *, load: bool = True, **models: Any) -> ModelRegistry:
    """Unloaded real wrappers by default; override any role with a stub."""
    registry = ModelRegistry.from_settings(settings)
    for role, model in models.items():
        setattr(registry, role, model)
    if load:
        registry.load_all()
    return registry


def build_pipeline(settings: Settings | None = None, **models: Any) -> ReportAnalysisPipeline:
    settings = settings or make_settings()
    return ReportAnalysisPipeline(settings, build_registry(settings, **models))
