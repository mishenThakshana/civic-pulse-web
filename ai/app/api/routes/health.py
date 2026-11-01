from __future__ import annotations

from fastapi import APIRouter, Depends

from app.api.dependencies import get_app_settings, get_registry
from app.core.config import Settings
from app.models.registry import ModelRegistry
from app.schemas.analysis import HealthResponse, ModelsResponse

router = APIRouter(tags=["health"])


@router.get("/health", response_model=HealthResponse, summary="Liveness check")
def health(settings: Settings = Depends(get_app_settings)) -> HealthResponse:
    """Returns ok whenever the process is serving requests, with or without trained models."""
    return HealthResponse(status="ok", service=settings.service_name)


@router.get("/models", response_model=ModelsResponse, summary="Model load status")
def models(
    settings: Settings = Depends(get_app_settings), registry: ModelRegistry = Depends(get_registry)
) -> ModelsResponse:
    """Which models are loaded. Components whose model is not ready report ``model_not_loaded``."""
    return ModelsResponse(models=registry.statuses(), heuristic_fallbacks_enabled=settings.enable_heuristic_fallbacks)
