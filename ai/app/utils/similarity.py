"""Similarity and distance functions used by consistency and duplicate detection."""

from __future__ import annotations

import math
from collections.abc import Mapping, Sequence

import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics.pairwise import cosine_similarity as _sk_cosine

EARTH_RADIUS_METERS = 6_371_008.8


def cosine_similarity(a: Sequence[float] | np.ndarray, b: Sequence[float] | np.ndarray) -> float:
    """Cosine similarity in [-1, 1]; 0.0 if either vector has zero norm."""
    va = np.asarray(a, dtype=np.float64).ravel()
    vb = np.asarray(b, dtype=np.float64).ravel()
    if va.shape != vb.shape:
        raise ValueError(f"vector shapes differ: {va.shape} vs {vb.shape}")
    na, nb = np.linalg.norm(va), np.linalg.norm(vb)
    if na == 0.0 or nb == 0.0:
        return 0.0
    return float(np.clip(np.dot(va, vb) / (na * nb), -1.0, 1.0))


def cosine_to_unit(similarity: float) -> float:
    """Map cosine similarity from [-1, 1] to [0, 1]."""
    return max(0.0, min(1.0, (similarity + 1.0) / 2.0))


def haversine_meters(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Great-circle distance between two WGS84 points in metres."""
    phi1, phi2 = math.radians(lat1), math.radians(lat2)
    dphi = math.radians(lat2 - lat1)
    dlambda = math.radians(lon2 - lon1)
    h = math.sin(dphi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(dlambda / 2) ** 2
    return 2 * EARTH_RADIUS_METERS * math.asin(min(1.0, math.sqrt(h)))


def hamming_distance_hex(hash_a: str, hash_b: str) -> int:
    if len(hash_a) != len(hash_b):
        raise ValueError("hashes must have the same length")
    return bin(int(hash_a, 16) ^ int(hash_b, 16)).count("1")


def hash_similarity(hash_a: str, hash_b: str) -> float:
    """1.0 for identical perceptual hashes, 0.0 for maximally different ones."""
    bits = len(hash_a) * 4
    return 1.0 - hamming_distance_hex(hash_a, hash_b) / bits


def bhattacharyya_coefficient(p: Mapping[str, float], q: Mapping[str, float]) -> float:
    """Overlap of two discrete distributions over labels, in [0, 1]."""
    total_p, total_q = sum(p.values()), sum(q.values())
    if total_p <= 0 or total_q <= 0:
        return 0.0
    labels = set(p) | set(q)
    bc = sum(math.sqrt(max(p.get(k, 0.0), 0.0) / total_p * max(q.get(k, 0.0), 0.0) / total_q) for k in labels)
    return max(0.0, min(1.0, bc))


def proximity_score(distance_m: float, radius_m: float) -> float:
    """1.0 at the same spot, decaying linearly to 0.0 at ``radius_m``."""
    if radius_m <= 0:
        raise ValueError("radius must be positive")
    return max(0.0, 1.0 - distance_m / radius_m)


def recency_score(hours_apart: float, window_hours: float) -> float:
    """1.0 for simultaneous reports, decaying linearly to 0.0 at ``window_hours``."""
    if window_hours <= 0:
        raise ValueError("window must be positive")
    return max(0.0, 1.0 - abs(hours_apart) / window_hours)


def char_ngram_similarities(query: str, others: Sequence[str]) -> list[float]:
    """TF-IDF character n-gram cosine similarity of ``query`` to each of ``others``.

    Character n-grams work for English, Sinhala and Tamil without language-
    specific tokenisers. Used when no embedding model is loaded.
    """
    if not others:
        return []
    corpus = [query, *others]
    if not any(doc.strip() for doc in corpus):
        return [0.0] * len(others)
    vectorizer = TfidfVectorizer(analyzer="char_wb", ngram_range=(2, 4), lowercase=True)
    try:
        matrix = vectorizer.fit_transform(corpus)
    except ValueError:  # empty vocabulary
        return [0.0] * len(others)
    sims = _sk_cosine(matrix[0], matrix[1:]).ravel()
    return [float(max(0.0, min(1.0, s))) for s in sims]
