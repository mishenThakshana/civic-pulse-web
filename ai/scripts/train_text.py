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
    resolve,
)

REQUIRED_COLUMNS = ["report_id", "description", "category", "language", "severity"]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--task", choices=["category", "severity"], default="category")
    parser.add_argument("--validate-only", action="store_true")
    parser.add_argument("--resume", action="store_true", help="Continue from the last saved epoch checkpoint.")
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

    return train(config, task_cfg, label_column, allowed, frames, resume=args.resume)


def train(config, task_cfg, label_column, allowed, frames, resume: bool = False) -> int:
    import json

    import numpy as np
    import pandas as pd
    import torch
    from datasets import Dataset
    from sklearn.metrics import f1_score
    from transformers import (
        AutoModelForSequenceClassification,
        AutoTokenizer,
        DataCollatorWithPadding,
        Trainer,
        TrainingArguments,
        set_seed,
    )

    from app.utils.text import normalize_text

    set_seed(config["seed"])
    label2id = {lbl: i for i, lbl in enumerate(allowed)}
    id2label = {i: lbl for lbl, i in label2id.items()}
    tokenizer = AutoTokenizer.from_pretrained(config["base_model"])

    def to_ds(df):
        df = df.assign(
            text=df["description"].astype(str).map(normalize_text),
            label=df[label_column].astype(str).str.strip().str.lower().map(label2id),
        )
        ds = Dataset.from_pandas(df[["text", "label"]].reset_index(drop=True))
        return ds.map(lambda b: tokenizer(b["text"], truncation=True, max_length=config["max_length"]), batched=True)

    train_ds, val_ds, test_ds = (to_ds(frames[s]) for s in ("train", "val", "test"))
    model = AutoModelForSequenceClassification.from_pretrained(
        config["base_model"], num_labels=len(allowed), id2label=id2label, label2id=label2id
    )

    def metrics(pred):
        y = pred.label_ids
        p = np.argmax(pred.predictions, axis=-1)
        return {"accuracy": float((p == y).mean()), "f1_macro": float(f1_score(y, p, average="macro"))}

    out_dir = resolve(task_cfg["output_dir"])
    args = TrainingArguments(
        output_dir=str(out_dir / "checkpoints"),
        num_train_epochs=config["epochs"],
        learning_rate=config["learning_rate"],
        per_device_train_batch_size=config["batch_size"],
        per_device_eval_batch_size=config["batch_size"] * 2,
        weight_decay=config["weight_decay"],
        warmup_ratio=0.06,
        eval_strategy="epoch",
        save_strategy="epoch",
        load_best_model_at_end=True,
        metric_for_best_model="f1_macro",
        save_total_limit=1,
        logging_steps=50,
        seed=config["seed"],
        report_to=[],
        use_mps_device=torch.backends.mps.is_available(),
    )
    trainer = Trainer(
        model=model,
        args=args,
        train_dataset=train_ds,
        eval_dataset=val_ds,
        data_collator=DataCollatorWithPadding(tokenizer),
        compute_metrics=metrics,
    )
    trainer.train(resume_from_checkpoint=True if resume else None)
    val_metrics = trainer.evaluate(val_ds)
    trainer.save_model(str(out_dir))
    tokenizer.save_pretrained(str(out_dir))

    pred = trainer.predict(test_ds)
    probs = torch.softmax(torch.tensor(pred.predictions), dim=-1).numpy()
    rows = pd.DataFrame(
        {
            "report_id": frames["test"]["report_id"].values,
            "y_true": [id2label[i] for i in pred.label_ids],
            "y_pred": [id2label[i] for i in probs.argmax(-1)],
        }
    )
    for i, lbl in id2label.items():
        rows[f"score_{lbl}"] = probs[:, i].round(5)
    rows.to_csv(out_dir / "test_predictions.csv", index=False)
    history = [h for h in trainer.state.log_history if "eval_f1_macro" in h or "loss" in h]
    (out_dir / "train_summary.json").write_text(
        json.dumps({"validation": val_metrics, "history": history}, indent=2, default=float)
    )
    print(f"[train] saved model to {out_dir}; TEST predictions written")
    return EXIT_OK

if __name__ == "__main__":
    sys.exit(main())
