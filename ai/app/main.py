"""Civi Pulse AI service entry point.

Run locally:  uvicorn app.main:app --reload --port 8000
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.concurrency import run_in_threadpool

from app.api.routes import analysis, health
from app.core.config import Settings, get_settings
from app.core.exceptions import register_exception_handlers
from app.core.logging import configure_logging, get_logger
from app.models.registry import ModelRegistry
from app.services.pipeline import ReportAnalysisPipeline

API_PREFIX = "/api/v1"
logger = get_logger(__name__)


def create_app(settings: Settings | None = None, registry: ModelRegistry | None = None) -> FastAPI:
    settings = settings or get_settings()
    configure_logging(settings.log_level)

    @asynccontextmanager
    async def lifespan(app: FastAPI) -> AsyncIterator[None]:
        # Only locally configured models are loaded; failures are logged and the
        # API still starts (components report model_not_loaded).
        await run_in_threadpool(app.state.registry.load_all)
        ready = [k for k, s in app.state.registry.statuses().items() if s.ready]
        logger.info("%s started (env=%s); models ready: %s", settings.service_name, settings.app_env, ready or "none")
        yield

    app = FastAPI(
        title="Civi Pulse AI",
        version=settings.service_version,
        description=(
            "Independent AI service for analysing Civi Pulse citizen reports: issue category, spam, "
            "severity, image/text consistency, duplicates and validity. Results are advisory only."
        ),
        lifespan=lifespan,
    )
    app.state.settings = settings
    app.state.registry = registry or ModelRegistry.from_settings(settings)
    app.state.pipeline = ReportAnalysisPipeline(settings, app.state.registry)

    register_exception_handlers(app)
    app.include_router(health.router, prefix=API_PREFIX)
    app.include_router(analysis.router, prefix=API_PREFIX)
    return app


app = create_app()
