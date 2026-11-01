"""Shared helpers for the training / evaluation scripts.

Exit codes used by every script:
    0  success (or --validate-only and the dataset is valid)
    2  dataset missing
    3  dataset present but invalid
    4  dataset valid, but the training step is not implemented yet (TODO)
"""

from __future__ import annotations

import json
import sys
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

AI_ROOT = Path(__file__).resolve().parents[1]
if str(AI_ROOT) not in sys.path:
    sys.path.insert(0, str(AI_ROOT))

from app.schemas.report import IssueCategory, SeverityLevel  # noqa: E402

EXIT_OK = 0
EXIT_MISSING = 2
EXIT_INVALID = 3
EXIT_NOT_IMPLEMENTED = 4

SPLITS = ("train", "val", "test")
LANGUAGES = ("en", "si", "ta")
IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp"}
CATEGORIES = IssueCategory.values()
SEVERITIES = SeverityLevel.values()


def load_config(task: str) -> dict[str, Any]:
    path = AI_ROOT / "training" / task / "config.json"
    if not path.is_file():
        raise SystemExit(f"[config] missing {rel(path)}")
    with path.open(encoding="utf-8") as fh:
        config = json.load(fh)
    print(f"[config] loaded training/{task}/config.json")
    return config


def rel(path: Path) -> str:
    """Path relative to ai/ for messages (absolute if outside)."""
    try:
        return str(path.resolve().relative_to(AI_ROOT))
    except ValueError:
        return str(path)


def resolve(path_str: str) -> Path:
    path = Path(path_str)
    return path if path.is_absolute() else AI_ROOT / path


@dataclass
class DatasetCheck:
    name: str
    errors: list[str] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)
    info: list[str] = field(default_factory=list)
    missing: bool = False

    def error(self, msg: str) -> None:
        self.errors.append(msg)

    def warn(self, msg: str) -> None:
        self.warnings.append(msg)

    def note(self, msg: str) -> None:
        self.info.append(msg)

    def report(self) -> int:
        print(f"\n=== Dataset check: {self.name} ===")
        for msg in self.info:
            print(f"  [info]    {msg}")
        for msg in self.warnings:
            print(f"  [warning] {msg}")
        for msg in self.errors:
            print(f"  [error]   {msg}")
        if self.missing:
            print("  RESULT: dataset MISSING - see PROJECT_DATASET_REQUIREMENTS.txt")
            return EXIT_MISSING
        if self.errors:
            print("  RESULT: dataset INVALID - fix the errors above")
            return EXIT_INVALID
        print("  RESULT: dataset structure OK")
        return EXIT_OK


def check_csv_splits(
    check: DatasetCheck,
    directory: Path,
    required_columns: list[str],
    *,
    label_column: str | None = None,
    allowed_labels: list[str] | None = None,
    id_columns: tuple[str, ...] = ("report_id",),
    min_rows: dict[str, int] | None = None,
) -> dict[str, Any]:
    """Validate ``{train,val,test}.csv`` in ``directory``; returns loaded DataFrames."""
    import pandas as pd

    frames: dict[str, Any] = {}
    if not directory.is_dir():
        check.missing = True
        check.error(f"directory not found: {rel(directory)}")
        return frames

    for split in SPLITS:
        path = directory / f"{split}.csv"
        if not path.is_file():
            check.missing = True
            check.error(f"missing split file: {rel(path)}")
            continue
        try:
            df = pd.read_csv(path, encoding="utf-8")
        except Exception as exc:  # noqa: BLE001
            check.error(f"{split}.csv could not be read: {exc}")
            continue
        frames[split] = df
        missing_cols = [c for c in required_columns if c not in df.columns]
        if missing_cols:
            check.error(f"{split}.csv missing columns: {missing_cols}")
            continue
        if df.empty:
            check.error(f"{split}.csv has no rows")
            continue
        blank = df[required_columns].isna().any(axis=1).sum()
        if blank:
            check.error(f"{split}.csv has {blank} rows with empty required fields")
        for col in id_columns:
            dupes = df[col].duplicated().sum() if len(id_columns) == 1 else 0
            if dupes:
                check.error(f"{split}.csv has {dupes} duplicate {col} values")
        if len(id_columns) > 1:
            pair_dupes = df.duplicated(subset=list(id_columns)).sum()
            if pair_dupes:
                check.error(f"{split}.csv has {pair_dupes} duplicate ({', '.join(id_columns)}) pairs")
        if label_column and allowed_labels is not None:
            labels = df[label_column].astype(str).str.strip().str.lower()
            bad = sorted(set(labels) - set(allowed_labels))
            if bad:
                check.error(f"{split}.csv has unknown {label_column} values: {bad[:10]}")
            counts = labels.value_counts().to_dict()
            check.note(f"{split}: {len(df)} rows; {label_column} counts: {counts}")
            absent = [lbl for lbl in allowed_labels if lbl not in counts]
            if absent and split == "train":
                check.warn(f"train split has no examples of: {absent}")
        else:
            check.note(f"{split}: {len(df)} rows")
        if "language" in df.columns:
            langs = df["language"].astype(str).str.lower().value_counts().to_dict()
            check.note(f"{split}: language counts: {langs}")
            unknown = sorted(set(langs) - set(LANGUAGES))
            if unknown:
                check.error(f"{split}.csv has unsupported language codes: {unknown} (use en, si, ta)")
            missing_langs = [lang for lang in LANGUAGES if lang not in langs]
            if missing_langs:
                check.warn(f"{split} split has no examples in: {missing_langs}")
        if min_rows and len(df) < min_rows.get(split, 0):
            check.warn(f"{split} has {len(df)} rows; recommended minimum is {min_rows[split]}")

    check_split_isolation(check, {s: _ids(df, id_columns) for s, df in frames.items()})
    return frames


def _ids(df: Any, id_columns: tuple[str, ...]) -> set[str]:
    ids: set[str] = set()
    for col in id_columns:
        if col in df.columns:
            ids |= set(df[col].dropna().astype(str))
    return ids


def check_split_isolation(check: DatasetCheck, ids_by_split: dict[str, set[str]]) -> None:
    """The test split must never share reports with train/val (data leakage)."""
    pairs = [("train", "test"), ("val", "test"), ("train", "val")]
    for a, b in pairs:
        if a in ids_by_split and b in ids_by_split:
            overlap = ids_by_split[a] & ids_by_split[b]
            if overlap:
                check.error(
                    f"LEAKAGE: {len(overlap)} report ids appear in both {a} and {b} "
                    f"(e.g. {sorted(overlap)[:3]})"
                )


def check_image_class_folders(
    check: DatasetCheck, root: Path, categories: list[str], min_per_class: dict[str, int] | None = None
) -> dict[str, dict[str, int]]:
    """Validate ``root/{train,val,test}/<category>/*.jpg`` (YOLO classification layout)."""
    counts: dict[str, dict[str, int]] = {}
    if not root.is_dir():
        check.missing = True
        check.error(f"directory not found: {rel(root)}")
        return counts
    stems_by_split: dict[str, set[str]] = {}
    for split in SPLITS:
        split_dir = root / split
        if not split_dir.is_dir():
            check.missing = True
            check.error(f"missing split directory: {rel(split_dir)}")
            continue
        extra = sorted(p.name for p in split_dir.iterdir() if p.is_dir() and p.name not in categories)
        if extra:
            check.error(f"{split}: unknown category folders {extra}")
        counts[split] = {}
        stems: set[str] = set()
        for cat in categories:
            files = [p for p in (split_dir / cat).glob("*") if p.suffix.lower() in IMAGE_EXTENSIONS]
            counts[split][cat] = len(files)
            stems |= {p.stem for p in files}
            need = (min_per_class or {}).get(split, 0)
            if len(files) == 0:
                (check.error if split == "train" else check.warn)(f"{split}/{cat}: no images")
            elif len(files) < need:
                check.warn(f"{split}/{cat}: {len(files)} images (recommended >= {need})")
        stems_by_split[split] = stems
        check.note(f"{split}: {sum(counts[split].values())} images")
    check_split_isolation(check, stems_by_split)
    return counts


def check_detection_dataset(check: DatasetCheck, data_yaml: Path, categories: list[str]) -> None:
    """Validate a YOLO detection dataset (images/ + labels/ per split + data.yaml)."""
    if not data_yaml.is_file():
        check.missing = True
        check.error(f"missing {rel(data_yaml)}")
        return
    root = data_yaml.parent
    for split in SPLITS:
        img_dir, lbl_dir = root / "images" / split, root / "labels" / split
        if not img_dir.is_dir() or not lbl_dir.is_dir():
            check.missing = True
            check.error(f"missing images/{split} or labels/{split}")
            continue
        images = [p for p in img_dir.glob("*") if p.suffix.lower() in IMAGE_EXTENSIONS]
        unlabeled = [p.name for p in images if not (lbl_dir / f"{p.stem}.txt").is_file()]
        check.note(f"{split}: {len(images)} images, {len(images) - len(unlabeled)} with label files")
        if unlabeled:
            check.warn(f"{split}: {len(unlabeled)} images without label files (treated as background)")
        for lbl in lbl_dir.glob("*.txt"):
            for n, line in enumerate(lbl.read_text(encoding="utf-8").splitlines(), 1):
                parts = line.split()
                if not parts:
                    continue
                if len(parts) != 5:
                    check.error(f"{split}/{lbl.name}:{n} expected 'class cx cy w h'")
                    break
                cls, *coords = parts
                if not cls.isdigit() or int(cls) >= len(categories):
                    check.error(f"{split}/{lbl.name}:{n} invalid class index {cls}")
                    break
                if any(not 0.0 <= float(c) <= 1.0 for c in coords):
                    check.error(f"{split}/{lbl.name}:{n} coordinates must be normalised 0-1")
                    break


def not_implemented(step: str, todo: list[str]) -> int:
    print(f"\n[TODO] {step} is not implemented yet. No model was trained and no model file was written.")
    for item in todo:
        print(f"  - {item}")
    return EXIT_NOT_IMPLEMENTED
