"""Download the 24 openly licensed Wikimedia Commons photos used in the report's
screening evaluation into datasets/processed/external_eval/images/<category>/.
They are an external test set only (never used for training or tuning)."""
import csv, time, urllib.parse, urllib.request
from pathlib import Path

OUT = Path(__file__).resolve().parents[2] / "datasets" / "processed" / "external_eval"
UA = {"User-Agent": "CivicPulseDatasetBuilder/1.0 (academic research)"}
FILES = [
    ("Potholes in Bengaluru road.jpg", "pothole"), ("Roads deformed T munnekollala Bengaluru.jpg", "pothole"),
    ("Potholes in a road in Gaborone.jpg", "pothole"), ("Pothole in an asphalt pavement.jpg", "pothole"),
    ("Road in Nairobi, Kenya with large pothole Aug 10, 2025 04-27-36 PM.jpeg", "pothole"),
    ("Cracked pavement 1.jpg", "road_damage"), ("Storm Drain Clogged - Mid-City New Orleans.jpg", "blocked_drain"),
    ("India - Chennai - Monsoon - 06 (3059058000).jpg", "flooding"),
    ("Torrential rain lash Kolkata, many areas waterlogged- Kolkata-West Bengal-September 2021-IMG 20210915 205028.jpg", "flooding"),
    ("Flood in Batti (bridge).jpg", "flooding"),
    ("Damaged dangerous street light pole 20180524.jpg", "broken_street_light"),
    ("A fallen street light pole.jpg", "broken_street_light"),
    ("Damaged street light pole, 300 block of E. 26th Street (north side), Baltimore, MD 21218 (47243240531).jpg", "broken_street_light"),
    ("Kelvedon Hall Lane litter dump Kelvedon Hatch Essex England.jpg", "garbage"),
    ("Overflowing garbage.jpg", "garbage"), ("Fly tipping near High Beech, Essex.jpg", "garbage"),
    ("Litter bin rubbish Tottenham, London, England 1.jpg", "garbage"),
    ("Burst Water Main in Melbourne July 2019.jpg", "flooding"), ("Burst water pipe - geograph.org.uk - 6866868.jpg", "flooding"),
    ("Storm drains clogged - heavy rain on 7th avenue = flooding - panoramio.jpg", "flooding"),
    ("Fallen Tree in Dormer Place, Leamington Spa (1).jpg", "fallen_tree"),
    ("Collapsed wall on Wollaton Road - geograph.org.uk - 7661284.jpg", "damaged_public_property"),
    ("Wall collapse during Storm Eva, Boston Road, Wetherby (26th December 2015) 002.JPG", "damaged_public_property"),
    ("Moscow, Donskaya Street, fallen tree June 2022 01.jpg", "fallen_tree"),
]
rows = []
for i, (name, cat) in enumerate(FILES, 1):
    url = "https://commons.wikimedia.org/wiki/Special:FilePath/" + urllib.parse.quote(name) + "?width=640"
    path = OUT / "images" / cat / f"commons_{i:02d}.jpg"
    path.parent.mkdir(parents=True, exist_ok=True)
    if not path.exists():
        for attempt in range(4):
            try:
                path.write_bytes(urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=60).read())
                break
            except Exception as e:  # noqa: BLE001
                time.sleep(5 * (attempt + 1))
        time.sleep(2)
    rows.append([f"commons_{i:02d}", name, cat])
with open(OUT / "manifest.csv", "w", newline="", encoding="utf-8") as fh:
    w = csv.writer(fh); w.writerow(["image_id", "commons_file", "category"]); w.writerows(rows)
print(len(list((OUT / "images").rglob("*.jpg"))), "images")
