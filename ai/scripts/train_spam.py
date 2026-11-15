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

from _common import EXIT_OK, DatasetCheck, check_csv_splits, load_config, resolve

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

    return train(config, frames)


def build_features(df):
    """Same feature code as inference (SpamService.extract_features).

    Training rows carry no photo and no trustworthy coordinates, so image and
    location features are neutral (a point inside the service area, no image).
    """
    import pandas as pd

    from app.core.config import Settings
    from app.models.spam_model import SPAM_FEATURE_COLUMNS, SPAM_TEXT_COLUMN
    from app.schemas.report import ReportInput
    from app.services.spam_service import SpamService
    from app.utils.text import normalize_text

    settings = Settings()
    lat = (settings.service_area_min_lat + settings.service_area_max_lat) / 2
    lon = (settings.service_area_min_lon + settings.service_area_max_lon) / 2
    service = SpamService(model=None, settings=settings)  # type: ignore[arg-type]
    rows = []
    for i, text in enumerate(df["description"].astype(str)):
        text = normalize_text(text)
        report = ReportInput(report_id=f"r{i}", description=text, latitude=lat, longitude=lon)
        rows.append(service.extract_features(text, report, None))
    return pd.DataFrame(rows)[[*SPAM_FEATURE_COLUMNS, SPAM_TEXT_COLUMN]]


def train(config, frames) -> int:
    import json

    import joblib
    import numpy as np
    from sklearn.compose import ColumnTransformer
    from sklearn.feature_extraction.text import TfidfVectorizer
    from sklearn.linear_model import LogisticRegression
    from sklearn.pipeline import Pipeline
    from sklearn.preprocessing import StandardScaler

    from app.evaluation.metrics import threshold_sweep
    from app.models.spam_model import SPAM_FEATURE_COLUMNS, SPAM_TEXT_COLUMN

    def xy(split):
        df = frames[split]
        y = df["is_spam"].astype(str).str.strip().str.lower().isin({"1", "true"}).astype(int).to_numpy()
        return build_features(df), y

    x_train, y_train = xy("train")
    x_val, y_val = xy("val")
    x_test, y_test = xy("test")

    tf = config["tfidf"]
    pipeline = Pipeline(
        [
            (
                "features",
                ColumnTransformer(
                    [
                        (
                            "text",
                            TfidfVectorizer(
                                analyzer=tf["analyzer"], ngram_range=tuple(tf["ngram_range"]), min_df=tf["min_df"],
                                sublinear_tf=True,
                            ),
                            SPAM_TEXT_COLUMN,
                        ),
                        ("numeric", StandardScaler(), list(SPAM_FEATURE_COLUMNS)),
                    ]
                ),
            ),
            (
                "clf",
                LogisticRegression(
                    class_weight=config["class_weight"], max_iter=2000, random_state=config["seed"], C=4.0
                ),
            ),
        ]
    )
    pipeline.fit(x_train, y_train)

    # Choose the decision threshold on VALIDATION only (best F1).
    val_scores = pipeline.predict_proba(x_val)[:, 1]
    sweep = threshold_sweep(y_val.tolist(), val_scores.tolist())
    best = max(sweep, key=lambda r: r["f1"])
    threshold = float(best["threshold"])

    out = resolve(config["output_path"])
    out.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(pipeline, out)

    test_scores = pipeline.predict_proba(x_test)[:, 1]
    test_pred = (test_scores >= threshold).astype(int)
    import pandas as pd

    pred = pd.DataFrame(
        {
            "report_id": frames["test"]["report_id"],
            "y_true": y_test,
            "y_pred": test_pred,
            "y_score": np.round(test_scores, 5),
            "spam_type": frames["test"]["spam_type"],
        }
    )
    pred.to_csv(out.parent / "test_predictions.csv", index=False)
    summary = {"validation_threshold": threshold, "validation_best": best, "train_rows": int(len(y_train))}
    (out.parent / "train_summary.json").write_text(json.dumps(summary, indent=2, default=float))
    print(f"[train] saved {out}; threshold chosen on VAL = {threshold:.2f}; TEST predictions written")
    return EXIT_OK

if __name__ == "__main__":
    sys.exit(main())
