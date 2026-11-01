"""Train the image issue-category model (Ultralytics YOLO).

Usage (from ai/):
    python scripts/train_image.py --validate-only
    python scripts/train_image.py --task-type classify
    python scripts/train_image.py --task-type detect

Currently validates the dataset only. Training is a TODO until the dataset is
selected (see PROJECT_DATASET_REQUIREMENTS.txt, section 1).
"""

from __future__ import annotations

import argparse
import sys

from _common import (
    CATEGORIES,
    EXIT_OK,
    DatasetCheck,
    check_detection_dataset,
    check_image_class_folders,
    load_config,
    not_implemented,
    resolve,
)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--task-type", choices=["classify", "detect"], help="Override config task_type.")
    parser.add_argument("--validate-only", action="store_true", help="Only validate the dataset.")
    args = parser.parse_args(argv)

    config = load_config("image")
    task_type = args.task_type or config["task_type"]
    check = DatasetCheck(f"image ({task_type})")

    if task_type == "classify":
        check_image_class_folders(
            check, resolve(config["classification_dataset_dir"]), CATEGORIES, config.get("min_images_per_class")
        )
    else:
        check_detection_dataset(check, resolve(config["detection_data_yaml"]), CATEGORIES)

    code = check.report()
    if code != EXIT_OK or args.validate_only:
        return code

    return not_implemented(
        "YOLO image training",
        [
            f"from ultralytics import YOLO; model = YOLO('{config['base_weights'][task_type]}')",
            "model.train(data=<dataset dir or data.yaml>, epochs, imgsz, batch, patience, seed "
            "from training/image/config.json, project=models/image)",
            "Evaluate on the TEST split once, with scripts/evaluate.py, after choosing hyper-parameters on VAL.",
            "Copy best.pt to models/image/<version>.pt and set IMAGE_MODEL_PATH in .env.",
            "Record dataset version, metrics and date in models/README_MODELS.txt.",
        ],
    )


if __name__ == "__main__":
    sys.exit(main())
