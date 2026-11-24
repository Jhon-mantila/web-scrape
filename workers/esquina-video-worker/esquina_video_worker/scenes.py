from __future__ import annotations

from pathlib import Path


def detect_scenes(path: Path) -> list[tuple[int, int]]:
    try:
        from scenedetect import SceneManager, open_video
        from scenedetect.detectors import ContentDetector
    except ImportError:
        return []

    video = open_video(str(path))
    manager = SceneManager()
    manager.add_detector(ContentDetector())
    manager.detect_scenes(video)
    scenes = manager.get_scene_list()
    ranges: list[tuple[int, int]] = []
    for start, end in scenes:
        ranges.append((int(start.get_seconds() * 1000), int(end.get_seconds() * 1000)))
    return ranges
