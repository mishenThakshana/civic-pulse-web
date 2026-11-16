import pytest

from app.models.spam_model import SPAM_FEATURE_COLUMNS, SPAM_TEXT_COLUMN, SklearnSpamModel
from app.schemas.analysis import ComponentStatus
from app.services.spam_service import PRIOR, RULE_WEIGHTS, SpamService
from tests.conftest import StubSpamModel, make_report, make_settings


def service(probability=None, **settings_overrides):
    settings = make_settings(**settings_overrides)
    model = StubSpamModel(probability) if probability is not None else SklearnSpamModel(None)
    model.load()
    return SpamService(model, settings)


def test_features_cover_declared_columns():
    feats = service().extract_features("Pothole on road", make_report(), None)
    assert set(SPAM_FEATURE_COLUMNS) | {SPAM_TEXT_COLUMN} == set(feats)


def test_clean_report_scores_prior_only():
    result = service().analyze("Large pothole near the school on the main road", make_report(), None)
    assert result.status == ComponentStatus.HEURISTIC
    assert result.probability == pytest.approx(PRIOR)
    assert result.is_suspicious is False
    assert result.reasons == []


def test_promotional_spam_is_flagged():
    text = "Buy now! Best price discount offer, click here www.cheap-deals.example call now 0771234567"
    result = service().analyze(text, make_report(), None)
    assert result.is_suspicious is True
    assert {"contains_url", "contains_contact_details", "promotional_keyword"} <= set(result.reasons)


def test_nonsense_and_location_signals():
    result = service().analyze("!!!!!!!!", make_report(latitude=0.0, longitude=0.0), None)
    assert "null_island_coordinates" in result.reasons
    assert "mostly_non_letter_characters" in result.reasons
    abroad = service().analyze("Pothole near the station", make_report(latitude=51.5, longitude=-0.12), None)
    assert abroad.reasons == ["outside_service_area"]


def test_noisy_or_combination():
    result = service().analyze("see https://x.example for pothole photos", make_report(), None)
    expected = 1 - (1 - PRIOR) * (1 - RULE_WEIGHTS["contains_url"])
    assert result.probability == pytest.approx(expected, abs=1e-4)


@pytest.mark.parametrize("threshold,expected", [(0.10, True), (0.50, True), (0.51, False), (0.90, False)])
def test_spam_threshold_applied(threshold, expected):
    result = service(probability=0.50, spam_threshold=threshold).analyze("text here ok", make_report(), None)
    assert result.status == ComponentStatus.OK
    assert result.threshold == threshold
    assert result.is_suspicious is expected


def test_default_threshold_is_070():
    assert make_settings().spam_threshold == 0.70


def test_trained_model_receives_features():
    svc = service(probability=0.9)
    result = svc.analyze("Pothole", make_report(), None)
    assert result.probability == 0.9 and result.is_suspicious
    assert svc.model.last_features["text_length"] == len("Pothole")


def test_heuristics_disabled_reports_model_not_loaded():
    result = service(enable_heuristic_fallbacks=False).analyze("anything", make_report(), None)
    assert result.status == ComponentStatus.MODEL_NOT_LOADED
    assert result.probability is None and result.is_suspicious is None


def test_invalid_threshold_rejected():
    with pytest.raises(ValueError):
        make_settings(spam_threshold=1.5)


SINHALA_REPORT = "පාරේ ලොකු වළක් තියෙනවා, බයිසිකල් පදින අයට අනතුරුදායකයි"


def test_unsupported_language_does_not_use_the_english_spam_model():
    result = service(probability=0.95).analyze(SINHALA_REPORT, make_report(), None, language="si")
    assert result.status == ComponentStatus.HEURISTIC
    assert "spam model not used for 'si'" in result.method
    assert result.is_suspicious is False
    assert result.probability == pytest.approx(PRIOR)


def test_unsupported_language_spam_is_still_caught_by_the_rules():
    text = SINHALA_REPORT + " www.cheap-deals.example call now 0771234567 best price"
    result = service(probability=0.01).analyze(text, make_report(), None, language="si")
    assert result.is_suspicious is True
    assert {"contains_url", "contains_contact_details", "promotional_keyword"} <= set(result.reasons)


def test_model_language_still_uses_the_trained_spam_model():
    result = service(probability=0.95).analyze("Win a free phone, click now", make_report(), None, language="en")
    assert result.status == ComponentStatus.OK
    assert result.is_suspicious is True
