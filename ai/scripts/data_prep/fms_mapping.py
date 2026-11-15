"""Map FixMyStreet council categories (service_name) to the CivicPulse taxonomy.

Labels come from the category the citizen chose on FixMyStreet. Order matters:
the first matching rule wins. Returns None for categories that are excluded
(not public infrastructure, or ambiguous).
"""

from __future__ import annotations

import re

EXCLUDE = re.compile(
    r"traffic light|traffic signal|signal|road marking|lining|marking|parking|car park|grass|weed|sweep|"
    r"clean|bin (collection|missed)|missed|assisted|council tax|noise|licen|planning|enquiry|request|"
    r"tree planting|pruning|hedge|overhang|vegetation|shrub|temporary|roadworks|road works|street works|"
    r"gritting|snow|ice|salt|cycle|bus stop timetable|other$|^other",
    re.I,
)
FALLEN = re.compile(
    r"\b(fallen|fell|blown (down|over)|uprooted|come down|came down|collapsed|snapped|down across|"
    r"across the (road|path|pavement)|blocking the (road|path|pavement))\b",
    re.I,
)
RULES: list[tuple[str, re.Pattern[str]]] = [
    ("pothole", re.compile(r"pot ?hole", re.I)),
    ("flooding", re.compile(r"flood|standing water|surface water|water (on|covering)|flowing water|ponding", re.I)),
    ("blocked_drain", re.compile(r"drain|gull(y|ies|eys)|culvert|ditch|sewer|catchpit", re.I)),
    (
        "broken_street_light",
        re.compile(r"street ?light|lamp|public lighting|street lighting|light (out|not|on during)|lights? out|"
                   r"bulb|flicker|all out", re.I),
    ),
    ("damaged_sign", re.compile(r"\bsigns?\b|signage|name ?plate", re.I)),
    ("garbage", re.compile(r"fly.?tip|flytip|rubbish|litter|refuse|dumped|waste", re.I)),
    (
        "damaged_public_property",
        re.compile(r"graffiti|bench|shelter|bollard|railing|barrier|fence|street furniture|play area|playground|"
                   r"vandal|guardrail|structures", re.I),
    ),
    ("road_damage", re.compile(r"road surface|surface (condition|problem|issue)|carriageway|crack|uneven|"
                               r"road defect|damage to road|damaged road|road damage|reinstatement|road edge|"
                               r"street defect", re.I)),
    ("other", re.compile(r"abandoned vehicle|dog (fouling|waste|mess)|dead animal|mud|obstruct", re.I)),
]
TREE = re.compile(r"\btrees?\b", re.I)


def map_category(service_name: str, description: str = "") -> str | None:
    name = (service_name or "").strip()
    if TREE.search(name):
        return "fallen_tree" if FALLEN.search(description or "") else None
    if EXCLUDE.search(name) and not re.search(r"pot ?hole|flood|drain|gull", name, re.I):
        return None
    for label, pattern in RULES:
        if pattern.search(name):
            return label
    return None
