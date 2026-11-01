import pytest
from pydantic import ValidationError

from app.schemas.analysis import AnalysisResponse, ComponentStatus, HealthResponse
from tests.conftest import build_pipeline, loaded_image, make_report

REQUIRED_TOP_LEVEL = {"report_id", "category", "spam", "severity", "consistency", "duplicate", "validity_score"}


def test_response_contains_contract_fields(client):
    from tests.test_api_analyze import post

    body = post(client).json()
    assert REQUIRED_TOP_LEVEL <= set(body)
    assert {"label", "confidence", "status"} <= set(body["category"])
    assert {"probability", "is_suspicious", "status"} <= set(body["spam"])
    assert {"label", "confidence", "status"} <= set(body["severity"])
    assert {"score", "is_consistent", "status"} <= set(body["consistency"])
    assert {"probability", "is_duplicate", "status"} <= set(body["duplicate"])
    AnalysisResponse.model_validate(body)  # round-trips through the schema


def test_every_block_status_is_known_value(client):
    from tests.test_api_analyze import post

    body = post(client).json()
    allowed = {s.value for s in ComponentStatus}
    for key in ("category", "spam", "severity", "consistency", "duplicate", "validity", "image", "text"):
        assert body[key]["status"] in allowed


def test_model_serialization_roundtrip():
    result = build_pipeline().analyze(make_report(), loaded_image())
    again = AnalysisResponse.model_validate_json(result.model_dump_json())
    assert again == result


def test_probability_bounds_enforced():
    from app.schemas.analysis import SpamResult

    with pytest.raises(ValidationError):
        SpamResult(status=ComponentStatus.OK, probability=1.2, threshold=0.7)


def test_health_schema():
    assert HealthResponse(status="ok", service="civi-pulse-ai").model_dump() == {
        "status": "ok",
        "service": "civi-pulse-ai",
    }


def test_openapi_documents_analyze_response(client):
    schema = client.get("/openapi.json").json()
    ok = schema["paths"]["/api/v1/analyze"]["post"]["responses"]["200"]
    assert ok["content"]["application/json"]["schema"]["$ref"].endswith("AnalysisResponse")
