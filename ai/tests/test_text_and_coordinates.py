import pytest
from pydantic import ValidationError

from app.core.exceptions import InputValidationError
from app.schemas.report import CandidateReport, IssueCategory, ReportInput, validate_coordinates
from app.services.text_service import TextService
from app.utils.text import (
    contains_phone_number,
    contains_url,
    detect_language,
    letter_ratio,
    normalize_text,
    unique_token_ratio,
)
from tests.conftest import build_registry, make_settings

# --- text -----------------------------------------------------------------------

SINHALA = "පාසල අසල විශාල වළක් ඇත"
TAMIL = "பள்ளிக்கு அருகில் பெரிய குழி உள்ளது"


def test_normalize_collapses_whitespace_and_removes_invisible():
    assert normalize_text("  Large​  pothole\n\n here﻿ ") == "Large pothole here"


def test_normalize_preserves_sinhala_zwj():
    sri = "ශ්‍රී"  # conjunct requires ZERO WIDTH JOINER
    assert "‍" in normalize_text(sri)


def test_normalize_is_nfc():
    decomposed = "é"
    assert normalize_text(decomposed) == "é"


@pytest.mark.parametrize(
    "text,lang",
    [
        ("Large pothole near the school", "en"),
        (SINHALA, "si"),
        (TAMIL, "ta"),
        ("pothole near school පාසල අසල වළක්", "mixed"),
        ("12345 !!!", "unknown"),
        ("road eke loku wala ekak thiyenawa", "si-latn"),
        ("The API for the street light is down", "en"),
    ],
)
def test_detect_language(text, lang):
    assert detect_language(text) == lang


def test_text_feature_helpers():
    assert contains_url("see www.example.com now")
    assert contains_url("https://spam.example")
    assert not contains_url("pothole on main road")
    assert contains_phone_number("call 077 123 4567")
    assert contains_phone_number("+94771234567")
    assert letter_ratio("!!!???") == 0.0
    assert letter_ratio(SINHALA) > 0.8  # Sinhala vowel signs count as letters
    assert unique_token_ratio("spam spam spam spam") == 0.25


def test_text_service_prepare_validates():
    settings = make_settings(max_description_length=20)
    service = TextService(build_registry(settings).text, build_registry(settings).embedding, settings)
    assert service.prepare("  hello  world ") == "hello world"
    with pytest.raises(InputValidationError):
        service.prepare("   ")
    with pytest.raises(InputValidationError):
        service.prepare(None)
    with pytest.raises(InputValidationError):
        service.prepare("x" * 21)


def test_text_service_language_hint_only_when_undetectable():
    settings = make_settings()
    reg = build_registry(settings)
    service = TextService(reg.text, reg.embedding, settings)
    analysis, embedding = service.analyze("12345", language_hint="si")
    assert analysis.language == "si"
    analysis, _ = service.analyze("Broken street light", language_hint="ta")
    assert analysis.language == "en"
    assert analysis.status == "model_not_loaded"
    assert embedding is None


def test_issue_category_normalize():
    assert IssueCategory.normalize("Broken Street Light") == IssueCategory.BROKEN_STREET_LIGHT
    assert IssueCategory.normalize("pot-hole") == IssueCategory.OTHER
    assert IssueCategory.normalize("blocked-drain") == IssueCategory.BLOCKED_DRAIN


# --- coordinates --------------------------------------------------------------


@pytest.mark.parametrize("lat,lon", [(0, 0), (90, 180), (-90, -180), (6.9271, 79.8612)])
def test_valid_coordinates(lat, lon):
    point = validate_coordinates(lat, lon)
    assert point.latitude == lat and point.longitude == lon


@pytest.mark.parametrize(
    "lat,lon", [(90.0001, 0), (-91, 0), (0, 180.5), (0, -181), (float("nan"), 0), (0, float("inf"))]
)
def test_invalid_coordinates(lat, lon):
    with pytest.raises(ValueError):
        validate_coordinates(lat, lon)
    with pytest.raises(ValidationError):
        ReportInput(report_id="r-1", description="x", latitude=lat, longitude=lon)
    with pytest.raises(ValidationError):
        CandidateReport(report_id="r-1", latitude=lat, longitude=lon)


def test_candidate_image_hash_format():
    CandidateReport(report_id="r-1", latitude=1, longitude=1, image_hash="0123456789abcdef")
    with pytest.raises(ValidationError):
        CandidateReport(report_id="r-1", latitude=1, longitude=1, image_hash="xyz")
