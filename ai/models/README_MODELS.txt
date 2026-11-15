Civi Pulse AI - trained model artefacts
======================================

Three models are trained (2026-11-15). Weights are git-ignored; rebuild them
with scripts/data_prep/ and scripts/train_*.py (see datasets/README_DATASET.txt).

Everything in this folder except this file is git-ignored. Weights are
mounted read-only into the Docker container (docker-compose.yml:
./models -> /app/models).

Layout
  image/image_classifier.pt        YOLO weights           -> IMAGE_MODEL_PATH
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

-------------------------------------------------------------------------------
name / version:   image_classifier.pt (v1)
task:             issue category from the report photo (10 classes)
training data:    5,919 images: Road Issues Detection Dataset (CC0) for pothole,
                  road_damage, garbage, damaged_sign, damaged_public_property
                  (max 2 shots per location) + FixMyStreet report photos for all
                  10 classes. All resized to max 256 px. Split 70/15/15 grouped by
                  location / report. train 4,195 / val 897 / test 827.
base model:       Ultralytics YOLO11n-cls, imgsz 224, batch 32, seed 42,
                  early stopping (patience 10): stopped at epoch 21, best epoch 11.
validation:       top-1 accuracy 0.7915
test:             accuracy 0.7630, macro F1 0.7147 (n = 827)
                  Road Issues photos 0.9329 (n = 417); FixMyStreet photos 0.5902 (n = 410)
external set:     24 Wikimedia Commons photos (datasets/processed/external_eval): 12/24 correct
known limitations: much weaker on real citizen photos than on curated ones;
                  road_damage (F1 0.46) is confused with pothole; flooding and
                  blocked_drain overlap; the "broken road sign" source class is
                  mostly intact signs. Treat as advisory; route low-confidence
                  predictions to staff.

name / version:   text_category/ (v1)
task:             issue category from the description (10 classes)
training data:    14,167 FixMyStreet reports (English), labelled by the council
                  category the citizen chose, max 1,500 per class.
                  train 10,013 / val 2,087 / test 2,067, grouped by report.
base model:       xlm-roberta-base, 5 epochs, lr 2e-5, batch 16, max_length 256,
                  best checkpoint by validation macro F1 (epoch 3).
validation:       macro F1 0.8011, accuracy 0.7964
test:             accuracy 0.8094, macro F1 0.8163 (n = 2,067)
                  weakest: road_damage 0.63, flooding 0.67, blocked_drain 0.68
                  strongest: broken_street_light 0.95, fallen_tree 0.94, garbage 0.90
per language:     English only. Sinhala and Tamil NOT measured (no labelled data).
known limitations: UK wording and council categories; untested on Sri Lankan text.

name / version:   spam/spam_model.joblib (v1)
task:             P(spam) for a report description
training data:    FixMyStreet reports (legitimate) + UCI SMS Spam Collection
                  (spam = promotional, ham = irrelevant). train 4,621 / val 931 / test 895.
model:            TF-IDF char 2-5-grams + SpamService numeric features ->
                  LogisticRegression(class_weight=balanced, C=4)
threshold:        best F1 on validation = 0.50; the service default SPAM_THRESHOLD
                  is 0.70.
test (0.50):      accuracy 0.9922, macro F1 0.9909, ROC-AUC 0.9997; 5 of 615
                  legitimate flagged, 2 of 280 spam missed
test (0.70):      accuracy 0.9933; 0 of 615 legitimate flagged, 6 of 280 spam missed
known limitations: SMS text differs strongly from civic reports, so the test is
                  easier than real traffic; no Sinhala/Tamil spam; no abusive or
                  gibberish examples.
