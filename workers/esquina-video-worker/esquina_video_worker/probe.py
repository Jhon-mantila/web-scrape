from __future__ import annotations

import json
import shutil
import subprocess
from dataclasses import dataclass
from pathlib import Path


@dataclass
class Probe:
    duration_ms: int
    width: int
    height: int
    fps: float
    codec: str | None
    has_audio: bool


def probe_video(path: Path) -> Probe:
    ffprobe = shutil.which("ffprobe")
    if not ffprobe:
        raise RuntimeError("ffprobe no está en PATH. Instala FFmpeg en WSL.")

    command = [
        ffprobe,
        "-v",
        "error",
        "-show_streams",
        "-show_format",
        "-print_format",
        "json",
        str(path),
    ]
    completed = subprocess.run(command, check=True, capture_output=True, text=True)
    payload = json.loads(completed.stdout)
    streams = payload.get("streams") or []
    video = next((item for item in streams if item.get("codec_type") == "video"), {})
    audio = next((item for item in streams if item.get("codec_type") == "audio"), None)
    duration = float(payload.get("format", {}).get("duration") or video.get("duration") or 0)
    fps_raw = str(video.get("avg_frame_rate") or video.get("r_frame_rate") or "0/1")
    num, _, den = fps_raw.partition("/")
    fps = float(num) / float(den or 1) if den else float(num or 0)

    return Probe(
        duration_ms=int(duration * 1000),
        width=int(video.get("width") or 0),
        height=int(video.get("height") or 0),
        fps=fps,
        codec=video.get("codec_name"),
        has_audio=audio is not None,
    )
