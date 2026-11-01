"""Image validation, loading and preprocessing helpers.

The declared Content-Type of an upload is never trusted on its own: the file's
magic bytes must agree, and Pillow must be able to decode it.
"""

from __future__ import annotations

import io
import warnings
from dataclasses import dataclass

import cv2
import numpy as np
from PIL import Image, ImageOps, UnidentifiedImageError

from app.core.exceptions import ImageTooLargeError, InvalidImageError, UnsupportedMediaTypeError
from app.schemas.analysis import ImageQuality

ALLOWED_MIME_TYPES: frozenset[str] = frozenset({"image/jpeg", "image/png", "image/webp"})
_MIME_ALIASES = {"image/jpg": "image/jpeg", "image/pjpeg": "image/jpeg"}
_PIL_FORMAT_TO_MIME = {"JPEG": "image/jpeg", "PNG": "image/png", "WEBP": "image/webp"}

# Quality thresholds for the development image-quality check. They are
# conservative rules of thumb and should be re-tuned on real report photos.
BLUR_VARIANCE_THRESHOLD = 60.0
DARK_BRIGHTNESS_THRESHOLD = 35.0
BRIGHT_BRIGHTNESS_THRESHOLD = 225.0


@dataclass(frozen=True)
class LoadedImage:
    image: Image.Image  # RGB, EXIF orientation applied, metadata dropped
    mime_type: str
    size_bytes: int

    @property
    def width(self) -> int:
        return self.image.width

    @property
    def height(self) -> int:
        return self.image.height


def normalize_mime_type(content_type: str | None) -> str | None:
    if not content_type:
        return None
    mime = content_type.split(";", 1)[0].strip().lower()
    return _MIME_ALIASES.get(mime, mime)


def sniff_mime_type(data: bytes) -> str | None:
    """Detect the image type from magic bytes."""
    if data.startswith(b"\xff\xd8\xff"):
        return "image/jpeg"
    if data.startswith(b"\x89PNG\r\n\x1a\n"):
        return "image/png"
    if len(data) >= 12 and data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "image/webp"
    return None


def validate_and_load_image(
    data: bytes,
    declared_content_type: str | None,
    *,
    max_bytes: int,
    min_dimension: int,
    max_pixels: int,
) -> LoadedImage:
    """Validate raw upload bytes and return a normalised RGB Pillow image."""
    if not data:
        raise InvalidImageError("Image file is empty.")
    if len(data) > max_bytes:
        raise ImageTooLargeError(
            f"Image exceeds the maximum size of {max_bytes / (1024 * 1024):.1f} MB.",
            details={"size_bytes": len(data), "max_bytes": max_bytes},
        )

    declared = normalize_mime_type(declared_content_type)
    if declared is not None and declared not in ALLOWED_MIME_TYPES and declared != "application/octet-stream":
        raise UnsupportedMediaTypeError(
            f"Unsupported image type '{declared}'.", details={"allowed": sorted(ALLOWED_MIME_TYPES)}
        )

    sniffed = sniff_mime_type(data)
    if sniffed is None:
        raise UnsupportedMediaTypeError(
            "File content is not a supported image (JPEG, PNG or WebP).",
            details={"allowed": sorted(ALLOWED_MIME_TYPES)},
        )
    if declared in ALLOWED_MIME_TYPES and declared != sniffed:
        raise InvalidImageError(
            "Declared content type does not match the file content.",
            details={"declared": declared, "detected": sniffed},
        )

    try:
        with warnings.catch_warnings():
            warnings.simplefilter("error", Image.DecompressionBombWarning)
            probe = Image.open(io.BytesIO(data))
            width, height = probe.size
            if width * height > max_pixels:
                raise InvalidImageError(
                    "Image resolution is too large.",
                    details={"pixels": width * height, "max_pixels": max_pixels},
                )
            probe.verify()  # structural check; the object is unusable afterwards

            image = Image.open(io.BytesIO(data))
            image.load()
    except InvalidImageError:
        raise
    except (UnidentifiedImageError, OSError, SyntaxError, ValueError, Image.DecompressionBombWarning,
            Image.DecompressionBombError) as exc:
        raise InvalidImageError("Image could not be decoded.", details={"reason": type(exc).__name__}) from exc

    detected_format = _PIL_FORMAT_TO_MIME.get(image.format or "")
    if detected_format != sniffed:
        raise InvalidImageError("Image format could not be confirmed.", details={"format": image.format})

    if min(image.size) < min_dimension:
        raise InvalidImageError(
            f"Image is too small; minimum dimension is {min_dimension}px.",
            details={"width": image.width, "height": image.height},
        )

    # Apply camera orientation, then drop all metadata (EXIF can contain GPS,
    # device ids, timestamps) by converting to a fresh RGB image.
    image = ImageOps.exif_transpose(image)
    rgb = image.convert("RGB")
    clean = Image.new("RGB", rgb.size)
    clean.paste(rgb)
    return LoadedImage(image=clean, mime_type=sniffed, size_bytes=len(data))


def pil_to_bgr(image: Image.Image) -> np.ndarray:
    """Convert a Pillow RGB image to an OpenCV BGR uint8 array."""
    return cv2.cvtColor(np.asarray(image.convert("RGB")), cv2.COLOR_RGB2BGR)


def resize_for_inference(image: Image.Image, max_side: int = 1280) -> Image.Image:
    """Downscale (never upscale) so the longest side is at most ``max_side``."""
    longest = max(image.size)
    if longest <= max_side:
        return image
    scale = max_side / longest
    return image.resize((max(1, round(image.width * scale)), max(1, round(image.height * scale))), Image.LANCZOS)


def to_normalized_array(image: Image.Image, size: tuple[int, int] = (224, 224)) -> np.ndarray:
    """Generic CHW float32 tensor in [0, 1] for custom (non-YOLO) image models."""
    resized = image.convert("RGB").resize(size, Image.BILINEAR)
    array = np.asarray(resized, dtype=np.float32) / 255.0
    return np.transpose(array, (2, 0, 1))


def assess_quality(image: Image.Image) -> ImageQuality:
    """Cheap, model-free photo quality checks (blur and exposure)."""
    grey = cv2.cvtColor(np.asarray(resize_for_inference(image, 1024)), cv2.COLOR_RGB2GRAY)
    blur_score = float(cv2.Laplacian(grey, cv2.CV_64F).var())
    brightness = float(grey.mean())

    is_blurry = blur_score < BLUR_VARIANCE_THRESHOLD
    is_too_dark = brightness < DARK_BRIGHTNESS_THRESHOLD
    is_too_bright = brightness > BRIGHT_BRIGHTNESS_THRESHOLD

    sharpness = min(1.0, blur_score / (BLUR_VARIANCE_THRESHOLD * 2))
    if brightness < DARK_BRIGHTNESS_THRESHOLD:
        exposure = brightness / DARK_BRIGHTNESS_THRESHOLD
    elif brightness > BRIGHT_BRIGHTNESS_THRESHOLD:
        exposure = (255.0 - brightness) / (255.0 - BRIGHT_BRIGHTNESS_THRESHOLD)
    else:
        exposure = 1.0
    quality_score = round(max(0.0, min(1.0, 0.6 * sharpness + 0.4 * exposure)), 4)

    return ImageQuality(
        width=image.width,
        height=image.height,
        blur_score=round(blur_score, 2),
        brightness=round(brightness, 2),
        is_blurry=is_blurry,
        is_too_dark=is_too_dark,
        is_too_bright=is_too_bright,
        quality_score=quality_score,
    )


def difference_hash(image: Image.Image, hash_size: int = 8) -> str:
    """64-bit perceptual difference hash (dHash) as 16 hex chars."""
    grey = image.convert("L").resize((hash_size + 1, hash_size), Image.LANCZOS)
    pixels = np.asarray(grey, dtype=np.int16)
    bits = (pixels[:, 1:] > pixels[:, :-1]).flatten()
    value = 0
    for bit in bits:
        value = (value << 1) | int(bit)
    return f"{value:0{hash_size * hash_size // 4}x}"
