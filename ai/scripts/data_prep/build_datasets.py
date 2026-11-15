"""Build datasets/processed/ from the raw sources in datasets/raw/.

Sources (see datasets/README_DATASET.txt for licences):
  road_issues_hf/train.parquet   Road Issues Detection Dataset (CC0-1.0)
  fixmystreet/days/*.json        public FixMyStreet reports (Open311 API)
  sms_spam/SMSSpamCollection     UCI SMS Spam Collection (CC BY 4.0)

Usage (from ai/):
  python scripts/data_prep/build_datasets.py images   # downloads report photos, builds image_classification/
  python scripts/data_prep/build_datasets.py text      # builds text/ and spam/

Splits are 70/15/15, seeded, and GROUPED (one location / one report never spans
two splits). EXIF is removed by re-encoding every image.
"""

from __future__ import annotations

import csv
import glob
import hashlib
import io
import json
import random
import re
import sys
import time
import urllib.request
from collections import defaultdict
from pathlib import Path

AI = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(AI))
sys.path.insert(0, str(Path(__file__).parent))
from fms_mapping import map_category  # noqa: E402

RAW, OUT = AI / "datasets" / "raw", AI / "datasets" / "processed"
SEED = 42
UA = {"User-Agent": "CivicPulseDatasetBuilder/1.0 (academic research)"}

# Some networks advertise IPv6 but drop it; prefer IPv4 so downloads do not wait for IPv6 timeouts.
import socket  # noqa: E402

_getaddrinfo = socket.getaddrinfo
socket.getaddrinfo = lambda host, *a, **k: [r for r in _getaddrinfo(host, *a, **k) if r[0] == socket.AF_INET] or _getaddrinfo(host, *a, **k)
HF_LABELS = {0: "damaged_sign", 1: "road_damage", 3: "garbage", 5: "pothole", 6: "damaged_public_property"}
HF_CAP, FMS_CAP = 700, 400


def split_of(group: str) -> str:
    h = int(hashlib.sha256(f"{SEED}:{group}".encode()).hexdigest(), 16) % 100
    return "train" if h < 70 else "val" if h < 85 else "test"


def save_jpeg(data: bytes, path: Path) -> bool:
    from PIL import Image, ImageOps

    try:
        im = Image.open(io.BytesIO(data))
        im = ImageOps.exif_transpose(im).convert("RGB")
        if min(im.size) < 160:
            return False
        im.thumbnail((256, 256))  # one size for every source, so resolution cannot act as a shortcut
        path.parent.mkdir(parents=True, exist_ok=True)
        im.save(path, "JPEG", quality=90)  # re-encoding drops EXIF (incl. GPS)
        return True
    except Exception:  # noqa: BLE001
        return False


def fms_reports():
    seen = set()
    for f in sorted(glob.glob(str(RAW / "fixmystreet" / "days" / "*.json"))):
        for r in json.load(open(f, encoding="utf-8"))["service_requests"]:
            rid = r["service_request_id"]
            if rid in seen:
                continue
            seen.add(rid)
            r["category"] = map_category(r.get("service_name", ""), r.get("detail", ""))
            yield r


def build_images() -> None:
    import pyarrow.parquet as pq

    rng = random.Random(SEED)
    root = OUT / "image_classification"
    manifest = []

    # 1. Road Issues Detection Dataset (CC0). Group = location prefix of the file name.
    table = pq.read_table(RAW / "road_issues_hf" / "train.parquet").to_pylist()
    by_cat = defaultdict(lambda: defaultdict(list))
    for row in table:
        cat = HF_LABELS.get(row["label"])
        if cat:
            name = row["image"]["path"]
            group = re.split(r"_A\d|_jpg|\.rf\.", name)[0]
            by_cat[cat][group].append(row)
    for cat, groups in by_cat.items():
        keys = sorted(groups)
        rng.shuffle(keys)
        n = 0
        for g in keys:
            if n >= HF_CAP:
                break
            for row in groups[g][:2]:  # at most two shots per location
                img_id = "hf_" + hashlib.md5(row["image"]["path"].encode()).hexdigest()[:12]
                split = split_of("hf:" + g)
                if save_jpeg(row["image"]["bytes"], root / split / cat / f"{img_id}.jpg"):
                    manifest.append([img_id, "road_issues_hf", "CC0-1.0", cat, split, row["image"]["path"]])
                    n += 1

    # 2. FixMyStreet report photos. One photo per report; group = report id.
    candidates = defaultdict(list)
    for r in fms_reports():
        if r["category"] and r.get("media_url"):
            candidates[r["category"]].append(r)
    import http.client
    from urllib.parse import urlsplit

    lic = "FixMyStreet public report (training use only)"
    conn = None

    def get(url: str) -> bytes | None:
        """One kept-alive HTTPS connection; FixMyStreet's smaller photo variant."""
        nonlocal conn
        parts = urlsplit(url.replace(".full.jpeg", ".jpeg"))
        for attempt in range(3):
            try:
                if conn is None:
                    conn = http.client.HTTPSConnection(parts.netloc, timeout=120)
                conn.request("GET", f"{parts.path}?{parts.query}", headers={**UA, "Connection": "keep-alive"})
                resp = conn.getresponse()
                body = resp.read()
                if resp.status == 200:
                    return body
                time.sleep(5)
            except Exception:  # noqa: BLE001
                conn = None
                time.sleep(5 * (attempt + 1))
        return None

    for cat, rows in sorted(candidates.items()):
        rng.shuffle(rows)
        n = 0
        cap = FMS_CAP // 2 if cat in HF_LABELS.values() else FMS_CAP  # HF already covers these
        for r in rows:
            if n >= cap:
                break
            img_id = f"fms_{r['service_request_id']}"
            split = split_of(f"fms:{r['service_request_id']}")
            path = root / split / cat / f"{img_id}.jpg"
            if not path.exists():
                data = get(r["media_url"])
                time.sleep(0.8)
                if not data or not save_jpeg(data, path):
                    continue
            manifest.append([img_id, "fixmystreet", lic, cat, split, r["media_url"]])
            n += 1
        print(f"[images] fixmystreet {cat}: {n}", flush=True)

    # remove images from earlier runs that are no longer selected, so folders match the manifest
    keep = {m[0] for m in manifest}
    for img in root.rglob("*.jpg"):
        if img.stem not in keep:
            img.unlink()

    # normalise any image saved by an earlier run to the same size
    from PIL import Image

    for img in root.rglob("*.jpg"):
        with Image.open(img) as im:
            big = max(im.size) > 256
        if big:
            save_jpeg(img.read_bytes(), img)

    with open(root / "manifest.csv", "w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(["image_id", "source", "license", "category", "split", "original"])
        w.writerows(manifest)
    print(f"[images] {len(manifest)} images written")


def clean_text(text: str) -> str:
    text = re.sub(r"\b0\d{9,10}\b|\+44\s?\d[\d ]{8,12}", "[phone]", text)
    text = re.sub(r"\S+@\S+\.\w+", "[email]", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text


def build_text() -> None:
    import pandas as pd

    rng = random.Random(SEED)
    rows = []
    for r in fms_reports():
        if not r["category"]:
            continue
        desc = clean_text(f"{r.get('title', '')}. {r.get('detail', '')}")
        if len(desc) < 15:
            continue
        rows.append({"report_id": f"fms_{r['service_request_id']}", "description": desc, "category": r["category"],
                     "language": "en", "severity": "unlabelled", "source": "fixmystreet"})
    df = pd.DataFrame(rows).drop_duplicates("description")
    # balance: cap the very large classes so every category is learnable
    df = df.groupby("category", group_keys=False).apply(lambda g: g.sample(min(len(g), 1500), random_state=SEED))
    df["split"] = df["report_id"].map(split_of)
    (OUT / "text").mkdir(parents=True, exist_ok=True)
    for s in ("train", "val", "test"):
        df[df.split == s].drop(columns="split").to_csv(OUT / "text" / f"{s}.csv", index=False)
    print("[text]", df.groupby(["split", "category"]).size().unstack(0).to_string())

    # Spam: legitimate = FixMyStreet reports; spam = SMS spam (promotional) + unrelated SMS chat (irrelevant).
    sms = pd.read_csv(RAW / "sms_spam" / "SMSSpamCollection", sep="\t", header=None, names=["label", "text"],
                      quoting=3, encoding="latin-1")
    spam_rows = []
    for i, r in sms.iterrows():
        kind = "promotional" if r.label == "spam" else "irrelevant"
        spam_rows.append({"report_id": f"sms_{i}", "description": clean_text(str(r.text)), "is_spam": 1,
                          "spam_type": kind, "language": "en"})
    spam = pd.DataFrame(spam_rows)
    spam = pd.concat([spam[spam.spam_type == "promotional"],
                      spam[spam.spam_type == "irrelevant"].sample(1200, random_state=SEED)])
    legit = df.sample(min(len(df), 4500), random_state=SEED)
    legit = pd.DataFrame({"report_id": legit.report_id, "description": legit.description, "is_spam": 0,
                          "spam_type": "legitimate", "language": "en"})
    all_rows = pd.concat([legit, spam]).sample(frac=1, random_state=SEED)
    all_rows["split"] = all_rows["report_id"].map(split_of)
    (OUT / "spam").mkdir(parents=True, exist_ok=True)
    for s in ("train", "val", "test"):
        all_rows[all_rows.split == s].drop(columns="split").to_csv(OUT / "spam" / f"{s}.csv", index=False)
    print("[spam]", all_rows.groupby(["split", "spam_type"]).size().unstack(0).to_string())


if __name__ == "__main__":
    {"images": build_images, "text": build_text}[sys.argv[1]]()
