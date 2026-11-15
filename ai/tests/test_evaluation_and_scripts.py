import os
import subprocess
import sys
from pathlib import Path

import pandas as pd
import pytest

from app.evaluation.metrics import compare_reports, evaluate_classification, threshold_sweep

AI_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(AI_ROOT / "scripts"))

import _common  # noqa: E402


# --- metrics ------------------------------------------------------------------------


def test_binary_metrics():
    y_true = [1, 1, 0, 0, 1, 0]
    y_pred = [1, 0, 0, 0, 1, 1]
    scores = [0.9, 0.4, 0.2, 0.1, 0.8, 0.6]
    report = evaluate_classification(y_true, y_pred, task="spam", labels=[0, 1], y_score=scores)
    assert report.accuracy == pytest.approx(4 / 6)
    assert report.per_class["1"]["precision"] == pytest.approx(2 / 3)
    assert report.per_class["1"]["recall"] == pytest.approx(2 / 3)
    assert report.confusion_matrix == [[2, 1], [1, 2]]
    assert report.roc_auc == pytest.approx(8 / 9)


def test_multiclass_metrics_and_roc():
    labels = ["pothole", "garbage", "flooding"]
    y_true = ["pothole", "garbage", "flooding", "pothole"]
    y_pred = ["pothole", "garbage", "pothole", "pothole"]
    probs = [[0.8, 0.1, 0.1], [0.1, 0.8, 0.1], [0.5, 0.1, 0.4], [0.7, 0.2, 0.1]]
    report = evaluate_classification(y_true, y_pred, labels=labels, y_score=probs)
    assert report.accuracy == 0.75
    # labels given unsorted on purpose; every class is perfectly ranked one-vs-rest
    assert report.roc_auc == pytest.approx(1.0)
    swapped = evaluate_classification(y_true, y_pred, labels=labels, y_score=[r[::-1] for r in probs])
    assert swapped.roc_auc < report.roc_auc  # columns really are matched to labels
    assert report.per_class["flooding"]["recall"] == 0.0


def test_roc_auc_undefined_single_class():
    report = evaluate_classification([1, 1], [1, 0], labels=[0, 1], y_score=[0.9, 0.3])
    assert report.roc_auc is None
    assert any("ROC-AUC" in n for n in report.notes)


def test_metrics_input_validation():
    with pytest.raises(ValueError):
        evaluate_classification([], [])
    with pytest.raises(ValueError):
        evaluate_classification([1], [1, 0])


def test_threshold_sweep_and_compare():
    rows = threshold_sweep([0, 0, 1, 1], [0.1, 0.4, 0.6, 0.9], thresholds=[0.5])
    assert rows == [{"threshold": 0.5, "precision": 1.0, "recall": 1.0, "f1": 1.0}]
    good = evaluate_classification([0, 1], [0, 1], labels=[0, 1])
    bad = evaluate_classification([0, 1], [1, 0], labels=[0, 1])
    assert [r["model"] for r in compare_reports({"bad": bad, "good": good})] == ["good", "bad"]


# --- dataset checks -----------------------------------------------------------------


def _write_splits(directory: Path, rows_by_split: dict[str, list[dict]]):
    directory.mkdir(parents=True)
    for split, rows in rows_by_split.items():
        pd.DataFrame(rows).to_csv(directory / f"{split}.csv", index=False)


def _row(rid, cat="pothole", lang="en"):
    return {"report_id": rid, "description": "pothole here", "category": cat, "language": lang, "severity": "low"}


def test_csv_check_missing_directory(tmp_path):
    check = _common.DatasetCheck("t")
    _common.check_csv_splits(check, tmp_path / "nope", ["report_id"])
    assert check.report() == _common.EXIT_MISSING


def test_csv_check_valid(tmp_path):
    _write_splits(tmp_path / "text", {"train": [_row("a"), _row("b")], "val": [_row("c")], "test": [_row("d")]})
    check = _common.DatasetCheck("t")
    _common.check_csv_splits(check, tmp_path / "text", ["report_id", "description", "category"],
                             label_column="category", allowed_labels=_common.CATEGORIES)
    assert check.report() == _common.EXIT_OK


def test_csv_check_detects_leakage_and_bad_labels(tmp_path):
    _write_splits(
        tmp_path / "text",
        {"train": [_row("a"), _row("b", cat="volcano")], "val": [_row("c", lang="fr")], "test": [_row("a")]},
    )
    check = _common.DatasetCheck("t")
    _common.check_csv_splits(check, tmp_path / "text", ["report_id", "category"],
                             label_column="category", allowed_labels=_common.CATEGORIES)
    assert check.report() == _common.EXIT_INVALID
    joined = " ".join(check.errors)
    assert "LEAKAGE" in joined and "volcano" in joined and "fr" in joined


def test_image_folder_check(tmp_path):
    from tests.conftest import make_image

    root = tmp_path / "img"
    for split in _common.SPLITS:
        for cat in _common.CATEGORIES:
            (root / split / cat).mkdir(parents=True)
            make_image(size=(8, 8)).save(root / split / cat / f"{split}_{cat}.jpg")
    check = _common.DatasetCheck("img")
    counts = _common.check_image_class_folders(check, root, _common.CATEGORIES)
    assert check.report() == _common.EXIT_OK
    assert counts["train"]["pothole"] == 1


# --- scripts run end-to-end with no dataset ----------------------------------------------


@pytest.mark.parametrize(
    "args",
    [
        ["scripts/train_image.py", "--validate-only"],
        ["scripts/train_text.py", "--task", "severity"],
        ["scripts/train_spam.py"],
        ["scripts/evaluate.py"],
    ],
)
def test_scripts_report_missing_dataset(args, tmp_path):
    # An empty work root: the scripts must report the dataset as missing (and never touch the real models/).
    env = {**os.environ, "CIVIC_AI_WORK_ROOT": str(tmp_path)}
    proc = subprocess.run([sys.executable, *args], cwd=AI_ROOT, capture_output=True, text=True, timeout=120, env=env)
    assert proc.returncode == _common.EXIT_MISSING, proc.stdout + proc.stderr


def test_evaluate_script_compares_models(tmp_path):
    pd.DataFrame({"y_true": [0, 1, 1, 0], "y_pred": [0, 1, 0, 0], "y_score": [0.1, 0.9, 0.4, 0.2]}).to_csv(
        tmp_path / "a.csv", index=False
    )
    pd.DataFrame({"y_true": [0, 1, 1, 0], "y_pred": [1, 1, 1, 0], "y_score": [0.6, 0.9, 0.8, 0.2]}).to_csv(
        tmp_path / "b.csv", index=False
    )
    out = tmp_path / "eval.json"
    proc = subprocess.run(
        [sys.executable, "scripts/evaluate.py", "--task", "spam", "--predictions", f"a={tmp_path / 'a.csv'}",
         "--predictions", f"b={tmp_path / 'b.csv'}", "--output", str(out), "--sweep"],
        cwd=AI_ROOT, capture_output=True, text=True, timeout=120,
    )
    assert proc.returncode == 0, proc.stdout + proc.stderr
    assert "Comparison" in proc.stdout
    assert out.is_file()
