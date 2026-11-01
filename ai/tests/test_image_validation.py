import io

import pytest
from PIL import Image

from app.core.exceptions import ImageTooLargeError, InvalidImageError, UnsupportedMediaTypeError
from app.utils.image import assess_quality, difference_hash, sniff_mime_type, validate_and_load_image
from app.utils.similarity import hash_similarity
from tests.conftest import image_bytes, make_image

LIMITS = {"max_bytes": 5 * 1024 * 1024, "min_dimension": 64, "max_pixels": 40_000_000}


@pytest.mark.parametrize("fmt,mime", [("JPEG", "image/jpeg"), ("PNG", "image/png"), ("WEBP", "image/webp")])
def test_sniff_and_load_supported_formats(fmt, mime):
    data = image_bytes(fmt)
    assert sniff_mime_type(data) == mime
    loaded = validate_and_load_image(data, mime, **LIMITS)
    assert loaded.mime_type == mime
    assert loaded.image.mode == "RGB"
    assert (loaded.width, loaded.height) == (320, 240)


def test_declared_mime_alias_and_octet_stream_accepted():
    data = image_bytes("JPEG")
    validate_and_load_image(data, "image/jpg", **LIMITS)
    validate_and_load_image(data, "application/octet-stream", **LIMITS)
    validate_and_load_image(data, None, **LIMITS)


def test_empty_file_rejected():
    with pytest.raises(InvalidImageError):
        validate_and_load_image(b"", "image/jpeg", **LIMITS)


def test_size_limit():
    data = image_bytes("PNG", size=(500, 500))
    with pytest.raises(ImageTooLargeError):
        validate_and_load_image(data, "image/png", max_bytes=len(data) - 1, min_dimension=64, max_pixels=10**8)


def test_gif_rejected():
    buffer = io.BytesIO()
    make_image().save(buffer, "GIF")
    with pytest.raises(UnsupportedMediaTypeError):
        validate_and_load_image(buffer.getvalue(), "image/gif", **LIMITS)
    with pytest.raises(UnsupportedMediaTypeError):
        validate_and_load_image(buffer.getvalue(), "image/jpeg", **LIMITS)


def test_truncated_image_rejected():
    data = image_bytes("JPEG")[:200]
    with pytest.raises(InvalidImageError):
        validate_and_load_image(data, "image/jpeg", **LIMITS)


def test_too_small_image_rejected():
    with pytest.raises(InvalidImageError):
        validate_and_load_image(image_bytes("PNG", size=(32, 32)), "image/png", **LIMITS)


def test_pixel_limit():
    with pytest.raises(InvalidImageError):
        validate_and_load_image(image_bytes("PNG", size=(300, 300)), "image/png",
                                max_bytes=10**8, min_dimension=1, max_pixels=1000)


def test_exif_metadata_is_stripped():
    img = make_image()
    exif = Image.Exif()
    exif[0x010F] = "SecretCameraMaker"  # Make
    buffer = io.BytesIO()
    img.save(buffer, "JPEG", exif=exif.tobytes())
    loaded = validate_and_load_image(buffer.getvalue(), "image/jpeg", **LIMITS)
    assert not loaded.image.getexif()
    assert not loaded.image.info.get("exif")


def test_quality_flags_blurry_and_dark():
    sharp = assess_quality(make_image(pattern="noise"))
    flat = assess_quality(make_image(pattern="flat"))
    dark = assess_quality(make_image(pattern="dark"))
    assert not sharp.is_blurry and sharp.quality_score > 0.9
    assert flat.is_blurry
    assert dark.is_too_dark
    assert dark.quality_score < sharp.quality_score


def test_difference_hash_stable_and_discriminative():
    a = make_image(pattern="shapes")
    same = difference_hash(a) == difference_hash(a.resize((640, 480)))
    assert same or hash_similarity(difference_hash(a), difference_hash(a.resize((640, 480)))) > 0.9
    assert hash_similarity(difference_hash(a), difference_hash(make_image(seed=5))) < 0.8
