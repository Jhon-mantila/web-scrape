from __future__ import annotations

import json
import logging
import re
from typing import Any


def sanitize_text(message: str, token: str | None = None) -> str:
    redacted = message
    if token:
        redacted = redacted.replace(token, "***")
    redacted = re.sub(r"(?i)(bearer\s+)[A-Za-z0-9._\-]+", r"\1***", redacted)
    redacted = re.sub(
        r"(?i)(authorization[\"']?\s*[:=]\s*[\"']?bearer\s+)[A-Za-z0-9._\-]+",
        r"\1***",
        redacted,
    )
    return redacted


def format_log_record(event: str, payload: dict[str, Any], token: str | None = None) -> str:
    safe = json.loads(json.dumps(payload))
    headers = safe.get("headers")
    if isinstance(headers, dict):
        for key in list(headers):
            if key.lower() == "authorization":
                headers[key] = "Bearer ***"
    return sanitize_text(f"{event} {json.dumps(safe, ensure_ascii=False)}", token)


class TokenFilter(logging.Filter):
    def __init__(self, token: str) -> None:
        super().__init__()
        self.token = token

    def filter(self, record: logging.LogRecord) -> bool:
        record.msg = sanitize_text(str(record.msg), self.token)
        if record.args:
            record.args = tuple(
                sanitize_text(str(arg), self.token) if not isinstance(arg, (int, float)) else arg
                for arg in record.args
            )
        return True


def configure_logging(token: str | None = None) -> logging.Logger:
    logger = logging.getLogger("esquina_video_worker")
    if not logger.handlers:
        handler = logging.StreamHandler()
        handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
        logger.addHandler(handler)
    logger.setLevel(logging.INFO)
    if token:
        logger.addFilter(TokenFilter(token))
    return logger
