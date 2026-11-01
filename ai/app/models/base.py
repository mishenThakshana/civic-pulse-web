"""Common base class for every model wrapper.

Contract for all model interfaces (ImageModel, TextModel, SpamModel,
EmbeddingModel):

* ``load()``      - try to load weights. Never raises: failures are recorded and
                    exposed through ``status()`` so the API can still start.
* ``is_ready()``  - True only once real weights are loaded.
* ``predict()`` / ``encode()`` - raise ``ModelNotLoadedError`` when not ready.

Heavy ML libraries (torch, ultralytics, transformers, sentence-transformers)
are imported lazily inside ``load()`` so that the API, tests and scripts work
without them being initialised.
"""

from __future__ import annotations

from abc import ABC, abstractmethod

from app.core.exceptions import ModelNotLoadedError
from app.core.logging import get_logger
from app.schemas.analysis import ModelStatus

logger = get_logger(__name__)


class BaseModelWrapper(ABC):
    #: Short role name used in logs and the /models endpoint.
    role: str = "model"

    def __init__(self, source: str | None = None) -> None:
        self.source = source
        self._ready = False
        self._error: str | None = None

    @property
    def name(self) -> str:
        return f"{type(self).__name__}({self.source})" if self.source else type(self).__name__

    def is_ready(self) -> bool:
        return self._ready

    def load(self) -> None:
        if self._ready:
            return
        if not self.source:
            self._error = "not configured"
            logger.info("%s model not configured; component will report model_not_loaded", self.role)
            return
        try:
            self._load()
        except Exception as exc:  # noqa: BLE001 - a broken model must not stop the API
            self._ready = False
            self._error = f"{type(exc).__name__}: {exc}"
            logger.warning("Failed to load %s model from %s: %s", self.role, self.source, self._error)
            return
        self._ready = True
        self._error = None
        logger.info("Loaded %s model from %s", self.role, self.source)

    @abstractmethod
    def _load(self) -> None:
        """Load weights; raise on failure."""

    def _require_ready(self) -> None:
        if not self._ready:
            raise ModelNotLoadedError(f"{self.role} model is not loaded", details={"reason": self._error})

    def status(self) -> ModelStatus:
        return ModelStatus(name=self.role, ready=self._ready, source=self.source, error=self._error)


def resolve_device(preference: str) -> str:
    """Resolve DEVICE=auto|cpu|cuda|mps to a concrete torch device string.

    Always falls back to CPU, so a GPU is never required.
    """
    preference = (preference or "auto").lower()
    if preference == "cpu":
        return "cpu"
    try:
        import torch
    except ImportError:
        return "cpu"
    if preference in ("auto", "cuda") and torch.cuda.is_available():
        return "cuda"
    if preference in ("auto", "mps") and getattr(torch.backends, "mps", None) and torch.backends.mps.is_available():
        return "mps"
    return "cpu"
