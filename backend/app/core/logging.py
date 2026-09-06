"""Structured logging with guaranteed secret redaction."""

import logging
import sys

from app.core.config import Settings

_REDACTED = "***REDACTED***"


class SecretRedactionFilter(logging.Filter):
    """Replaces any configured secret value appearing in a log record."""

    def __init__(self, secrets: list[str]):
        super().__init__()
        self._secrets = [s for s in secrets if len(s) >= 8]

    def filter(self, record: logging.LogRecord) -> bool:
        if self._secrets:
            if isinstance(record.msg, str):
                for secret in self._secrets:
                    if secret in record.msg:
                        record.msg = record.msg.replace(secret, _REDACTED)
            if record.args:
                record.args = tuple(
                    (
                        "".join(
                            a.replace(s, _REDACTED) if isinstance(a, str) and s in a else a
                            for s in self._secrets
                        )
                        if isinstance(a, str)
                        else a
                    )
                    for a in record.args
                )
        return True


def configure_logging(settings: Settings) -> None:
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(
        logging.Formatter(
            fmt="%(asctime)s %(levelname)s [%(name)s] %(message)s",
            datefmt="%Y-%m-%dT%H:%M:%S",
        )
    )
    handler.addFilter(SecretRedactionFilter(settings.secret_values))

    root = logging.getLogger()
    root.handlers.clear()
    root.addHandler(handler)
    root.setLevel(settings.LOG_LEVEL.upper())

    # Quieten noisy access logs from dependencies; uvicorn logs stay separate.
    logging.getLogger("httpx").setLevel(logging.WARNING)
    logging.getLogger("httpcore").setLevel(logging.WARNING)


def get_logger(name: str) -> logging.Logger:
    return logging.getLogger(name)
