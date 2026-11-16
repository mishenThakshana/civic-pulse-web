"""Model-not-loaded behaviour: missing or broken models never crash the service."""

import pytest
from fastapi.testclient import TestClient

from app.core.exceptions import ModelNotLoadedError
from app.main import create_app
from app.models.embedding_model import SentenceTransformerEmbeddingModel
from app.models.image_model import YoloImageModel
from app.models.registry import ModelRegistry
from app.models.spam_model import SklearnSpamModel
from app.models.text_model import TransformersTextClassifier
from tests.conftest import make_image, make_settings, valid_form, image_bytes


def test_unconfigured_models_are_not_ready_and_refuse_predictions():
    models = [
        YoloImageModel(None),
        TransformersTextClassifier(None),
        SentenceTransformerEmbeddingModel(None),
        SklearnSpamModel(None),
    ]
    for model in models:
        model.load()
        assert model.is_ready() is False
        assert model.status().error == "not configured"
    with pytest.raises(ModelNotLoadedError):
        models[0].predict(make_image())
    with pytest.raises(ModelNotLoadedError):
        models[1].predict("text")
    with pytest.raises(ModelNotLoadedError):
        models[2].encode(["text"])
    with pytest.raises(ModelNotLoadedError):
        models[3].predict({})


def test_missing_weight_files_are_recorded_not_raised(tmp_path):
    image = YoloImageModel(tmp_path / "missing.pt")
    spam = SklearnSpamModel(tmp_path / "missing.joblib")
    image.load()
    spam.load()
    assert not image.is_ready() and "FileNotFoundError" in image.status().error
    assert not spam.is_ready() and "FileNotFoundError" in spam.status().error


def test_corrupt_weight_file_is_recorded_not_raised(tmp_path):
    bad = tmp_path / "bad.joblib"
    bad.write_bytes(b"not a model")
    spam = SklearnSpamModel(bad)
    spam.load()
    assert not spam.is_ready() and spam.status().error


def test_hf_model_not_downloaded_when_download_disallowed():
    model = TransformersTextClassifier("civi-pulse/does-not-exist-xyz", allow_download=False)
    model.load()  # local_files_only=True -> fails fast offline, no download
    assert not model.is_ready()
    assert model.status().error


def test_registry_statuses():
    registry = ModelRegistry.from_settings(make_settings())
    registry.load_all()
    statuses = registry.statuses()
    assert set(statuses) == {"image", "text", "severity", "embedding", "spam", "multimodal"}
    assert not any(s.ready for s in statuses.values())


def test_api_starts_and_analyzes_with_broken_model_config(tmp_path):
    settings = make_settings(
        image_model_path=str(tmp_path / "nope.pt"),
        spam_model_path=str(tmp_path / "nope.joblib"),
        text_model_name="civi-pulse/does-not-exist-xyz",
    )
    with TestClient(create_app(settings=settings)) as client:
        assert client.get("/api/v1/health").json()["status"] == "ok"
        models = client.get("/api/v1/models").json()["models"]
        assert models["image"]["ready"] is False and models["image"]["error"]
        response = client.post(
            "/api/v1/analyze", data=valid_form(), files={"image": ("a.jpg", image_bytes(), "image/jpeg")}
        )
        assert response.status_code == 200
        body = response.json()
        assert body["category"]["status"] == "model_not_loaded"
        assert body["spam"]["status"] == "heuristic"  # falls back because the model file is missing


def test_relative_model_paths_resolve_under_ai_root():
    from app.core.config import AI_ROOT

    settings = make_settings(image_model_path="models/image/x.pt")
    assert settings.image_model_path == AI_ROOT / "models/image/x.pt"
    assert make_settings(image_model_path="").image_model_path is None
