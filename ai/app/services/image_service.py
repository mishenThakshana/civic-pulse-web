"""Image analysis: validation, quality checks, perceptual hash and category inference."""

from __future__ import annotations

from app.core.config import Settings
from app.core.logging import get_logger
from app.models.image_model import ImageModel
from app.schemas.analysis import ComponentStatus, ImageAnalysis
from app.utils.image import LoadedImage, assess_quality, difference_hash, validate_and_load_image

logger = get_logger(__name__)


class ImageService:
    def __init__(self, model: ImageModel, settings: Settings) -> None:
        self.model = model
        self.settings = settings

    def load(self, data: bytes, content_type: str | None) -> LoadedImage:
        """Validate MIME type, size, dimensions and decodability (raises on failure)."""
        return validate_and_load_image(
            data,
            content_type,
            max_bytes=self.settings.max_image_size_bytes,
            min_dimension=self.settings.min_image_dimension,
            max_pixels=self.settings.max_image_pixels,
        )

    def analyze(self, loaded: LoadedImage) -> ImageAnalysis:
        # Quality metrics and the perceptual hash are deterministic computations
        # (no model required), so they are always returned.
        quality = assess_quality(loaded.image)
        phash = difference_hash(loaded.image)

        if not self.model.is_ready():
            return ImageAnalysis(
                status=ComponentStatus.MODEL_NOT_LOADED,
                message="Image model not loaded; quality metrics and hash are still computed.",
                quality=quality,
                perceptual_hash=phash,
            )

        try:
            prediction = self.model.predict(loaded.image)
        except Exception as exc:  # noqa: BLE001
            logger.warning("Image inference failed: %s", type(exc).__name__)
            return ImageAnalysis(
                status=ComponentStatus.ERROR,
                message=f"Image inference failed: {type(exc).__name__}",
                quality=quality,
                perceptual_hash=phash,
            )

        return ImageAnalysis(
            status=ComponentStatus.OK,
            method=self.model.name,
            label=prediction.label,
            confidence=_clip(prediction.confidence),
            scores={k: round(_clip(v) or 0.0, 6) for k, v in prediction.scores.items()},
            detections=prediction.detections,
            quality=quality,
            perceptual_hash=phash,
        )


def _clip(value: float | None) -> float | None:
    return None if value is None else max(0.0, min(1.0, float(value)))
