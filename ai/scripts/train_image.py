"""Train the image issue-category model (Ultralytics YOLO).

Usage (from ai/):
    python scripts/train_image.py --validate-only
    python scripts/train_image.py --task-type classify
    python scripts/train_image.py --task-type detect

Validates the dataset, fine-tunes the configured YOLO weights, evaluates the
best checkpoint once on the TEST split and writes test-set predictions to
models/image/test_predictions.csv for scripts/evaluate.py.
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
    resolve,
)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--task-type", choices=["classify", "detect"], help="Override config task_type.")
    parser.add_argument("--validate-only", action="store_true", help="Only validate the dataset.")
    parser.add_argument("--resume", action="store_true", help="Continue an interrupted run from its last.pt.")
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

    if task_type != "classify":
        print("[train] detection training needs box labels; only classification is implemented.")
        return 4
    return train_classifier(config, resume=args.resume)


def train_classifier(config: dict, resume: bool = False) -> int:
    from ultralytics import YOLO

    data_dir = resolve(config["classification_dataset_dir"])
    out_dir = resolve(config["output_dir"])
    out_dir.mkdir(parents=True, exist_ok=True)
    device = config.get("device") or ("mps" if _mps() else "cpu")

    last = out_dir / "run" / "weights" / "last.pt"
    if resume and last.is_file():
        YOLO(str(last)).train(resume=True)
    else:
        _fresh_train(YOLO(str(resolve(config["base_weights"]["classify"]))), config, data_dir, out_dir, device)
    return _finish(config, data_dir, out_dir, device)


def _fresh_train(model, config: dict, data_dir, out_dir, device: str) -> None:
    model.train(
        data=str(data_dir),
        epochs=config["epochs"],
        imgsz=config["imgsz"]["classify"],
        batch=config["batch"],
        patience=config["patience"],
        seed=config["seed"],
        deterministic=True,
        device=device,
        project=str(out_dir),
        name="run",
        exist_ok=True,
        verbose=False,
    )


def _finish(config: dict, data_dir, out_dir, device: str) -> int:
    import csv
    import json
    import shutil

    from ultralytics import YOLO

    best = out_dir / "run" / "weights" / "best.pt"
    final = out_dir / "image_classifier.pt"
    shutil.copy(best, final)

    # One evaluation on the held-out TEST split.
    model = YOLO(str(final))
    names = model.names
    rows = []
    for cat_dir in sorted((data_dir / "test").iterdir()):
        if not cat_dir.is_dir():
            continue
        for img in sorted(cat_dir.iterdir()):
            r = model.predict(str(img), imgsz=config["imgsz"]["classify"], device=device, verbose=False)[0]
            probs = r.probs.data.tolist()
            top = max(range(len(probs)), key=probs.__getitem__)
            rows.append({"report_id": img.stem, "y_true": cat_dir.name, "y_pred": names[top], "confidence": round(probs[top], 4)})
    pred_path = out_dir / "test_predictions.csv"
    with pred_path.open("w", newline="", encoding="utf-8") as fh:
        writer = csv.DictWriter(fh, fieldnames=list(rows[0]))
        writer.writeheader()
        writer.writerows(rows)
    acc = sum(r["y_true"] == r["y_pred"] for r in rows) / len(rows)
    (out_dir / "train_summary.json").write_text(json.dumps({"test_images": len(rows), "test_accuracy": acc}, indent=2))
    print(f"[train] saved {final}; TEST accuracy {acc:.4f} on {len(rows)} images; predictions in {pred_path}")
    return EXIT_OK


def _mps() -> bool:
    try:
        import torch

        return torch.backends.mps.is_available()
    except Exception:  # noqa: BLE001
        return False

if __name__ == "__main__":
    sys.exit(main())
