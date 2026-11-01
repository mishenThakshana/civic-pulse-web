Civi Pulse AI - trained model artefacts
======================================

NO MODEL HAS BEEN TRAINED YET. This folder intentionally contains no weights.

Everything in this folder except this file is git-ignored. Weights are
mounted read-only into the Docker container (docker-compose.yml:
./models -> /app/models).

Planned layout
  image/<version>.pt               YOLO weights           -> IMAGE_MODEL_PATH
  text_category/                   HF save_pretrained dir -> TEXT_MODEL_NAME
  severity/                        HF save_pretrained dir -> SEVERITY_MODEL_NAME
  spam/spam_model.joblib           scikit-learn pipeline  -> SPAM_MODEL_PATH
  duplicate/duplicate_model.joblib (future)
  embeddings/                      optional local copy of a Sentence
                                   Transformers model     -> EMBEDDING_MODEL_NAME

Relative paths in .env are resolved from the ai/ directory, e.g.
  IMAGE_MODEL_PATH=models/image/civi_pulse_cls_v1.pt

Model card (add one entry per deployed model)
  name / version:
  task:
  training dataset version:
  base model:
  trained on (date, by):
  validation metrics (overall + per language):
  test metrics (overall + per language):
  Sri Lankan evaluation set metrics:
  chosen threshold(s) and how they were selected (validation split):
  known limitations:
