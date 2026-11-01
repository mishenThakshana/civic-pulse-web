"""Reusable evaluation metrics for every Civi Pulse AI task.

Used by ``scripts/evaluate.py`` and by the training scripts so that all
models are measured the same way and can be compared side by side.
"""

from __future__ import annotations

from collections.abc import Sequence
from dataclasses import asdict, dataclass, field
from typing import Any

import numpy as np
from sklearn.metrics import (
    accuracy_score,
    confusion_matrix,
    precision_recall_fscore_support,
    roc_auc_score,
)


@dataclass
class ClassificationReport:
    task: str
    n_samples: int
    labels: list[str]
    accuracy: float
    precision_macro: float
    recall_macro: float
    f1_macro: float
    precision_weighted: float
    recall_weighted: float
    f1_weighted: float
    per_class: dict[str, dict[str, float]]
    confusion_matrix: list[list[int]]
    roc_auc: float | None = None
    notes: list[str] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)

    def summary_row(self, model_name: str) -> dict[str, Any]:
        return {
            "model": model_name,
            "task": self.task,
            "n": self.n_samples,
            "accuracy": round(self.accuracy, 4),
            "precision_macro": round(self.precision_macro, 4),
            "recall_macro": round(self.recall_macro, 4),
            "f1_macro": round(self.f1_macro, 4),
            "roc_auc": None if self.roc_auc is None else round(self.roc_auc, 4),
        }


def evaluate_classification(
    y_true: Sequence[Any],
    y_pred: Sequence[Any],
    *,
    task: str = "classification",
    labels: Sequence[Any] | None = None,
    y_score: Sequence[float] | Sequence[Sequence[float]] | None = None,
    positive_label: Any = 1,
) -> ClassificationReport:
    """Accuracy, precision, recall, F1 (macro + weighted + per class), confusion
    matrix and ROC-AUC.

    ``y_score``: for binary tasks, the score/probability of ``positive_label``
    per sample; for multi-class, an (n, k) probability matrix ordered like
    ``labels`` (one-vs-rest macro ROC-AUC).
    """
    if len(y_true) != len(y_pred):
        raise ValueError("y_true and y_pred must have the same length")
    if len(y_true) == 0:
        raise ValueError("cannot evaluate an empty set")

    label_list = list(labels) if labels is not None else sorted(set(y_true) | set(y_pred), key=str)
    notes: list[str] = []

    precision, recall, f1, support = precision_recall_fscore_support(
        y_true, y_pred, labels=label_list, zero_division=0
    )
    p_macro, r_macro, f_macro, _ = precision_recall_fscore_support(
        y_true, y_pred, labels=label_list, average="macro", zero_division=0
    )
    p_w, r_w, f_w, _ = precision_recall_fscore_support(
        y_true, y_pred, labels=label_list, average="weighted", zero_division=0
    )
    per_class = {
        str(lbl): {
            "precision": float(precision[i]),
            "recall": float(recall[i]),
            "f1": float(f1[i]),
            "support": int(support[i]),
        }
        for i, lbl in enumerate(label_list)
    }
    missing = [str(lbl) for lbl, sup in zip(label_list, support) if sup == 0]
    if missing:
        notes.append(f"labels with no true samples: {', '.join(missing)}")

    roc_auc = None
    if y_score is not None:
        roc_auc = _roc_auc(y_true, y_score, label_list, positive_label, notes)

    return ClassificationReport(
        task=task,
        n_samples=len(y_true),
        labels=[str(lbl) for lbl in label_list],
        accuracy=float(accuracy_score(y_true, y_pred)),
        precision_macro=float(p_macro),
        recall_macro=float(r_macro),
        f1_macro=float(f_macro),
        precision_weighted=float(p_w),
        recall_weighted=float(r_w),
        f1_weighted=float(f_w),
        per_class=per_class,
        confusion_matrix=confusion_matrix(y_true, y_pred, labels=label_list).tolist(),
        roc_auc=roc_auc,
        notes=notes,
    )


def _roc_auc(y_true, y_score, labels, positive_label, notes: list[str]) -> float | None:
    scores = np.asarray(y_score, dtype=float)
    try:
        if scores.ndim == 1:
            y_bin = np.asarray([1 if y == positive_label else 0 for y in y_true])
            if len(set(y_bin.tolist())) < 2:
                notes.append("ROC-AUC undefined: only one class present in y_true")
                return None
            return float(roc_auc_score(y_bin, scores))
        present = set(y_true)
        if len(present) < 2:
            notes.append("ROC-AUC undefined: only one class present in y_true")
            return None
        # scikit-learn requires sorted labels; reorder the score columns to match.
        order = sorted(range(len(labels)), key=lambda i: str(labels[i]))
        sorted_labels = [labels[i] for i in order]
        return float(
            roc_auc_score(y_true, scores[:, order], multi_class="ovr", average="macro", labels=sorted_labels)
        )
    except ValueError as exc:
        notes.append(f"ROC-AUC not computed: {exc}")
        return None


def threshold_sweep(
    y_true: Sequence[int], y_score: Sequence[float], thresholds: Sequence[float] | None = None
) -> list[dict[str, float]]:
    """Precision/recall/F1 at several thresholds - used to choose SPAM_THRESHOLD,
    CONSISTENCY_THRESHOLD and DUPLICATE_THRESHOLD on the VALIDATION set."""
    thresholds = thresholds or [round(t, 2) for t in np.arange(0.05, 1.0, 0.05)]
    truth = np.asarray(y_true, dtype=int)
    scores = np.asarray(y_score, dtype=float)
    rows = []
    for t in thresholds:
        pred = (scores >= t).astype(int)
        p, r, f, _ = precision_recall_fscore_support(truth, pred, average="binary", zero_division=0)
        rows.append({"threshold": float(t), "precision": float(p), "recall": float(r), "f1": float(f)})
    return rows


def compare_reports(reports: dict[str, ClassificationReport]) -> list[dict[str, Any]]:
    """One summary row per model, sorted by macro F1 (best first)."""
    rows = [rep.summary_row(name) for name, rep in reports.items()]
    return sorted(rows, key=lambda r: r["f1_macro"], reverse=True)
