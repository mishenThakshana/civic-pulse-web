import json

import pytest

from app.models.multimodal_model import GeminiMultimodalModel, MultimodalAssessment, parse_assessment
from app.schemas.analysis import ComponentStatus
from tests.conftest import StubMultimodalModel, build_pipeline, loaded_image, make_report

GENUINE = MultimodalAssessment(
    category="pothole",
    category_confidence=0.93,
    image_shows_issue=True,
    spam_probability=0.04,
    spam_reasons=[],
    severity="high",
    severity_confidence=0.8,
    consistency_score=0.9,
    summary="Deep pothole on a main road.",
)
SPAM = MultimodalAssessment(
    category="other",
    category_confidence=0.2,
    image_shows_issue=False,
    spam_probability=0.97,
    spam_reasons=["advertisement", "unrelated_image"],
    severity="low",
    severity_confidence=0.5,
    consistency_score=0.1,
    summary="Promotional post, no infrastructure issue.",
)


def _gemini_payload(data: dict) -> dict:
    return {"candidates": [{"content": {"parts": [{"text": json.dumps(data)}]}}]}


def test_multimodal_assessment_drives_the_pipeline():
    model = StubMultimodalModel(GENUINE)
    result = build_pipeline(multimodal=model).analyze(make_report(), loaded_image())

    assert model.calls == 1
    assert result.category.status == ComponentStatus.OK
    assert result.category.label == "pothole"
    assert result.spam.status == ComponentStatus.OK
    assert result.spam.is_suspicious is False
    assert result.severity.label == "high"
    assert result.consistency.is_consistent is True
    assert result.image.quality is not None
    assert result.image.perceptual_hash
    assert result.requires_human_review is False


def test_multimodal_spam_is_flagged_with_reasons():
    result = build_pipeline(multimodal=StubMultimodalModel(SPAM)).analyze(make_report(), loaded_image())

    assert result.spam.is_suspicious is True
    assert result.spam.reasons == ["advertisement", "unrelated_image"]
    assert "possible_spam" in result.review_reasons
    assert "image_text_mismatch" in result.review_reasons


def test_multimodal_failure_falls_back_to_component_models():
    result = build_pipeline(multimodal=StubMultimodalModel(None)).analyze(make_report(), loaded_image())

    assert result.spam.status == ComponentStatus.HEURISTIC
    assert result.category.status == ComponentStatus.MODEL_NOT_LOADED


def test_sinhala_reports_are_screened_by_the_main_model():
    model = StubMultimodalModel(GENUINE)
    report = make_report(description="නුගේගොඩ හයිලෙවල් පාරේ ලොකු වළක් තියෙනවා. යතුරුපැදිකරුවන්ට අනතුරු වෙන්න පුළුවන්.")
    result = build_pipeline(multimodal=model).analyze(report, loaded_image())

    assert model.calls == 1
    assert result.category.label == "pothole"
    assert result.spam.status == ComponentStatus.OK


def test_gemini_model_without_api_key_is_not_configured():
    model = GeminiMultimodalModel(None, "gemini-2.5-flash")
    model.load()
    assert model.is_ready() is False
    assert model.status().error == "not configured"


def test_parse_assessment_normalises_values():
    parsed = parse_assessment(_gemini_payload({
        "category": "Broken Street Light",
        "category_confidence": 1.7,
        "image_shows_issue": True,
        "spam_probability": -0.2,
        "spam_reasons": ["Contains Scam Link", " "],
        "severity": "CRITICAL",
        "severity_confidence": 0.6,
        "consistency_score": 0.75,
        "summary": "ok",
    }))

    assert parsed.category == "broken_street_light"
    assert parsed.category_confidence == 1.0
    assert parsed.spam_probability == 0.0
    assert parsed.spam_reasons == ["contains_scam_link"]
    assert parsed.severity == "medium"


def test_parse_assessment_rejects_malformed_responses():
    with pytest.raises(ValueError):
        parse_assessment({"candidates": []})
    with pytest.raises(ValueError):
        parse_assessment({"candidates": [{"content": {"parts": [{"text": "not json"}]}}]})


def _gemini_ok() -> dict:
    return _gemini_payload({
        "category": "pothole", "category_confidence": 0.9, "image_shows_issue": True, "spam_probability": 0.01,
        "spam_reasons": [], "severity": "high", "severity_confidence": 0.8, "consistency_score": 0.9, "summary": "",
    })


def _model_with_responses(*responses):
    import httpx

    calls: list[str] = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(request.url.path)
        return responses[len(calls) - 1]

    model = GeminiMultimodalModel("key", "primary-model", fallback_model_name="fallback-model")
    model.load()
    model._client = httpx.Client(transport=httpx.MockTransport(handler))
    return model, calls


def test_gemini_falls_back_when_primary_model_is_overloaded():
    import httpx

    model, calls = _model_with_responses(httpx.Response(503, json={}), httpx.Response(200, json=_gemini_ok()))
    result = model.assess(loaded_image().image, "pothole")

    assert result.category == "pothole"
    assert [c.split("/")[-1] for c in calls] == ["primary-model:generateContent", "fallback-model:generateContent"]


def test_gemini_raises_when_every_model_fails():
    import httpx

    model, _ = _model_with_responses(httpx.Response(429, json={}), httpx.Response(503, json={}))
    with pytest.raises(httpx.HTTPStatusError):
        model.assess(loaded_image().image, "pothole")
