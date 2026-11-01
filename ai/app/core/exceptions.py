"""Domain exceptions and their HTTP mapping.

Every error response has the shape::

    {"error": {"code": "...", "message": "...", "details": ...}}
"""

from __future__ import annotations

from typing import Any

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from app.core.logging import get_logger

logger = get_logger(__name__)


class CiviPulseAIError(Exception):
    status_code: int = 400
    code: str = "bad_request"

    def __init__(self, message: str, details: Any | None = None) -> None:
        super().__init__(message)
        self.message = message
        self.details = details


class InputValidationError(CiviPulseAIError):
    status_code = 422
    code = "invalid_input"


class InvalidImageError(CiviPulseAIError):
    status_code = 422
    code = "invalid_image"


class UnsupportedMediaTypeError(CiviPulseAIError):
    status_code = 415
    code = "unsupported_media_type"


class ImageTooLargeError(CiviPulseAIError):
    status_code = 413
    code = "image_too_large"


class ModelNotLoadedError(CiviPulseAIError):
    """Raised by a model wrapper when predict() is called before load()."""

    status_code = 503
    code = "model_not_loaded"


def _error_body(code: str, message: str, details: Any | None = None) -> dict[str, Any]:
    return {"error": {"code": code, "message": message, "details": details}}


def register_exception_handlers(app: FastAPI) -> None:
    @app.exception_handler(CiviPulseAIError)
    async def _handle_domain_error(_: Request, exc: CiviPulseAIError) -> JSONResponse:
        return JSONResponse(
            status_code=exc.status_code,
            content=_error_body(exc.code, exc.message, exc.details),
        )

    @app.exception_handler(RequestValidationError)
    async def _handle_request_validation(_: Request, exc: RequestValidationError) -> JSONResponse:
        details = [
            {"field": ".".join(str(p) for p in err.get("loc", ())), "message": err.get("msg")}
            for err in exc.errors()
        ]
        return JSONResponse(
            status_code=422,
            content=_error_body("invalid_input", "Request validation failed.", details),
        )

    @app.exception_handler(Exception)
    async def _handle_unexpected(_: Request, exc: Exception) -> JSONResponse:
        logger.exception("Unhandled error: %s", type(exc).__name__)
        return JSONResponse(
            status_code=500,
            content=_error_body("internal_error", "An unexpected error occurred."),
        )
