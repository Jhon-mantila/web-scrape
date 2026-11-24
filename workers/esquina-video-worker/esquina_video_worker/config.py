from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class WorkerConfig:
    api_url: str
    token: str
    poll_seconds: float
    work_dir: Path
    ollama_url: str | None

    @classmethod
    def from_env(cls) -> WorkerConfig:
        _load_dotenv(Path(__file__).resolve().parents[1] / ".env")
        api_url = os.environ.get("ESQUINA_API_URL", "").rstrip("/")
        token = os.environ.get("ESQUINA_WORKER_TOKEN", "").strip()
        if not api_url or not token:
            raise SystemExit(
                "Faltan ESQUINA_API_URL o ESQUINA_WORKER_TOKEN. "
                "Cópialos a workers/esquina-video-worker/.env"
            )

        work_dir = Path(os.environ.get("ESQUINA_WORK_DIR", str(Path.home() / ".cache/esquina-video-worker")))
        ollama = os.environ.get("ESQUINA_OLLAMA_URL", "").strip() or None

        return cls(
            api_url=api_url,
            token=token,
            poll_seconds=float(os.environ.get("ESQUINA_POLL_SECONDS", "10")),
            work_dir=work_dir,
            ollama_url=ollama,
        )


def _load_dotenv(path: Path) -> None:
    if not path.is_file():
        return

    for line in path.read_text(encoding="utf-8").splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or "=" not in stripped:
            continue
        key, _, value = stripped.partition("=")
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))
