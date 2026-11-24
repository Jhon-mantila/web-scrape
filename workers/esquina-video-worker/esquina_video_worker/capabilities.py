from __future__ import annotations

import shutil
import subprocess
from typing import Any
from urllib.request import urlopen


def detect_capabilities(ollama_url: str | None = None) -> dict[str, Any]:
    ffmpeg = shutil.which("ffmpeg")
    ffprobe = shutil.which("ffprobe")
    nvenc = False
    if ffmpeg:
        encoded = _run([ffmpeg, "-hide_banner", "-encoders"])
        nvenc = "h264_nvenc" in encoded or "hevc_nvenc" in encoded

    ollama = False
    if ollama_url:
        try:
            with urlopen(ollama_url.rstrip("/") + "/api/tags", timeout=2) as response:
                ollama = response.status == 200
        except OSError:
            ollama = False

    scenedetect = False
    try:
        import scenedetect  # noqa: F401

        scenedetect = True
    except ImportError:
        scenedetect = False

    return {
        "ffmpeg": bool(ffmpeg),
        "ffprobe": bool(ffprobe),
        "nvenc": nvenc,
        "cpu_encode": True,
        "ollama": ollama,
        "scenedetect": scenedetect,
        "whisper": False,
    }


def _run(command: list[str]) -> str:
    try:
        completed = subprocess.run(command, check=False, capture_output=True, text=True, timeout=10)
    except (OSError, subprocess.TimeoutExpired):
        return ""
    return (completed.stdout or "") + (completed.stderr or "")
