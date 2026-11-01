"""Builds and holds every model used by the service.

Swapping an implementation (e.g. a CLIP image model or an ONNX text model)
only requires changing ``ModelRegistry.from_settings``; services depend on the
abstract interfaces, never on YOLO or Transformers directly.
"""

from __future__ import annotations

from dataclasses import dataclass

from app.core.config import Settings
from app.models.embedding_model import EmbeddingModel, SentenceTransformerEmbeddingModel
from app.models.image_model import ImageModel, YoloImageModel
from app.models.multimodal_model import GeminiMultimodalModel, MultimodalModel
from app.models.spam_model import SklearnSpamModel, SpamModel
from app.models.text_model import TextModel, TransformersTextClassifier
from app.schemas.analysis import ModelStatus
from app.schemas.report import IssueCategory


def _severity_label(label: str) -> str:
    # Unknown labels are passed through; SeverityService rejects them as an error
    # instead of silently guessing a level.
    return label.strip().lower()


@dataclass
class ModelRegistry:
    image: ImageModel
    text: TextModel
    severity: TextModel
    embedding: EmbeddingModel
    spam: SpamModel
    multimodal: MultimodalModel

    @classmethod
    def from_settings(cls, settings: Settings) -> "ModelRegistry":
        return cls(
            image=YoloImageModel(settings.image_model_path, device=settings.device),
            text=TransformersTextClassifier(
                settings.text_model_name,
                role="text",
                device=settings.device,
                allow_download=settings.allow_model_download,
                label_mapper=lambda s: IssueCategory.normalize(s).value,
            ),
            severity=TransformersTextClassifier(
                settings.severity_model_name,
                role="severity",
                device=settings.device,
                allow_download=settings.allow_model_download,
                label_mapper=_severity_label,
            ),
            embedding=SentenceTransformerEmbeddingModel(
                settings.embedding_model_name,
                device=settings.device,
                allow_download=settings.allow_model_download,
            ),
            spam=SklearnSpamModel(settings.spam_model_path),
            multimodal=GeminiMultimodalModel(
                settings.gemini_api_key,
                settings.gemini_model,
                fallback_model_name=settings.gemini_fallback_model,
                timeout_seconds=settings.gemini_timeout_seconds,
            ),
        )

    def all(self) -> dict[str, "ImageModel | TextModel | EmbeddingModel | SpamModel | MultimodalModel"]:
        return {
            "image": self.image,
            "text": self.text,
            "severity": self.severity,
            "embedding": self.embedding,
            "spam": self.spam,
            "multimodal": self.multimodal,
        }

    def load_all(self) -> None:
        for model in self.all().values():
            model.load()

    def statuses(self) -> dict[str, ModelStatus]:
        return {key: model.status() for key, model in self.all().items()}
