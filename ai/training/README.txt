Civi Pulse AI - training workspace
==================================

Each sub-folder holds the configuration (config.json) and, later, any
task-specific training code/notebooks for one model:

  image/      YOLO issue-category model          -> scripts/train_image.py
  text/       multilingual category + severity    -> scripts/train_text.py
  spam/       spam / abuse classifier             -> scripts/train_spam.py
  duplicate/  duplicate-pair classifier (future)  -> no script yet; replaces the
              hand-set weights in app/services/duplicate_service.py

Nothing here has been trained. No dataset has been selected yet.
See ../PROJECT_DATASET_REQUIREMENTS.txt before adding data.

Rules
-----
* Tune hyper-parameters and thresholds on the VALIDATION split only.
* Touch the TEST split once per final model, via scripts/evaluate.py.
* Never commit datasets or model weights (they are git-ignored).
