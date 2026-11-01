"""Train the spam model (scikit-learn pipeline over text + image + metadata features).

Usage (from ai/):
    python scripts/train_spam.py --validate-only
    python scripts/train_spam.py

Expects datasets/processed/spam/{train,val,test}.csv with columns
report_id, description, is_spam, spam_type  (+ optional latitude, longitude,
image) - see PROJECT_DATASET_REQUIREMENTS.txt, section 3.
"""

from __future__ import annotations

import argparse
import sys

from _common import EXIT_OK, DatasetCheck, check_csv_splits, load_config, not_implemented, resolve

REQUIRED_COLUMNS = ["report_id", "description", "is_spam", "spam_type"]
SPAM_TYPES = ["legitimate", "irrelevant", "promotional", "nonsense", "abusive", "duplicate_like", "other"]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--validate-only", action="store_true")
    args = parser.parse_args(argv)

    config = load_config("spam")
    check = DatasetCheck("spam")
    frames = check_csv_splits(
        check,
        resolve(config["dataset_dir"]),
        REQUIRED_COLUMNS,
        label_column="spam_type",
        allowed_labels=SPAM_TYPES,
        min_rows=config.get("min_rows"),
    )
    for split, df in frames.items():
        if "is_spam" not in df.columns:
            continue
        values = set(df["is_spam"].astype(str).str.strip().str.lower())
        if not values <= {"0", "1", "true", "false"}:
            check.error(f"{split}: is_spam must be 0/1 or true/false, got {sorted(values)[:5]}")
            continue
        is_spam = df["is_spam"].astype(str).str.strip().str.lower().isin({"1", "true"})
        rate = float(is_spam.mean())
        check.note(f"{split}: spam rate {rate:.1%}")
        if rate in (0.0, 1.0):
            check.error(f"{split}: needs both spam and legitimate examples")
        if "spam_type" in df.columns:
            legit = df["spam_type"].astype(str).str.lower() == "legitimate"
            inconsistent = int((legit & is_spam).sum() + (~legit & ~is_spam).sum())
            if inconsistent:
                check.error(f"{split}: {inconsistent} rows where is_spam disagrees with spam_type")

    code = check.report()
    if code != EXIT_OK or args.validate_only:
        return code

    return not_implemented(
        "spam model training",
        [
            "Compute features for every row with SpamService.extract_features (same code as inference) so "
            "columns match app.models.spam_model.SPAM_FEATURE_COLUMNS + 'description'.",
            "Pipeline: ColumnTransformer[TfidfVectorizer(analyzer='char_wb', ngram_range=(2,5)) on description, "
            "passthrough numeric features] -> LogisticRegression(class_weight='balanced').",
            "Tune on VAL; choose SPAM_THRESHOLD with app.evaluation.metrics.threshold_sweep on VAL.",
            f"joblib.dump(pipeline, '{config['output_path']}') and set SPAM_MODEL_PATH in .env.",
            "Evaluate once on TEST with scripts/evaluate.py.",
        ],
    )


if __name__ == "__main__":
    sys.exit(main())
