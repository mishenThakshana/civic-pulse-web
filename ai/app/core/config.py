"""Application configuration.

All settings are read from environment variables (and an optional ``.env`` file
in the ``ai/`` directory). Every model reference is optional: when a model is
not configured the service still starts and reports ``model_not_loaded`` for
the affected component.
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from typing import Literal

from pydantic import Field, field_validator, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

AI_ROOT = Path(__file__).resolve().parents[2]


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=AI_ROOT / ".env",
        env_file_encoding="utf-8",
        extra="ignore",
        case_sensitive=False,
    )

    # --- Service -----------------------------------------------------------
    app_env: Literal["development", "test", "staging", "production"] = "development"
    host: str = "0.0.0.0"
    port: int = Field(default=8000, ge=1, le=65535)
    log_level: str = "INFO"
    service_name: str = "civi-pulse-ai"
    service_version: str = "0.1.0"

    # --- Models (all optional; empty => model_not_loaded) -------------------
    # Hugging Face model id or local directory for issue-category text classification.
    text_model_name: str | None = None
    # Hugging Face model id or local directory for severity text classification.
    severity_model_name: str | None = None
    # Sentence Transformers model id or local directory used for embeddings.
    embedding_model_name: str | None = None
    # Local path to trained Ultralytics YOLO weights (.pt).
    image_model_path: Path | None = None
    # Local path to a trained scikit-learn spam pipeline (.joblib).
    spam_model_path: Path | None = None
    # Languages the trained text and spam models were trained on. Reports in
    # any other language are still analysed, but the text model is skipped and
    # spam is judged by the language-independent rules only.
    model_languages: list[str] = ["en"]

    # When false, Hugging Face models are loaded with local_files_only=True so
    # nothing is downloaded at API startup.
    allow_model_download: bool = False
    # auto | cpu | cuda | mps
    device: str = "auto"
    # Rule-based development fallbacks used when a trained model is missing.
    # They are always labelled status="heuristic" in API responses.
    enable_heuristic_fallbacks: bool = True

    # --- Decision thresholds --------------------------------------------------
    spam_threshold: float = Field(default=0.70, ge=0.0, le=1.0)
    consistency_threshold: float = Field(default=0.70, ge=0.0, le=1.0)
    duplicate_threshold: float = Field(default=0.80, ge=0.0, le=1.0)
    validity_review_threshold: float = Field(default=0.50, ge=0.0, le=1.0)

    # --- Duplicate detection -------------------------------------------------
    duplicate_radius_meters: float = Field(default=150.0, gt=0)
    duplicate_time_window_hours: float = Field(default=168.0, gt=0)
    max_duplicate_candidates: int = Field(default=50, ge=0, le=500)

    # --- Input limits -------------------------------------------------------
    max_image_size_mb: float = Field(default=10.0, gt=0)
    min_image_dimension: int = Field(default=64, ge=1)
    max_image_pixels: int = Field(default=40_000_000, gt=0)
    max_description_length: int = Field(default=5000, gt=0)

    # Expected service area (default: Sri Lanka). Reports outside it are only
    # flagged as a spam signal, never rejected.
    service_area_min_lat: float = 5.85
    service_area_max_lat: float = 9.90
    service_area_min_lon: float = 79.50
    service_area_max_lon: float = 81.95

    @field_validator(
        "text_model_name",
        "severity_model_name",
        "embedding_model_name",
        "image_model_path",
        "spam_model_path",
        mode="before",
    )
    @classmethod
    def _empty_to_none(cls, value: object) -> object:
        if isinstance(value, str) and not value.strip():
            return None
        return value

    @field_validator("image_model_path", "spam_model_path", mode="after")
    @classmethod
    def _resolve_relative_path(cls, value: Path | None) -> Path | None:
        if value is not None and not value.is_absolute():
            return AI_ROOT / value
        return value

    @field_validator("log_level", mode="before")
    @classmethod
    def _upper_log_level(cls, value: object) -> object:
        return value.upper() if isinstance(value, str) else value

    @model_validator(mode="after")
    def _check_service_area(self) -> "Settings":
        if self.service_area_min_lat >= self.service_area_max_lat:
            raise ValueError("SERVICE_AREA_MIN_LAT must be < SERVICE_AREA_MAX_LAT")
        if self.service_area_min_lon >= self.service_area_max_lon:
            raise ValueError("SERVICE_AREA_MIN_LON must be < SERVICE_AREA_MAX_LON")
        return self

    @property
    def max_image_size_bytes(self) -> int:
        return int(self.max_image_size_mb * 1024 * 1024)


@lru_cache
def get_settings() -> Settings:
    return Settings()
