def test_health_returns_ok(client):
    response = client.get("/api/v1/health")
    assert response.status_code == 200
    assert response.json() == {"status": "ok", "service": "civi-pulse-ai"}


def test_models_endpoint_reports_nothing_loaded(client):
    response = client.get("/api/v1/models")
    assert response.status_code == 200
    body = response.json()
    assert set(body["models"]) == {"image", "text", "severity", "embedding", "spam"}
    assert all(m["ready"] is False for m in body["models"].values())
    assert all(m["error"] == "not configured" for m in body["models"].values())


def test_docs_and_openapi_available(client):
    assert client.get("/docs").status_code == 200
    schema = client.get("/openapi.json").json()
    assert "/api/v1/health" in schema["paths"]
    assert "/api/v1/analyze" in schema["paths"]
