from __future__ import annotations

import json
from datetime import datetime

from fastapi import APIRouter, Depends, File, Form, UploadFile
from fastapi.concurrency import run_in_threadpool
from pydantic import TypeAdapter, ValidationError

from app.api.dependencies import get_app_settings, get_pipeline
from app.core.config import Settings
from app.core.exceptions import ImageTooLargeError, InputValidationError
from app.schemas.analysis import AnalysisResponse
from app.schemas.report import CandidateReport, ReportInput
from app.services.duplicate_service import RequestCandidateProvider
from app.services.pipeline import ReportAnalysisPipeline

router = APIRouter(tags=["analysis"])

_CHUNK = 1024 * 1024
_MAX_CANDIDATES_ACCEPTED = 500
_candidates_adapter = TypeAdapter(list[CandidateReport])


async def _read_limited(upload: UploadFile, max_bytes: int) -> bytes:
    buffer = bytearray()
    while chunk := await upload.read(_CHUNK):
        buffer.extend(chunk)
        if len(buffer) > max_bytes:
            raise ImageTooLargeError(
                f"Image exceeds the maximum size of {max_bytes / (1024 * 1024):.1f} MB.",
                details={"max_bytes": max_bytes},
            )
    return bytes(buffer)


def _validation_details(exc: ValidationError) -> list[dict[str, str]]:
    return [
        {"field": ".".join(str(p) for p in err["loc"]) or "body", "message": err["msg"]} for err in exc.errors()
    ]


def _parse_candidates(raw: str | None) -> list[CandidateReport]:
    if raw is None or not raw.strip():
        return []
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise InputValidationError("candidates must be a JSON array.", details={"reason": str(exc)}) from exc
    if not isinstance(data, list):
        raise InputValidationError("candidates must be a JSON array.")
    if len(data) > _MAX_CANDIDATES_ACCEPTED:
        raise InputValidationError(f"At most {_MAX_CANDIDATES_ACCEPTED} candidates are accepted.")
    try:
        return _candidates_adapter.validate_python(data)
    except ValidationError as exc:
        raise InputValidationError("Invalid candidate report.", details=_validation_details(exc)) from exc


@router.post(
    "/analyze",
    response_model=AnalysisResponse,
    summary="Analyse a citizen report",
    responses={
        413: {"description": "Image too large"},
        415: {"description": "Unsupported image type"},
        422: {"description": "Invalid input"},
    },
)
async def analyze(
    image: UploadFile = File(..., description="Report photo (JPEG, PNG or WebP)."),
    description: str = Form(..., description="Citizen description (English, Sinhala or Tamil)."),
    latitude: float = Form(..., description="WGS84 latitude, -90..90."),
    longitude: float = Form(..., description="WGS84 longitude, -180..180."),
    report_id: str = Form(..., description="Backend report id."),
    title: str | None = Form(None, description="Optional report title."),
    created_at: datetime | None = Form(None, description="Optional ISO-8601 submission time."),
    language: str | None = Form(None, description="Optional language hint: en, si or ta."),
    candidates: str | None = Form(
        None,
        description=(
            "Optional JSON array of existing reports for duplicate detection: "
            '[{"report_id","description","latitude","longitude","created_at","image_hash"}]'
        ),
    ),
    settings: Settings = Depends(get_app_settings),
    pipeline: ReportAnalysisPipeline = Depends(get_pipeline),
) -> AnalysisResponse:
    """Run the full multimodal analysis.

    Results are advisory: the service flags reports for human review and never
    deletes or rejects them. Each block carries a ``status`` telling whether
    it came from a trained model (``ok``), a development heuristic
    (``heuristic``) or is unavailable (``model_not_loaded`` / ``insufficient_data``).
    """
    try:
        report = ReportInput(
            report_id=report_id.strip(),
            description=description,
            latitude=latitude,
            longitude=longitude,
            title=title,
            created_at=created_at,
            language_hint=language,
        )
    except ValidationError as exc:
        raise InputValidationError("Invalid report fields.", details=_validation_details(exc)) from exc

    candidate_list = _parse_candidates(candidates)
    data = await _read_limited(image, settings.max_image_size_bytes)
    loaded = pipeline.image_service.load(data, image.content_type)

    return await run_in_threadpool(
        pipeline.analyze, report, loaded, RequestCandidateProvider(candidate_list)
    )
