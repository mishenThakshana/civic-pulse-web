"""Input schemas: the citizen report and duplicate-detection candidates."""

from __future__ import annotations

import math
from datetime import datetime, timezone
from enum import Enum
from typing import Annotated

from pydantic import BaseModel, ConfigDict, Field, field_validator


class IssueCategory(str, Enum):
    """Issue taxonomy shared by image and text models.

    Trained models MUST emit exactly these labels (see
    PROJECT_DATASET_REQUIREMENTS.txt). Unknown labels are mapped to ``other``.
    """

    ROAD_DAMAGE = "road_damage"
    POTHOLE = "pothole"
    GARBAGE = "garbage"
    FLOODING = "flooding"
    BLOCKED_DRAIN = "blocked_drain"
    BROKEN_STREET_LIGHT = "broken_street_light"
    FALLEN_TREE = "fallen_tree"
    DAMAGED_SIGN = "damaged_sign"
    DAMAGED_PUBLIC_PROPERTY = "damaged_public_property"
    OTHER = "other"

    @classmethod
    def values(cls) -> list[str]:
        return [c.value for c in cls]

    @classmethod
    def normalize(cls, label: str) -> "IssueCategory":
        key = label.strip().lower().replace(" ", "_").replace("-", "_")
        try:
            return cls(key)
        except ValueError:
            return cls.OTHER


class SeverityLevel(str, Enum):
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"

    @classmethod
    def values(cls) -> list[str]:
        return [s.value for s in cls]


REPORT_ID_PATTERN = r"^[A-Za-z0-9][A-Za-z0-9_.:\-]{0,127}$"

Latitude = Annotated[float, Field(ge=-90.0, le=90.0, allow_inf_nan=False)]
Longitude = Annotated[float, Field(ge=-180.0, le=180.0, allow_inf_nan=False)]


def _check_coordinates(latitude: float, longitude: float) -> None:
    if not (math.isfinite(latitude) and math.isfinite(longitude)):
        raise ValueError("coordinates must be finite numbers")
    if not -90.0 <= latitude <= 90.0:
        raise ValueError("latitude must be between -90 and 90")
    if not -180.0 <= longitude <= 180.0:
        raise ValueError("longitude must be between -180 and 180")


class GeoPoint(BaseModel):
    latitude: Latitude
    longitude: Longitude


def validate_coordinates(latitude: float, longitude: float) -> GeoPoint:
    """Validate a latitude/longitude pair (raises ``ValueError``)."""
    _check_coordinates(latitude, longitude)
    return GeoPoint(latitude=latitude, longitude=longitude)


def _ensure_aware(value: datetime | None) -> datetime | None:
    if value is not None and value.tzinfo is None:
        return value.replace(tzinfo=timezone.utc)
    return value


class CandidateReport(BaseModel):
    """An existing report supplied by the Civi Pulse backend for duplicate checks.

    The AI service has no database of its own: the backend decides which
    candidates are relevant (e.g. nearby, recent, still open) and sends them.
    """

    model_config = ConfigDict(extra="ignore")

    report_id: str = Field(pattern=REPORT_ID_PATTERN)
    description: str | None = Field(default=None, max_length=20000)
    latitude: Latitude
    longitude: Longitude
    created_at: datetime | None = None
    category: str | None = None
    # Perceptual hash previously returned by this service in
    # ``image.perceptual_hash``; lets the backend enable image similarity
    # without re-sending candidate images.
    image_hash: str | None = Field(default=None, pattern=r"^[0-9a-fA-F]{16}$")

    @field_validator("created_at")
    @classmethod
    def _aware(cls, value: datetime | None) -> datetime | None:
        return _ensure_aware(value)


class ReportInput(BaseModel):
    """A validated citizen report as seen by the analysis pipeline."""

    report_id: str = Field(pattern=REPORT_ID_PATTERN)
    description: str
    latitude: Latitude
    longitude: Longitude
    title: str | None = None
    created_at: datetime | None = None
    language_hint: str | None = None

    @field_validator("created_at")
    @classmethod
    def _aware(cls, value: datetime | None) -> datetime | None:
        return _ensure_aware(value)
