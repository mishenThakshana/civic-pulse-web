"""Text classification model interface and the Hugging Face implementation.

The same interface is used for two tasks, each with its own fine-tuned model:

* issue category   (TEXT_MODEL_NAME,     labels = IssueCategory values)
* severity         (SEVERITY_MODEL_NAME, labels = low / medium / high)

Recommended multilingual base models that cover English, Sinhala and Tamil
(to be fine-tuned on Civi Pulse data): ``xlm-roberta-base``,
``google/muril-base-cased`` (Indic incl. Tamil) or ``setu4993/LaBSE``.
Always verify language coverage on the Sri Lankan evaluation set.
"""

from __future__ import annotations

from abc import abstractmethod
from collections.abc import Callable
from dataclasses import dataclass, field

from app.models.base import BaseModelWrapper, resolve_device


@dataclass
class TextPrediction:
    label: str
    confidence: float
    scores: dict[str, float] = field(default_factory=dict)


class TextModel(BaseModelWrapper):
    role = "text"

    @abstractmethod
    def predict(self, text: str) -> TextPrediction: ...


class TransformersTextClassifier(TextModel):
    """Wraps a fine-tuned ``AutoModelForSequenceClassification`` checkpoint."""

    def __init__(
        self,
        model_name: str | None,
        *,
        role: str = "text",
        device: str = "auto",
        allow_download: bool = False,
        label_mapper: Callable[[str], str] | None = None,
        max_length: int = 256,
    ) -> None:
        super().__init__(model_name)
        self.role = role
        self._device_pref = device
        self._allow_download = allow_download
        self._label_mapper = label_mapper or (lambda s: s)
        self._max_length = max_length
        self._pipeline = None

    def _load(self) -> None:
        from transformers import AutoModelForSequenceClassification, AutoTokenizer, pipeline

        local_only = not self._allow_download
        tokenizer = AutoTokenizer.from_pretrained(self.source, local_files_only=local_only)
        model = AutoModelForSequenceClassification.from_pretrained(self.source, local_files_only=local_only)
        self._pipeline = pipeline(
            "text-classification",
            model=model,
            tokenizer=tokenizer,
            top_k=None,
            device=resolve_device(self._device_pref),
        )

    def predict(self, text: str) -> TextPrediction:
        self._require_ready()
        assert self._pipeline is not None
        raw = self._pipeline(text, truncation=True, max_length=self._max_length)
        rows = raw[0] if raw and isinstance(raw[0], list) else raw
        scores: dict[str, float] = {}
        for row in rows:
            label = self._label_mapper(str(row["label"]))
            scores[label] = scores.get(label, 0.0) + float(row["score"])
        label = max(scores, key=scores.get)  # type: ignore[arg-type]
        return TextPrediction(label=label, confidence=scores[label], scores=scores)
