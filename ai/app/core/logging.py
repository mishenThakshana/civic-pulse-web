"""Logging setup.

Report descriptions and images are never logged: they may contain personal
information. Only report ids, statuses and timings are logged.
"""

from __future__ import annotations

import logging
import sys

_FORMAT = "%(asctime)s %(levelname)s [%(name)s] %(message)s"


def configure_logging(level: str = "INFO") -> None:
    root = logging.getLogger()
    root.setLevel(level)
    if not any(getattr(h, "_civi_pulse", False) for h in root.handlers):
        handler = logging.StreamHandler(sys.stdout)
        handler.setFormatter(logging.Formatter(_FORMAT))
        handler._civi_pulse = True  # type: ignore[attr-defined]
        root.addHandler(handler)


def get_logger(name: str) -> logging.Logger:
    return logging.getLogger(name)
