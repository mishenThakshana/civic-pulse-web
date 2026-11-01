"""Train multilingual text classifiers (issue category and severity).

Usage (from ai/):
    python scripts/train_text.py --task category --validate-only
    python scripts/train_text.py --task severity

Expects datasets/processed/text/{train,val,test}.csv with columns
report_id, description, category, language, severity
(see PROJECT_DATASET_REQUIREMENTS.txt, sections 2, 4 and 7).
"""

from __future__ import annotations

import argparse
import sys

from _common import (
    CATEGORIES,
    EXIT_OK,
    SEVERITIES,
    DatasetCheck,
    check_csv_splits,
    load_config,
    not_implemented,
    resolve,
)

REQUIRED_COLUMNS = ["report_id", "description", "category", "language", "severity"]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--task", choices=["category", "severity"], default="category")
    parser.add_argument("--validate-only", action="store_true")
    args = parser.parse_args(argv)

    config = load_config("text")
    task_cfg = config["tasks"][args.task]
    label_column = task_cfg["label_column"]
    allowed = CATEGORIES if args.task == "category" else SEVERITIES

    check = DatasetCheck(f"text ({args.task})")
    frames = check_csv_splits(
        check,
        resolve(config["dataset_dir"]),
        REQUIRED_COLUMNS,
        label_column=label_column,
        allowed_labels=allowed,
        min_rows=config.get("min_rows"),
    )
    for split, df in frames.items():
        if "description" in df.columns:
            short = (df["description"].astype(str).str.strip().str.len() < 5).sum()
            if short:
                check.warn(f"{split}: {short} descriptions shorter than 5 characters")

    code = check.report()
    if code != EXIT_OK or args.validate_only:
        return code

    return not_implemented(
        f"text {args.task} fine-tuning",
        [
            f"Tokenizer/model: AutoModelForSequenceClassification.from_pretrained('{config['base_model']}', "
            f"num_labels={len(allowed)}, id2label/label2id from the allowed labels)",
            "Normalise descriptions with app.utils.text.normalize_text (same as inference).",
            "Fine-tune with transformers.Trainer using hyper-parameters from training/text/config.json;"
            " select checkpoint by macro-F1 on VAL.",
            "Report metrics per language (en/si/ta) as well as overall.",
            f"save_pretrained() tokenizer + model to {task_cfg['output_dir']}/ and set "
            f"{task_cfg['env_var']} to that directory in .env.",
        ],
    )


if __name__ == "__main__":
    sys.exit(main())
