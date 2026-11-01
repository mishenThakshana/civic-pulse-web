"""Image model interface and the Ultralytics YOLO implementation."""

from __future__ import annotations

from abc import abstractmethod
from dataclasses import dataclass, field
from pathlib import Path

from PIL import Image

from app.models.base import BaseModelWrapper, resolve_device
from app.schemas.report import IssueCategory


@dataclass
class ImagePrediction:
    label: str | None
    confidence: float | None
    # classification: probability per category; detection: max box confidence per category
    scores: dict[str, float] = field(default_factory=dict)
    detections: int | None = None
    is_distribution: bool = True  # True if ``scores`` sums to ~1 (classification)


class ImageModel(BaseModelWrapper):
    """Predicts an IssueCategory from a report photo."""

    role = "image"

    @abstractmethod
    def predict(self, image: Image.Image) -> ImagePrediction: ...


class YoloImageModel(ImageModel):
    """Ultralytics YOLO wrapper supporting both ``classify`` and ``detect`` weights.

    Weights are only loaded from a local file (IMAGE_MODEL_PATH); nothing is
    downloaded. Model class names must match ``IssueCategory`` values; unknown
    names are mapped to ``other``.
    """

    def __init__(self, weights_path: Path | None, device: str = "auto", imgsz: int = 640) -> None:
        super().__init__(str(weights_path) if weights_path else None)
        self._weights_path = weights_path
        self._device_pref = device
        self._imgsz = imgsz
        self._model = None
        self._device = "cpu"

    def _load(self) -> None:
        assert self._weights_path is not None
        if not self._weights_path.is_file():
            raise FileNotFoundError(f"weights not found at {self._weights_path}")
        from ultralytics import YOLO  # lazy: heavy import

        self._device = resolve_device(self._device_pref)
        self._model = YOLO(str(self._weights_path))
        if self._model.task not in ("classify", "detect", "segment"):
            raise ValueError(f"unsupported YOLO task '{self._model.task}'")

    def predict(self, image: Image.Image) -> ImagePrediction:
        self._require_ready()
        assert self._model is not None
        result = self._model.predict(image, device=self._device, imgsz=self._imgsz, verbose=False)[0]
        names: dict[int, str] = result.names

        if self._model.task == "classify":
            scores: dict[str, float] = {}
            for idx, prob in enumerate(result.probs.data.tolist()):
                label = IssueCategory.normalize(names[idx]).value
                scores[label] = scores.get(label, 0.0) + float(prob)
            label = max(scores, key=scores.get)  # type: ignore[arg-type]
            return ImagePrediction(label=label, confidence=scores[label], scores=scores)

        # detect / segment: keep the highest box confidence per category
        scores = {}
        boxes = result.boxes
        count = 0 if boxes is None else len(boxes)
        for cls_idx, conf in zip(boxes.cls.tolist() if count else [], boxes.conf.tolist() if count else []):
            label = IssueCategory.normalize(names[int(cls_idx)]).value
            scores[label] = max(scores.get(label, 0.0), float(conf))
        if not scores:
            return ImagePrediction(label=None, confidence=None, scores={}, detections=0, is_distribution=False)
        label = max(scores, key=scores.get)  # type: ignore[arg-type]
        return ImagePrediction(
            label=label, confidence=scores[label], scores=scores, detections=count, is_distribution=False
        )
