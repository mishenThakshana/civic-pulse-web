"""FastAPI dependencies. Routes receive services through these, never globals."""

from __future__ import annotations

from fastapi import Request

from app.core.config import Settings
from app.models.registry import ModelRegistry
from app.services.pipeline import ReportAnalysisPipeline


def get_app_settings(request: Request) -> Settings:
    return request.app.state.settings


def get_registry(request: Request) -> ModelRegistry:
    return request.app.state.registry


def get_pipeline(request: Request) -> ReportAnalysisPipeline:
    return request.app.state.pipeline
