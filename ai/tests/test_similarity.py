import math

import pytest

from app.utils.similarity import (
    bhattacharyya_coefficient,
    char_ngram_similarities,
    cosine_similarity,
    cosine_to_unit,
    hamming_distance_hex,
    hash_similarity,
    haversine_meters,
    proximity_score,
    recency_score,
)


def test_cosine_similarity():
    assert cosine_similarity([1, 0], [1, 0]) == pytest.approx(1.0)
    assert cosine_similarity([1, 0], [0, 1]) == pytest.approx(0.0)
    assert cosine_similarity([1, 0], [-1, 0]) == pytest.approx(-1.0)
    assert cosine_similarity([0, 0], [1, 1]) == 0.0
    with pytest.raises(ValueError):
        cosine_similarity([1, 2], [1, 2, 3])
    assert cosine_to_unit(-1.0) == 0.0 and cosine_to_unit(1.0) == 1.0


def test_haversine_zero_and_known_distances():
    assert haversine_meters(6.9271, 79.8612, 6.9271, 79.8612) == 0.0
    # one degree of latitude ~ 111.2 km
    assert haversine_meters(0, 0, 1, 0) == pytest.approx(111_195, rel=1e-3)
    # Colombo Fort -> Kandy is ~94 km in a straight line
    assert haversine_meters(6.9344, 79.8428, 7.2906, 80.6337) == pytest.approx(94_000, rel=0.03)
    # symmetric, and ~11 m for 0.0001 degrees latitude
    a = haversine_meters(6.9271, 79.8612, 6.9272, 79.8612)
    assert a == pytest.approx(haversine_meters(6.9272, 79.8612, 6.9271, 79.8612))
    assert a == pytest.approx(11.1, abs=0.2)
    # antipodal points ~ half the circumference
    assert haversine_meters(0, 0, 0, 180) == pytest.approx(math.pi * 6_371_008.8, rel=1e-6)


def test_hash_similarity():
    assert hamming_distance_hex("ff", "00") == 8
    assert hash_similarity("0000000000000000", "0000000000000000") == 1.0
    assert hash_similarity("0000000000000000", "ffffffffffffffff") == 0.0
    assert hash_similarity("000000000000000f", "0000000000000000") == pytest.approx(1 - 4 / 64)
    with pytest.raises(ValueError):
        hamming_distance_hex("ff", "fff")


def test_bhattacharyya():
    p = {"pothole": 0.9, "garbage": 0.1}
    assert bhattacharyya_coefficient(p, p) == pytest.approx(1.0)
    assert bhattacharyya_coefficient({"pothole": 1.0}, {"garbage": 1.0}) == 0.0
    assert 0.0 < bhattacharyya_coefficient(p, {"pothole": 0.2, "garbage": 0.8}) < 1.0
    assert bhattacharyya_coefficient({}, p) == 0.0


def test_proximity_and_recency():
    assert proximity_score(0, 100) == 1.0
    assert proximity_score(50, 100) == 0.5
    assert proximity_score(150, 100) == 0.0
    assert recency_score(0, 24) == 1.0
    assert recency_score(-12, 24) == 0.5
    assert recency_score(48, 24) == 0.0
    with pytest.raises(ValueError):
        proximity_score(1, 0)


def test_char_ngram_similarities_multilingual():
    sims = char_ngram_similarities(
        "Large pothole near the school",
        ["Big pothole close to the school", "Garbage dumped beside the road", ""],
    )
    assert sims[0] > sims[1]
    assert sims[2] == 0.0
    si = char_ngram_similarities("පාසල අසල විශාල වළක්", ["පාසල අසල වළක්", "කුණු දමා ඇත"])
    assert si[0] > si[1]
    assert char_ngram_similarities("x", []) == []
