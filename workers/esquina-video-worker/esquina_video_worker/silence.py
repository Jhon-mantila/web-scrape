from __future__ import annotations

import re
import shutil
import subprocess
from pathlib import Path

_START = re.compile(r"silence_start:\s*(-?\d+(?:\.\d+)?)")
_END = re.compile(r"silence_end:\s*(-?\d+(?:\.\d+)?)")


def detect_silences(path: Path, noise_db: float = -30.0, min_duration: float = 0.35) -> list[tuple[int, int]]:
    ffmpeg = shutil.which("ffmpeg")
    if not ffmpeg:
        return []

    command = [
        ffmpeg,
        "-hide_banner",
        "-i",
        str(path),
        "-af",
        f"silencedetect=noise={noise_db}dB:d={min_duration}",
        "-f",
        "null",
        "-",
    ]
    completed = subprocess.run(command, check=False, capture_output=True, text=True)
    output = (completed.stderr or "") + (completed.stdout or "")
    starts = [float(match.group(1)) for match in _START.finditer(output)]
    ends = [float(match.group(1)) for match in _END.finditer(output)]
    pairs: list[tuple[int, int]] = []
    for index, start in enumerate(starts):
        end = ends[index] if index < len(ends) else start
        if end > start:
            pairs.append((int(start * 1000), int(end * 1000)))
    return pairs
