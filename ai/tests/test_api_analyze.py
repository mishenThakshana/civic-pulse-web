import json

import pytest

from tests.conftest import image_bytes, valid_form


def post(client, *, image=None, filename="photo.jpg", content_type="image/jpeg", **form_overrides):
    data = valid_form(**form_overrides)
    data = {k: v for k, v in data.items() if v is not None}
    files = {"image": (filename, image if image is not None else image_bytes(), content_type)}
    return client.post("/api/v1/analyze", data=data, files=files)


def test_analyze_success_without_models(client):
    response = post(client)
    assert response.status_code == 200
    body = response.json()
    assert body["report_id"] == "report-123"
    # Trained models are absent: model-only components say so explicitly.
    assert body["category"]["status"] == "model_not_loaded"
    assert body["category"]["label"] is None
    assert body["consistency"]["status"] == "model_not_loaded"
    assert body["consistency"]["score"] is None
    # Heuristic components are labelled as such, never as "ok".
    assert body["spam"]["status"] == "heuristic"
    assert "not a trained model" in body["spam"]["method"]
    assert body["meta"]["uses_development_fallbacks"] is True
    assert body["image"]["quality"]["width"] == 320
    assert len(body["image"]["perceptual_hash"]) == 16


@pytest.mark.parametrize("fmt,mime", [("PNG", "image/png"), ("WEBP", "image/webp")])
def test_analyze_accepts_png_and_webp(client, fmt, mime):
    response = post(client, image=image_bytes(fmt), content_type=mime)
    assert response.status_code == 200


@pytest.mark.parametrize("missing", ["description", "latitude", "longitude", "report_id"])
def test_missing_required_field(client, missing):
    response = post(client, **{missing: None})
    assert response.status_code == 422
    assert response.json()["error"]["code"] == "invalid_input"


def test_missing_image(client):
    response = client.post("/api/v1/analyze", data=valid_form())
    assert response.status_code == 422


@pytest.mark.parametrize(
    "lat,lon", [("91", "79.8"), ("-90.5", "79.8"), ("6.9", "181"), ("6.9", "-180.01"), ("nan", "79.8"), ("abc", "1")]
)
def test_invalid_coordinates_rejected(client, lat, lon):
    response = post(client, latitude=lat, longitude=lon)
    assert response.status_code == 422


@pytest.mark.parametrize("description", ["", "   ", "​​"])
def test_empty_description_rejected(client, description):
    response = post(client, description=description)
    assert response.status_code == 422


def test_too_long_description_rejected(client):
    response = post(client, description="a" * 6000)
    assert response.status_code == 422


@pytest.mark.parametrize("report_id", ["", "has space", "../etc", "x" * 200])
def test_invalid_report_id_rejected(client, report_id):
    response = post(client, report_id=report_id)
    assert response.status_code == 422


def test_unsupported_declared_mime(client):
    response = post(client, filename="a.gif", content_type="image/gif")
    assert response.status_code == 415
    assert response.json()["error"]["code"] == "unsupported_media_type"


def test_non_image_content_rejected(client):
    response = post(client, image=b"%PDF-1.4 not an image", filename="a.jpg")
    assert response.status_code == 415


def test_mime_mismatch_rejected(client):
    response = post(client, image=image_bytes("PNG"), content_type="image/jpeg")
    assert response.status_code == 422
    assert response.json()["error"]["code"] == "invalid_image"


def test_oversized_image_rejected(settings):
    from fastapi.testclient import TestClient

    from app.main import create_app
    from tests.conftest import make_settings

    small = make_settings(max_image_size_mb=0.01)  # ~10 KB
    with TestClient(create_app(settings=small)) as client:
        response = post(client, image=image_bytes(size=(400, 400)))
    assert response.status_code == 413
    assert response.json()["error"]["code"] == "image_too_large"


def test_invalid_candidates_json(client):
    assert post(client, candidates="not json").status_code == 422
    assert post(client, candidates='{"a": 1}').status_code == 422
    bad = json.dumps([{"report_id": "r-1", "latitude": 100, "longitude": 1}])
    assert post(client, candidates=bad).status_code == 422


def test_duplicate_candidates_are_compared(client):
    candidates = [
        {"report_id": "r-near", "description": "Large pothole near the school on Galle Road",
         "latitude": 6.92711, "longitude": 79.86121},
        {"report_id": "r-far", "description": "Large pothole near the school on Galle Road",
         "latitude": 7.2906, "longitude": 80.6337},
    ]
    body = post(client, candidates=json.dumps(candidates)).json()
    dup = body["duplicate"]
    assert dup["status"] == "heuristic"
    assert dup["candidates_evaluated"] == 2
    assert [m["report_id"] for m in dup["top_matches"]] == ["r-near"]  # far one outside radius
    assert dup["is_duplicate"] is True
    assert dup["matched_report_id"] == "r-near"
    assert "possible_duplicate" in body["review_reasons"]


def test_no_candidates_means_insufficient_data(client):
    dup = post(client).json()["duplicate"]
    assert dup["status"] == "insufficient_data"
    assert dup["probability"] is None
