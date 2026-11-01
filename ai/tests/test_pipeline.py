from datetime import datetime, timedelta, timezone

import pytest

from app.schemas.analysis import (
    CategoryResult,
    ComponentStatus,
    ConsistencyResult,
    ImageAnalysis,
    SpamResult,
)
from app.schemas.report import CandidateReport
from app.services.duplicate_service import LOCATION_ONLY_CAP, NullCandidateProvider, RequestCandidateProvider
from app.services.validity_scorer import COMPONENT_WEIGHTS, ValidityInputs, WeightedValidityScorer
from tests.conftest import (
    FailingImageModel,
    StubEmbeddingModel,
    StubImageModel,
    StubSpamModel,
    StubTextModel,
    build_pipeline,
    loaded_image,
    make_report,
    make_settings,
)

POTHOLE = {"pothole": 0.9, "road_damage": 0.08, "garbage": 0.02}
GARBAGE = {"garbage": 0.92, "pothole": 0.05, "road_damage": 0.03}
STREET_LIGHT = {"broken_street_light": 0.95, "garbage": 0.05}


def test_pipeline_without_models():
    result = build_pipeline().analyze(make_report(), loaded_image())
    assert result.category.status == ComponentStatus.MODEL_NOT_LOADED
    assert result.consistency.status == ComponentStatus.MODEL_NOT_LOADED
    assert result.image.status == ComponentStatus.MODEL_NOT_LOADED
    assert result.text.status == ComponentStatus.MODEL_NOT_LOADED
    assert result.spam.status == ComponentStatus.HEURISTIC
    assert result.duplicate.status == ComponentStatus.INSUFFICIENT_DATA
    assert result.validity.status == ComponentStatus.HEURISTIC
    assert set(result.validity.components) == {"not_spam", "image_quality"}
    assert result.validity.coverage == pytest.approx(0.55)
    assert result.validity_score == result.validity.score
    assert result.meta.uses_development_fallbacks is True


def test_pipeline_without_models_or_heuristics():
    settings = make_settings(enable_heuristic_fallbacks=False)
    result = build_pipeline(settings).analyze(make_report(), loaded_image())
    for block in (result.spam, result.severity, result.duplicate, result.validity, result.category):
        assert block.status == ComponentStatus.MODEL_NOT_LOADED
    assert result.validity_score is None
    assert result.meta.uses_development_fallbacks is False
    assert result.requires_human_review is False


def test_pipeline_with_models_matching_image_and_text():
    pipeline = build_pipeline(
        image=StubImageModel(POTHOLE),
        text=StubTextModel(POTHOLE),
        severity=StubTextModel({"high": 0.8, "medium": 0.15, "low": 0.05}, role="severity"),
        spam=StubSpamModel(0.03),
    )
    result = pipeline.analyze(make_report(), loaded_image())
    assert result.category.status == ComponentStatus.OK
    assert result.category.label == "pothole"
    assert result.category.source == "image+text"
    assert result.consistency.status == ComponentStatus.OK
    assert result.consistency.method == "label_distribution_overlap"
    assert result.consistency.score > 0.95 and result.consistency.is_consistent
    assert result.severity.status == ComponentStatus.OK and result.severity.label == "high"
    assert result.spam.status == ComponentStatus.OK and not result.spam.is_suspicious
    assert set(result.validity.components) == set(COMPONENT_WEIGHTS)
    assert result.validity.coverage == pytest.approx(1.0)
    assert result.requires_human_review is False


def test_pipeline_flags_image_text_mismatch():
    pipeline = build_pipeline(image=StubImageModel(STREET_LIGHT), text=StubTextModel(GARBAGE))
    result = pipeline.analyze(make_report(description="Garbage is being dumped beside the road"), loaded_image())
    assert result.consistency.score < 0.5
    assert result.consistency.is_consistent is False
    assert "image_text_mismatch" in result.review_reasons
    assert result.requires_human_review is True


def test_zero_shot_consistency_with_embeddings_only():
    pipeline = build_pipeline(image=StubImageModel(POTHOLE), embedding=StubEmbeddingModel())
    result = pipeline.analyze(make_report(description="a pothole in the road"), loaded_image())
    assert result.consistency.status == ComponentStatus.OK
    assert result.consistency.method == "zero_shot_embedding"
    assert result.text.has_embedding is True
    assert result.category.source == "image"


def test_detector_with_no_detections():
    result = build_pipeline(image=StubImageModel({}, detections=0)).analyze(make_report(), loaded_image())
    assert result.consistency.status == ComponentStatus.INSUFFICIENT_DATA
    assert result.category.status == ComponentStatus.INSUFFICIENT_DATA


def test_failing_component_is_isolated():
    result = build_pipeline(image=FailingImageModel()).analyze(make_report(), loaded_image())
    assert result.image.status == ComponentStatus.ERROR
    assert result.image.quality is not None  # deterministic parts still computed
    assert result.spam.status == ComponentStatus.HEURISTIC


def test_severity_model_with_unknown_label_is_error():
    result = build_pipeline(severity=StubTextModel({"critical": 1.0}, role="severity")).analyze(
        make_report(), loaded_image()
    )
    assert result.severity.status == ComponentStatus.ERROR


@pytest.mark.parametrize(
    "text,label",
    [
        ("Dangerous open manhole, a child was injured", "high"),
        ("Large pothole on the road", "medium"),
        ("Small crack in the pavement", "low"),
    ],
)
def test_severity_heuristic_english(text, label):
    result = build_pipeline().analyze(make_report(description=text), loaded_image())
    assert result.severity.status == ComponentStatus.HEURISTIC
    assert result.severity.label == label
    assert result.severity.confidence is None


def test_severity_heuristic_declines_non_english():
    result = build_pipeline().analyze(make_report(description="පාසල අසල විශාල වළක් ඇත"), loaded_image())
    assert result.text.language == "si"
    assert result.severity.status == ComponentStatus.MODEL_NOT_LOADED


# --- duplicate detection ----------------------------------------------------------


def _dup(pipeline, report, candidates, image_hash=None):
    text = pipeline.text_service.prepare(report.description)
    return pipeline.duplicate_service.analyze(report, text, image_hash, RequestCandidateProvider(candidates))


def test_duplicate_location_only_is_capped():
    pipeline = build_pipeline()
    cand = CandidateReport(report_id="r-2", latitude=6.9271, longitude=79.8612)
    result = _dup(pipeline, make_report(), [cand])
    assert result.top_matches[0].probability == LOCATION_ONLY_CAP
    assert result.is_duplicate is False


def test_duplicate_uses_image_hash_and_time():
    pipeline = build_pipeline()
    now = datetime.now(timezone.utc)
    report = make_report(created_at=now)
    cand = CandidateReport(
        report_id="r-2", latitude=6.9271, longitude=79.8612, description="Large pothole near the school",
        image_hash="0123456789abcdef", created_at=now - timedelta(hours=2),
    )
    result = _dup(pipeline, report, [cand], image_hash="0123456789abcdef")
    match = result.top_matches[0]
    assert match.image_similarity == 1.0 and match.hours_apart == pytest.approx(2.0, abs=0.01)
    assert result.is_duplicate is True and result.matched_report_id == "r-2"


def test_duplicate_excludes_self_and_far_candidates():
    pipeline = build_pipeline()
    report = make_report()
    cands = [
        CandidateReport(report_id=report.report_id, latitude=6.9271, longitude=79.8612, description="x"),
        CandidateReport(report_id="far", latitude=7.29, longitude=80.63, description=report.description),
    ]
    result = _dup(pipeline, report, cands)
    assert result.candidates_evaluated == 1
    assert result.top_matches == [] and result.probability == 0.0 and result.is_duplicate is False


def test_null_candidate_provider():
    assert NullCandidateProvider().get_candidates(make_report()) == []


# --- validity scorer ----------------------------------------------------------------


def _inputs(spam=0.1, consistency=0.9, category=0.8, quality_image=None):
    return ValidityInputs(
        spam=SpamResult(status=ComponentStatus.OK, probability=spam, threshold=0.7),
        consistency=ConsistencyResult(status=ComponentStatus.OK, score=consistency, threshold=0.7),
        category=CategoryResult(status=ComponentStatus.OK, confidence=category),
        image=quality_image or ImageAnalysis(status=ComponentStatus.MODEL_NOT_LOADED),
    )


def test_validity_documented_weights():
    result = WeightedValidityScorer().score(_inputs())
    expected = (0.40 * 0.9 + 0.30 * 0.9 + 0.15 * 0.8) / 0.85
    assert result.score == pytest.approx(expected, abs=1e-4)
    assert result.coverage == pytest.approx(0.85)
    assert result.status == ComponentStatus.HEURISTIC


def test_validity_ignores_unavailable_components():
    inputs = _inputs()
    inputs.consistency = ConsistencyResult(status=ComponentStatus.MODEL_NOT_LOADED, threshold=0.7)
    result = WeightedValidityScorer().score(inputs)
    assert "consistency" not in result.components


def test_validity_no_components():
    inputs = ValidityInputs(
        spam=SpamResult(status=ComponentStatus.MODEL_NOT_LOADED, threshold=0.7),
        consistency=ConsistencyResult(status=ComponentStatus.MODEL_NOT_LOADED, threshold=0.7),
        category=CategoryResult(status=ComponentStatus.MODEL_NOT_LOADED),
        image=ImageAnalysis(status=ComponentStatus.MODEL_NOT_LOADED),
    )
    result = WeightedValidityScorer().score(inputs)
    assert result.status == ComponentStatus.INSUFFICIENT_DATA and result.score is None


def test_validity_rejects_bad_weights():
    with pytest.raises(ValueError):
        WeightedValidityScorer(weights={"not_spam": -1})


def test_low_validity_requests_review():
    pipeline = build_pipeline(spam=StubSpamModel(0.95))
    result = pipeline.analyze(make_report(), loaded_image(pattern="dark"))
    assert "possible_spam" in result.review_reasons
    assert "low_validity_score" in result.review_reasons
