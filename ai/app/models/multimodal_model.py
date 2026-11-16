"""Multimodal (vision-language) screening model interface and its Gemini implementation.

A single multimodal call looks at the report photo AND the description
together and returns category, spam/abuse, severity and image/text
consistency in one structured assessment. When this model is ready the
pipeline uses it in place of the separate image / text / spam / severity
models; when it is not configured the per-component models and heuristics
are used as before.

The Gemini implementation talks to the Generative Language REST API over
HTTPS and asks for a JSON response constrained by ``RESPONSE_SCHEMA``.
"""

from __future__ import annotations

import base64
import io
import json
from abc import abstractmethod
from dataclasses import dataclass, field

from PIL import Image

from app.models.base import BaseModelWrapper
from app.schemas.report import IssueCategory, SeverityLevel

GEMINI_ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
# Overloaded (503) or rate-limited (429) responses are retried on the fallback model.
RETRYABLE_STATUS_CODES = frozenset({429, 503})
# Images are downscaled before upload: plenty for classification, keeps latency low.
MAX_UPLOAD_SIDE = 1024

SYSTEM_PROMPT = """You are the screening model of Civi Pulse, a Sri Lankan civic infrastructure reporting app.
Citizens submit ONE photo and a short description (English, Sinhala or Tamil) of a public infrastructure problem
(potholes, garbage, flooding, blocked drains, broken street lights, fallen trees, damaged signs or public property).

Assess the submission and answer ONLY with the requested JSON.

Spam / abuse means any of: advertising or promotion, scams, phishing, links or contact details used to sell
something, gibberish or keyboard mashing, jokes or test posts, offensive or sexual content, selfies or memes,
screenshots, photos that clearly show no public infrastructure issue, or a description unrelated to the photo
in a way that suggests a fake report. A genuine but blurry, dark or brief report is NOT spam.

spam_probability is 0-1. Give short machine-readable reasons in snake_case (e.g. "advertisement",
"unrelated_image", "gibberish_text", "contains_scam_link", "offensive_content"); empty list when genuine.
consistency_score is 0-1: how well the photo matches the description.
"""

RESPONSE_SCHEMA: dict = {
    "type": "OBJECT",
    "properties": {
        "category": {"type": "STRING", "enum": IssueCategory.values()},
        "category_confidence": {"type": "NUMBER"},
        "image_shows_issue": {"type": "BOOLEAN"},
        "spam_probability": {"type": "NUMBER"},
        "spam_reasons": {"type": "ARRAY", "items": {"type": "STRING"}},
        "severity": {"type": "STRING", "enum": SeverityLevel.values()},
        "severity_confidence": {"type": "NUMBER"},
        "consistency_score": {"type": "NUMBER"},
        "summary": {"type": "STRING"},
    },
    "required": [
        "category",
        "category_confidence",
        "image_shows_issue",
        "spam_probability",
        "spam_reasons",
        "severity",
        "severity_confidence",
        "consistency_score",
        "summary",
    ],
}


@dataclass
class MultimodalAssessment:
    category: str
    category_confidence: float
    image_shows_issue: bool
    spam_probability: float
    spam_reasons: list[str] = field(default_factory=list)
    severity: str = SeverityLevel.MEDIUM.value
    severity_confidence: float = 0.0
    consistency_score: float = 0.0
    summary: str = ""


class MultimodalModel(BaseModelWrapper):
    """Screens a whole report (photo + description) in one pass."""

    role = "multimodal"

    @abstractmethod
    def assess(self, image: Image.Image, description: str, context: str = "") -> MultimodalAssessment: ...


class GeminiMultimodalModel(MultimodalModel):
    def __init__(
        self,
        api_key: str | None,
        model_name: str,
        *,
        fallback_model_name: str | None = None,
        timeout_seconds: float = 20.0,
    ) -> None:
        # Without an API key the model counts as "not configured".
        super().__init__(model_name if api_key else None)
        self._api_key = api_key
        self._model_names = [m for m in (model_name, fallback_model_name) if m]
        self._timeout = timeout_seconds
        self._client = None

    def _load(self) -> None:
        import httpx

        self._client = httpx.Client(timeout=self._timeout)

    def assess(self, image: Image.Image, description: str, context: str = "") -> MultimodalAssessment:
        self._require_ready()
        prompt = f"Report description:\n\"\"\"\n{description}\n\"\"\"\n{context}".strip()
        body = {
            "systemInstruction": {"parts": [{"text": SYSTEM_PROMPT}]},
            "contents": [
                {
                    "role": "user",
                    "parts": [
                        {"inlineData": {"mimeType": "image/jpeg", "data": _encode_jpeg(image)}},
                        {"text": prompt},
                    ],
                }
            ],
            "generationConfig": {
                "temperature": 0.0,
                "responseMimeType": "application/json",
                "responseSchema": RESPONSE_SCHEMA,
            },
        }
        for index, model_name in enumerate(self._model_names):
            response = self._client.post(  # type: ignore[union-attr]
                GEMINI_ENDPOINT.format(model=model_name),
                headers={"x-goog-api-key": self._api_key or ""},
                json=body,
            )
            is_last = index == len(self._model_names) - 1
            if response.status_code in RETRYABLE_STATUS_CODES and not is_last:
                continue
            response.raise_for_status()
            break
        return parse_assessment(response.json())


def parse_assessment(payload: dict) -> MultimodalAssessment:
    """Turn a generateContent response into a validated assessment (raises ValueError)."""
    try:
        text = payload["candidates"][0]["content"]["parts"][0]["text"]
        data = json.loads(text)
    except (KeyError, IndexError, TypeError, json.JSONDecodeError) as exc:
        raise ValueError(f"unexpected multimodal response: {type(exc).__name__}") from exc

    severity = str(data.get("severity", "")).strip().lower()
    return MultimodalAssessment(
        category=IssueCategory.normalize(str(data.get("category", "other"))).value,
        category_confidence=_unit(data.get("category_confidence")),
        image_shows_issue=bool(data.get("image_shows_issue", False)),
        spam_probability=_unit(data.get("spam_probability")),
        spam_reasons=[str(r).strip().lower().replace(" ", "_") for r in data.get("spam_reasons") or [] if str(r).strip()],
        severity=severity if severity in SeverityLevel.values() else SeverityLevel.MEDIUM.value,
        severity_confidence=_unit(data.get("severity_confidence")),
        consistency_score=_unit(data.get("consistency_score")),
        summary=str(data.get("summary", ""))[:500],
    )


def _unit(value: object) -> float:
    try:
        return max(0.0, min(1.0, float(value)))  # type: ignore[arg-type]
    except (TypeError, ValueError):
        return 0.0


def _encode_jpeg(image: Image.Image) -> str:
    copy = image.convert("RGB")
    copy.thumbnail((MAX_UPLOAD_SIDE, MAX_UPLOAD_SIDE))
    buffer = io.BytesIO()
    copy.save(buffer, format="JPEG", quality=85)
    return base64.b64encode(buffer.getvalue()).decode("ascii")
