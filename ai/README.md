# Civi Pulse AI

An independent Python AI service for **Civi Pulse**. It analyses citizen infrastructure reports (photo + description + location) and returns advisory signals for the council: issue category, spam probability, severity, image/text consistency, duplicate probability and an overall validity score.

> **Status: three trained models (image category, text category, spam).**
> They were trained on public data (see `datasets/README_DATASET.txt`) and their test results are in `models/README_MODELS.txt`. The text and spam models are English-only (`MODEL_LANGUAGES=en`): reports in other languages are still analysed, but get their category from the photo and are spam-checked with the language-independent rules. Weights are git-ignored and are enabled with `IMAGE_MODEL_PATH`, `TEXT_MODEL_NAME` and `SPAM_MODEL_PATH`. Severity, consistency and duplicate models are not trained. With no models configured, the API still runs: components that need a model return `"status": "model_not_loaded"` and components with a documented rule-based fallback return `"status": "heuristic"`.

The service lives entirely in `ai/`. It does not touch the mobile app (`app/`) or the council dashboard/backend (`web/`), and it talks to them only through REST.

---

## Contents

1. [What Civi Pulse AI does](#1-what-civi-pulse-ai-does)
2. [Architecture](#2-architecture)
3. [Technology stack](#3-technology-stack)
4. [Folder structure](#4-folder-structure)
5. [Setup](#5-setup)
6. [Virtual environment](#6-virtual-environment)
7. [Installing dependencies](#7-installing-dependencies)
8. [Environment variables](#8-environment-variables)
9. [Running locally](#9-running-locally)
10. [Docker](#10-docker)
11. [API endpoints](#11-api-endpoints)
12. [Example API requests](#12-example-api-requests)
13. [Model architecture](#13-model-architecture)
14. [Dataset requirements](#14-dataset-requirements)
15. [Future training process](#15-future-training-process)
16. [Evaluation](#16-evaluation)
17. [Integration with the existing Civi Pulse backend](#17-integration-with-the-existing-civi-pulse-backend)

---

## 1. What Civi Pulse AI does

For each report it produces:

| Output | Meaning |
|---|---|
| `category` | Which of 10 issue types (pothole, garbage, flooding, …) is shown, with a confidence score |
| `spam` | Probability that the report is spam, irrelevant or abusive, plus an `is_suspicious` flag |
| `severity` | `low` / `medium` / `high` |
| `consistency` | Whether the photo and the text describe the same issue |
| `duplicate` | Probability that the report duplicates an existing one supplied by the backend |
| `validity_score` | Overall likelihood that this is a genuine, actionable report |
| `requires_human_review` | Advisory flag with reasons |

**The service only flags reports. It never deletes, rejects or stores them.** Every decision stays with council staff.

### CURRENT IMPLEMENTATION vs FUTURE DATASET / MODEL WORK

| Component | CURRENT IMPLEMENTATION | FUTURE DATASET / MODEL WORK |
|---|---|---|
| Image validation | ✅ Real: MIME type + magic bytes, size, dimensions, decode check, EXIF stripping | – |
| Image quality | ✅ Real: OpenCV blur (Laplacian variance) and exposure checks | Re-tune thresholds on real photos |
| Image hash | ✅ Real: 64-bit perceptual dHash (used for duplicate checks) | – |
| Image category | ✅ Trained YOLO11n-cls (test accuracy 0.763; 0.590 on real citizen photos) | More real citizen photos, Sri Lankan photos, detection later |
| Text normalisation / language | ✅ Real: Unicode NFC, Sinhala ZWJ preserved, script-based en/si/ta detection | Trained language ID for romanised text |
| Text category | ✅ Fine-tuned XLM-R base on English reports (test macro F1 0.816) | Sinhala/Tamil labelled data and per-language evaluation |
| Embeddings | `model_not_loaded` (can be enabled with a pretrained Sentence Transformers model) | Choose/fine-tune a multilingual embedding model |
| Spam | ✅ Trained scikit-learn model (test accuracy 0.993 at threshold 0.70); noisy-OR rules as fallback | Real civic spam, Sinhala/Tamil, abusive and gibberish examples |
| Severity | `heuristic`: English keyword rules. Sinhala/Tamil get `model_not_loaded` | Fine-tune a severity model (text first, then multimodal) |
| Consistency | Works with the trained image + text models (label-distribution overlap); threshold 0.70 is untuned | Tune the threshold on labelled pairs; optionally a joint image-text model |
| Duplicate | `heuristic`: documented weighted signals (GPS, TF-IDF text, image hash, time) | Train a pair classifier on the duplicate dataset |
| Validity | `heuristic`: documented weighted average with a `coverage` indicator | Train and calibrate a scorer on labelled data |

---

## 2. Architecture

```
                         Civi Pulse backend (web/, Laravel)
                                     │  multipart POST /api/v1/analyze
                                     ▼
┌──────────────────────── FastAPI (app/api) ──────────────────────────┐
│ validation: report_id, description, coordinates, image, candidates  │
└──────────────────────────────┬──────────────────────────────────────┘
                               ▼
                  ReportAnalysisPipeline (services/pipeline.py)
   Image ─────────► ImageService ──► quality, dHash, category scores
   Text  ─────────► TextService  ──► language, category scores, embedding
   Image + Text ──► category fusion, ConsistencyService
   Text + Image + Metadata ──► SpamService
   Text (+ category) ──► SeverityService
   Report + candidates (DuplicateCandidateProvider) ──► DuplicateService
   All results ───► ValidityScorer ──► validity_score, requires_human_review
                               │
                               ▼
         Model interfaces (app/models): ImageModel, TextModel,
         EmbeddingModel, SpamModel   ← YOLO / Transformers / ST / sklearn
```

Design rules:

- **Model abstraction.** Services depend only on abstract interfaces with `load()`, `is_ready()` and `predict()`/`encode()`. The API has no knowledge of YOLO or Transformers. You can swap an implementation in one place, `ModelRegistry.from_settings`.
- **Startup never fails because of models.** Missing or broken weights are logged and reported by `/api/v1/models`. Heavy libraries are imported lazily inside `load()`. Nothing is downloaded at startup (`ALLOW_MODEL_DOWNLOAD=false` passes `local_files_only=True`).
- **Honest statuses.** Every result block carries a `status` (`ok`, `heuristic`, `model_not_loaded`, `insufficient_data`, `unsupported_language` or `error`) and a `method`.
- **Failure isolation.** If one component raises, only that block returns `status: "error"`. The rest of the analysis still runs.
- **Stateless, no database.** Duplicate candidates come from the backend.
- **Privacy.** EXIF (including GPS) is dropped on load. Descriptions and images are never logged or stored.

---

## 3. Technology stack

Python 3.11+, FastAPI, Pydantic v2 (+ pydantic-settings), Uvicorn, PyTorch, Ultralytics YOLO, OpenCV, Pillow, NumPy, Pandas, scikit-learn, Hugging Face Transformers, Sentence Transformers, pytest and Docker. The service does not use LangChain or any external LLM API.

---

## 4. Folder structure

```
ai/
├── app/
│   ├── main.py                    # FastAPI app factory + lifespan (model loading)
│   ├── api/
│   │   ├── dependencies.py        # settings / registry / pipeline injection
│   │   └── routes/
│   │       ├── health.py          # GET /health, GET /models
│   │       └── analysis.py        # POST /analyze
│   ├── core/
│   │   ├── config.py              # Settings from env / .env
│   │   ├── logging.py             # logging (never logs report content)
│   │   └── exceptions.py          # domain errors → structured JSON errors
│   ├── schemas/
│   │   ├── report.py              # IssueCategory, SeverityLevel, ReportInput, CandidateReport
│   │   └── analysis.py            # response models + ComponentStatus
│   ├── services/
│   │   ├── pipeline.py            # ReportAnalysisPipeline
│   │   ├── image_service.py
│   │   ├── text_service.py
│   │   ├── spam_service.py
│   │   ├── severity_service.py
│   │   ├── consistency_service.py
│   │   ├── duplicate_service.py   # DuplicateCandidateProvider protocol
│   │   └── validity_scorer.py     # ValidityScorer interface + dev fallback
│   ├── models/
│   │   ├── base.py                # BaseModelWrapper (load / is_ready / status)
│   │   ├── image_model.py         # ImageModel + YoloImageModel
│   │   ├── text_model.py          # TextModel + TransformersTextClassifier
│   │   ├── spam_model.py          # SpamModel + SklearnSpamModel, feature columns
│   │   ├── embedding_model.py     # EmbeddingModel + SentenceTransformerEmbeddingModel
│   │   └── registry.py            # ModelRegistry (the only place implementations are chosen)
│   ├── evaluation/metrics.py      # accuracy, P/R/F1, confusion matrix, ROC-AUC, threshold sweep
│   └── utils/
│       ├── image.py               # validation, loading, preprocessing, quality, dHash
│       ├── text.py                # normalisation, language detection, text features
│       └── similarity.py          # cosine, haversine, hash similarity, Bhattacharyya, TF-IDF
├── training/{image,text,spam,duplicate}/config.json
├── datasets/{raw,processed}/ + README_DATASET.txt   (data is git-ignored)
├── models/README_MODELS.txt                         (weights are git-ignored)
├── scripts/train_image.py, train_text.py, train_spam.py, evaluate.py, _common.py
├── tests/                                           (pytest, no models required)
├── requirements.txt, requirements-dev.txt, pytest.ini
├── .env.example, .gitignore, .dockerignore
├── Dockerfile, docker-compose.yml
├── README.md
└── PROJECT_DATASET_REQUIREMENTS.txt
```

---

## 5. Setup

Requirements: Python **3.11 or newer** and optionally Docker. A GPU is **not** required.

```bash
cd civi-pulse/ai
cp .env.example .env        # optional; defaults work without it
```

## 6. Virtual environment

```bash
python3 -m venv .venv
source .venv/bin/activate          # Windows: .venv\Scripts\activate
```

## 7. Installing dependencies

```bash
pip install --upgrade pip
pip install -r requirements-dev.txt   # runtime + pytest/httpx
# runtime only: pip install -r requirements.txt
```

`torch` is large. For a CPU-only Linux install that skips the CUDA wheels, run:
`pip install --index-url https://download.pytorch.org/whl/cpu torch torchvision` before the command above.

## 8. Environment variables

All variables are optional. See `.env.example` for the full, commented list.

| Variable | Default | Purpose |
|---|---|---|
| `APP_ENV` | `development` | development / test / staging / production |
| `HOST`, `PORT` | `0.0.0.0`, `8000` | bind address |
| `TEXT_MODEL_NAME` | empty | fine-tuned HF category classifier (dir or id) |
| `SEVERITY_MODEL_NAME` | empty | fine-tuned HF severity classifier |
| `EMBEDDING_MODEL_NAME` | empty | Sentence Transformers model, e.g. `sentence-transformers/LaBSE` |
| `IMAGE_MODEL_PATH` | empty | YOLO `.pt` weights (relative paths resolve from `ai/`) |
| `SPAM_MODEL_PATH` | empty | scikit-learn `.joblib` pipeline |
| `ALLOW_MODEL_DOWNLOAD` | `false` | allow Hugging Face downloads when loading |
| `DEVICE` | `auto` | `auto`/`cpu`/`cuda`/`mps` (always falls back to CPU) |
| `ENABLE_HEURISTIC_FALLBACKS` | `true` | use the documented rule-based fallbacks. Set to `false` to return only `model_not_loaded` |
| `SPAM_THRESHOLD` | `0.70` | `spam.is_suspicious` cut-off |
| `CONSISTENCY_THRESHOLD` | `0.70` | `consistency.is_consistent` cut-off |
| `DUPLICATE_THRESHOLD` | `0.80` | `duplicate.is_duplicate` cut-off |
| `VALIDITY_REVIEW_THRESHOLD` | `0.50` | validity below this adds `low_validity_score` review reason |
| `DUPLICATE_RADIUS_METERS` | `150` | candidates farther away are never duplicates |
| `DUPLICATE_TIME_WINDOW_HOURS` | `168` | recency signal decays to 0 over this window |
| `MAX_IMAGE_SIZE_MB` | `10` | upload limit (HTTP 413 above it) |
| `SERVICE_AREA_*` | Sri Lanka bbox | out-of-area coordinates are a spam *signal*, never a rejection |

## 9. Running locally

```bash
uvicorn app.main:app --reload --port 8000
```

- Health: <http://localhost:8000/api/v1/health>
- Swagger docs: <http://localhost:8000/docs>

Run the tests (no models or datasets needed):

```bash
pytest
```

## 10. Docker

```bash
cd civi-pulse/ai
docker compose up --build
```

This serves <http://localhost:8000> and <http://localhost:8000/docs>. The image is CPU-only (PyTorch CPU wheels). `./models` is mounted read-only at `/app/models`, and `.env` is used if it exists. The web stack uses port 8081, so the two do not conflict. To change the host port, set `AI_HOST_PORT=8010 docker compose up`.

**GPU later:** build with `TORCH_INDEX_URL=https://download.pytorch.org/whl/cu124 docker compose build`. Then uncomment the `deploy.resources` GPU block in `docker-compose.yml` (this needs the NVIDIA Container Toolkit) and set `DEVICE=cuda`.

## 11. API endpoints

| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/health` | `{"status": "ok", "service": "civi-pulse-ai"}`, returned whether or not any model is loaded |
| GET | `/api/v1/models` | load status of every model and whether heuristic fallbacks are on |
| POST | `/api/v1/analyze` | full report analysis (multipart/form-data) |
| GET | `/docs`, `/openapi.json` | interactive docs / OpenAPI schema |

`POST /api/v1/analyze` form fields:

| Field | Required | Notes |
|---|---|---|
| `image` | yes | JPEG, PNG or WebP ≤ `MAX_IMAGE_SIZE_MB`. The content is verified by magic bytes and a full decode |
| `description` | yes | 1–5000 chars after normalisation. English, Sinhala or Tamil |
| `latitude` | yes | −90..90 |
| `longitude` | yes | −180..180 |
| `report_id` | yes | `^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$` |
| `title` | no | |
| `created_at` | no | ISO-8601. Enables the time signal in duplicate checks |
| `language` | no | `en`/`si`/`ta` hint, used only when the script cannot be detected |
| `candidates` | no | JSON array of existing reports for duplicate detection (see §17) |

Errors always have the shape `{"error": {"code", "message", "details"}}`:
`422 invalid_input` / `422 invalid_image`, `413 image_too_large`, `415 unsupported_media_type`.

**Status values in every block:**

| `status` | Meaning |
|---|---|
| `ok` | produced by a loaded, trained model |
| `heuristic` | produced by a documented rule-based development fallback. It is **not** a trained model |
| `model_not_loaded` | the required model is not configured. Value fields are `null` |
| `insufficient_data` | required input missing, e.g. no duplicate candidates |
| `unsupported_language` | the text is in a language the trained model was not trained on (see `MODEL_LANGUAGES`). Value fields are `null` |
| `error` | this component failed. Other components still ran |

## 12. Example API requests

```bash
curl -s http://localhost:8000/api/v1/health

curl -s -X POST http://localhost:8000/api/v1/analyze \
  -F "image=@pothole.jpg;type=image/jpeg" \
  -F "description=Large pothole near the school, dangerous for bikes" \
  -F "latitude=6.9271" -F "longitude=79.8612" \
  -F "report_id=report-123" \
  -F "created_at=2026-09-21T08:30:00+05:30" \
  -F 'candidates=[{"report_id":"report-98","description":"Big pothole close to the school","latitude":6.9272,"longitude":79.8612,"created_at":"2026-09-20T10:00:00+05:30"}]'
```

Abridged response **with no trained models** (the current state):

```json
{
  "report_id": "report-123",
  "category":    { "status": "model_not_loaded", "label": null, "confidence": null },
  "spam":        { "status": "heuristic", "method": "rule_based_v1 (development fallback, not a trained model)",
                   "probability": 0.02, "is_suspicious": false, "threshold": 0.7, "reasons": [] },
  "severity":    { "status": "heuristic", "label": "high", "confidence": null },
  "consistency": { "status": "model_not_loaded", "score": null, "is_consistent": null },
  "duplicate":   { "status": "heuristic", "probability": 0.6, "is_duplicate": false,
                   "top_matches": [{ "report_id": "report-98", "distance_meters": 11.1, "...": "..." }] },
  "validity":    { "status": "heuristic", "score": 0.99, "coverage": 0.55,
                   "components": { "not_spam": 0.98, "image_quality": 1.0 } },
  "validity_score": 0.99,
  "requires_human_review": false,
  "review_reasons": [],
  "image": { "status": "model_not_loaded", "quality": { "is_blurry": false, "...": "..." },
             "perceptual_hash": "51ad220a8c34c5d4" },
  "text":  { "status": "model_not_loaded", "language": "en" },
  "meta":  { "uses_development_fallbacks": true, "models": { "...": "..." } }
}
```

Once trained models are configured, the same fields return `"status": "ok"` with real predictions, for example `"category": {"status": "ok", "label": "road_damage", "confidence": 0.94, "source": "image+text"}`. The response shape stays the same.

## 13. Model architecture

| Interface (`app/models`) | Current implementation | Used by | Configure with |
|---|---|---|---|
| `ImageModel.predict(image)` | `YoloImageModel`: Ultralytics YOLO, `classify` or `detect` weights | category, consistency, spam features | `IMAGE_MODEL_PATH` |
| `TextModel.predict(text)` | `TransformersTextClassifier`: fine-tuned `AutoModelForSequenceClassification` | category, and severity (second instance) | `TEXT_MODEL_NAME`, `SEVERITY_MODEL_NAME` |
| `EmbeddingModel.encode(texts)` | `SentenceTransformerEmbeddingModel` | duplicate text similarity, zero-shot consistency | `EMBEDDING_MODEL_NAME` |
| `SpamModel.predict(features)` | `SklearnSpamModel`: joblib pipeline over `SPAM_FEATURE_COLUMNS` + description | spam | `SPAM_MODEL_PATH` |
| `ValidityScorer.score(inputs)` | `WeightedValidityScorer` (development fallback) | validity | code (replace with trained scorer) |
| `DuplicateCandidateProvider.get_candidates(report)` | `RequestCandidateProvider` | duplicate | request field `candidates` |

To replace an implementation, for example with CLIP, ONNX or a hosted model server, subclass the interface, implement `_load()` and `predict()`/`encode()`, and return it from `ModelRegistry.from_settings`. The API and services do not change.

Fusion and fallback logic, with the weights documented in each module's docstring:

- **Category:** equal-weight fusion of the image and text distributions when both models are loaded (`pipeline.py`).
- **Consistency:** the Bhattacharyya overlap of the image and text category distributions. If only an embedding model is available, zero-shot uses embedding similarity to category prompts (`consistency_service.py`).
- **Spam fallback:** noisy-OR over weighted rules, `p = 1 − (1 − 0.02)·Π(1 − wᵢ)` (`spam_service.py`).
- **Duplicate fallback:** weighted mean of proximity 0.35, text 0.35, image 0.20 and recency 0.10 over the available signals. Candidates outside the radius score 0. With no text or image evidence the score is capped at 0.5 (`duplicate_service.py`).
- **Validity fallback:** weighted mean of not_spam 0.40, consistency 0.30, category_confidence 0.15 and image_quality 0.15 over the available signals. Duplicates are deliberately excluded, and `coverage` shows how much of the evidence was available (`validity_scorer.py`).

## 14. Dataset requirements

See **[`PROJECT_DATASET_REQUIREMENTS.txt`](PROJECT_DATASET_REQUIREMENTS.txt)**. It covers the image, text, spam, severity, consistency, duplicate and multilingual datasets, why Sri Lankan data is needed, split isolation, annotation guidelines, privacy rules and recommended sizes. `datasets/README_DATASET.txt` summarises the expected folder layout.

## 15. Future training process

1. Collect and de-identify data according to `PROJECT_DATASET_REQUIREMENTS.txt`, and put it in `datasets/processed/`.
2. Validate the structure. This checks columns, labels, languages and split leakage:
   ```bash
   python scripts/train_image.py --validate-only
   python scripts/train_text.py --task category --validate-only
   python scripts/train_text.py --task severity --validate-only
   python scripts/train_spam.py --validate-only
   ```
   Exit codes: `0` valid, `2` missing, `3` invalid, `4` valid but training not implemented yet.
3. Implement the training step marked `TODO` in each script. The steps are listed in the script output. Hyper-parameters are in `training/<task>/config.json`.
4. Tune hyper-parameters and thresholds on **validation** only (`evaluate.py --sweep`).
5. Evaluate once on **test**, and on the Sri Lankan evaluation set.
6. Put the weights in `models/`, set the env vars, restart the service, and check `/api/v1/models`.
7. Replace the heuristic fallbacks (duplicate weights, validity scorer) with trained versions, and record a model card in `models/README_MODELS.txt`.

## 16. Evaluation

`app/evaluation/metrics.py` provides accuracy, precision, recall, F1 (macro, weighted and per class), the confusion matrix, ROC-AUC (binary, or one-vs-rest for multi-class) and a threshold sweep. `scripts/evaluate.py` compares any number of models from predictions CSVs (`report_id,y_true,y_pred[,y_score][,score_<label>…]`):

```bash
python scripts/evaluate.py --list-test-sets
python scripts/evaluate.py --task spam \
  --predictions heuristic=preds/spam_heuristic.csv \
  --predictions logreg=preds/spam_logreg.csv \
  --output reports/spam_eval.json
```

Report metrics per language (en/si/ta) as well as overall.

## 17. Integration with the existing Civi Pulse backend

The Laravel backend in `web/` owns the reports (`reports` table: `description`, `latitude`, `longitude`, `photo_path`, `created_at`). The AI service is called **server-to-server**. The mobile app never calls it directly. This repository makes no changes to `web/`; below is a sketch of the integration for later.

1. When a report is created, the backend (ideally from a queued job, so citizens never wait on AI) sends the photo and fields to `POST /api/v1/analyze`.
2. **Duplicate candidates:** the backend selects nearby, recent, open reports (for example within ~300 m and 30 days) and sends them as `candidates`. Store each report's `image.perceptual_hash` from the response and send it back as the candidate's `image_hash` next time, so image similarity works without re-sending photos.
3. Store the response (for example as a JSON column or an `ai_analyses` table) and show the flags in the council dashboard. **Treat `heuristic` values as hints, and never auto-delete or auto-reject.**
4. If the AI service is down or slow, the backend should continue normally. Analysis is optional.

```php
// Illustrative only - e.g. inside a queued Laravel job
$response = Http::timeout(30)
    ->attach('image', Storage::get($report->photo_path), basename($report->photo_path))
    ->post(config('services.civi_pulse_ai.url').'/api/v1/analyze', [
        'report_id'   => (string) $report->id,
        'description' => $report->description,
        'latitude'    => $report->latitude,
        'longitude'   => $report->longitude,
        'created_at'  => $report->created_at->toIso8601String(),
        'candidates'  => json_encode($candidates),
    ]);
```

In a combined deployment, add the AI container to the same Docker network and address it as `http://civi-pulse-ai:8000`. Keep it on an internal network: the service has no authentication and is designed to be reached only by the backend.
