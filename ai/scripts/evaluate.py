"""Evaluate and compare models on a held-out TEST split.

Each model is represented by a predictions CSV produced on the TEST split:

    report_id,y_true,y_pred[,y_score][,score_<label> ...]

* ``y_score``        binary tasks: score/probability of the positive class
* ``score_<label>``  multi-class tasks: per-class probabilities (for ROC-AUC)

Usage (from ai/):
    # compare two spam models
    python scripts/evaluate.py --task spam \
        --predictions baseline=preds/spam_heuristic.csv \
        --predictions logreg=preds/spam_logreg.csv \
        --output reports/spam_eval.json

    # check which test splits exist
    python scripts/evaluate.py --list-test-sets

Threshold selection (--sweep) is intended for VALIDATION predictions only;
never tune thresholds on the test split.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from _common import AI_ROOT, CATEGORIES, EXIT_INVALID, EXIT_MISSING, EXIT_OK, SEVERITIES, resolve

from app.evaluation.metrics import compare_reports, evaluate_classification, threshold_sweep

TASKS: dict[str, dict] = {
    "image": {"labels": CATEGORIES, "binary": False, "test": "datasets/processed/image_classification/test"},
    "category": {"labels": CATEGORIES, "binary": False, "test": "datasets/processed/text/test.csv"},
    "severity": {"labels": SEVERITIES, "binary": False, "test": "datasets/processed/text/test.csv"},
    "spam": {"labels": [0, 1], "binary": True, "test": "datasets/processed/spam/test.csv"},
    "consistency": {"labels": [0, 1], "binary": True, "test": "datasets/processed/consistency/test.csv"},
    "duplicate": {"labels": [0, 1], "binary": True, "test": "datasets/processed/duplicate/test.csv"},
}


def _parse_named(values: list[str]) -> dict[str, Path]:
    named: dict[str, Path] = {}
    for value in values:
        name, sep, path = value.partition("=")
        if not sep:
            name, path = Path(value).stem, value
        named[name] = resolve(path)
    return named


def _to_binary(series):
    return series.astype(str).str.strip().str.lower().map({"1": 1, "true": 1, "0": 0, "false": 0})


def evaluate_file(task: str, path: Path):
    import pandas as pd

    spec = TASKS[task]
    df = pd.read_csv(path, encoding="utf-8")
    for col in ("y_true", "y_pred"):
        if col not in df.columns:
            raise ValueError(f"{path.name}: missing column '{col}'")
    if spec["binary"]:
        y_true, y_pred = _to_binary(df["y_true"]), _to_binary(df["y_pred"])
        if y_true.isna().any() or y_pred.isna().any():
            raise ValueError(f"{path.name}: binary labels must be 0/1 or true/false")
        y_score = df["y_score"].astype(float).tolist() if "y_score" in df.columns else None
        return evaluate_classification(
            y_true.tolist(), y_pred.tolist(), task=task, labels=[0, 1], y_score=y_score, positive_label=1
        ), (y_true.tolist(), y_score)

    y_true = df["y_true"].astype(str).str.strip().str.lower().tolist()
    y_pred = df["y_pred"].astype(str).str.strip().str.lower().tolist()
    score_cols = [f"score_{lbl}" for lbl in spec["labels"]]
    y_score = df[score_cols].astype(float).values.tolist() if all(c in df.columns for c in score_cols) else None
    return evaluate_classification(y_true, y_pred, task=task, labels=spec["labels"], y_score=y_score), (None, None)


def _print_table(rows: list[dict]) -> None:
    if not rows:
        return
    headers = list(rows[0])
    widths = {h: max(len(h), *(len(str(r[h])) for r in rows)) for h in headers}
    print("  ".join(h.ljust(widths[h]) for h in headers))
    for row in rows:
        print("  ".join(str(row[h]).ljust(widths[h]) for h in headers))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--task", choices=sorted(TASKS))
    parser.add_argument("--predictions", action="append", default=[], help="name=path.csv (repeatable)")
    parser.add_argument("--output", help="Write full JSON results to this path.")
    parser.add_argument("--sweep", action="store_true", help="Threshold sweep for binary tasks (VALIDATION only).")
    parser.add_argument("--list-test-sets", action="store_true")
    args = parser.parse_args(argv)

    if args.list_test_sets:
        missing = 0
        for task, spec in TASKS.items():
            exists = resolve(spec["test"]).exists()
            missing += not exists
            print(f"  {task:<12} {'present' if exists else 'MISSING'}  {spec['test']}")
        return EXIT_OK if missing == 0 else EXIT_MISSING

    if not args.task or not args.predictions:
        parser.print_usage()
        print("\nNo predictions supplied. Generate a predictions CSV for each model on the TEST split, then")
        print("re-run with --task and one or more --predictions name=path.csv. Use --list-test-sets to see")
        print("which test splits exist (see PROJECT_DATASET_REQUIREMENTS.txt).")
        return EXIT_MISSING

    named = _parse_named(args.predictions)
    absent = [str(p.relative_to(AI_ROOT) if p.is_relative_to(AI_ROOT) else p) for p in named.values() if not p.is_file()]
    if absent:
        print(f"Predictions file(s) not found: {absent}")
        return EXIT_MISSING

    reports, results = {}, {}
    for name, path in named.items():
        try:
            report, (y_true_bin, y_score) = evaluate_file(args.task, path)
        except ValueError as exc:
            print(f"[error] {exc}")
            return EXIT_INVALID
        reports[name] = report
        results[name] = report.to_dict()
        if args.sweep and y_score is not None:
            results[name]["threshold_sweep"] = threshold_sweep(y_true_bin, y_score)

        print(f"\n=== {name} ({args.task}, n={report.n_samples}) ===")
        print(f"accuracy={report.accuracy:.4f}  precision_macro={report.precision_macro:.4f}  "
              f"recall_macro={report.recall_macro:.4f}  f1_macro={report.f1_macro:.4f}  "
              f"roc_auc={'n/a' if report.roc_auc is None else f'{report.roc_auc:.4f}'}")
        print(f"labels: {report.labels}")
        print("confusion matrix (rows=true, cols=pred):")
        for label, row in zip(report.labels, report.confusion_matrix):
            print(f"  {label:<24} {row}")
        for note in report.notes:
            print(f"  note: {note}")

    print("\n=== Comparison (sorted by macro F1) ===")
    comparison = compare_reports(reports)
    _print_table(comparison)

    if args.output:
        out = resolve(args.output)
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps({"task": args.task, "comparison": comparison, "models": results}, indent=2))
        print(f"\nWrote {out}")
    return EXIT_OK


if __name__ == "__main__":
    sys.exit(main())
