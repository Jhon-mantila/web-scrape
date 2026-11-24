from __future__ import annotations

from dataclasses import dataclass, field


@dataclass
class Segment:
    index: int
    start_ms: int
    end_ms: int
    source: str
    score: float = 0.0
    warnings: list[str] = field(default_factory=list)


@dataclass
class Proposal:
    segments: list[Segment]
    recommended_count: int
    reason: str | None = None


def propose_segments(
    *,
    duration_ms: int,
    target_count: int,
    min_ms: int,
    max_ms: int,
    scenes: list[tuple[int, int]] | None = None,
    silences: list[tuple[int, int]] | None = None,
    mode: str = "smart",
) -> Proposal:
    if duration_ms <= 0:
        raise ValueError("duration_ms must be positive")

    scene_ranges = _cover_duration(scenes or [], duration_ms)
    silences = silences or []

    if mode == "scene":
        units = [(start, end, "scene", []) for start, end in scene_ranges]
    else:
        units = _split_long_units(scene_ranges, silences, min_ms, max_ms)
        units = _merge_short_units(units, min_ms, max_ms)

    segments = [
        Segment(
            index=index,
            start_ms=start,
            end_ms=end,
            source=source,
            score=_score_unit(start, end, min_ms, max_ms, silences),
            warnings=warnings,
        )
        for index, (start, end, source, warnings) in enumerate(units)
    ]

    recommended = len(segments)
    reason = None
    if recommended != target_count:
        reason = (
            f"El contenido sugiere {recommended} clips naturales. "
            f"Forzar {target_count} cortaría escenas o explicaciones a mitad."
        )

    return Proposal(segments=segments, recommended_count=recommended, reason=reason)


def _cover_duration(scenes: list[tuple[int, int]], duration_ms: int) -> list[tuple[int, int]]:
    if not scenes:
        return [(0, duration_ms)]

    ordered = sorted((max(0, start), min(duration_ms, end)) for start, end in scenes if end > start)
    filled: list[tuple[int, int]] = []
    cursor = 0
    for start, end in ordered:
        if start > cursor:
            filled.append((cursor, start))
        filled.append((max(cursor, start), end))
        cursor = end
    if cursor < duration_ms:
        filled.append((cursor, duration_ms))
    return filled or [(0, duration_ms)]


def _split_long_units(
    scenes: list[tuple[int, int]],
    silences: list[tuple[int, int]],
    min_ms: int,
    max_ms: int,
) -> list[tuple[int, int, str, list[str]]]:
    units: list[tuple[int, int, str, list[str]]] = []
    for start, end in scenes:
        cursor = start
        warnings: list[str] = []
        while end - cursor > max_ms:
            cut = _best_internal_cut(cursor, end, silences, min_ms, max_ms)
            if cut is None:
                warnings.append("no_safe_internal_cut")
                break
            units.append((cursor, cut, "smart", []))
            cursor = cut
        units.append((cursor, end, "smart", warnings))
    return units


def _best_internal_cut(
    start: int,
    end: int,
    silences: list[tuple[int, int]],
    min_ms: int,
    max_ms: int,
) -> int | None:
    window_end = min(end - min_ms, start + max_ms)
    window_start = start + min_ms
    if window_end <= window_start:
        return None

    candidates: list[int] = []
    for silence_start, silence_end in silences:
        mid = (silence_start + silence_end) // 2
        if window_start <= mid <= window_end:
            candidates.append(mid)

    if not candidates:
        return None

    target = start + max_ms
    return min(candidates, key=lambda cut: abs(cut - target))


def _merge_short_units(
    units: list[tuple[int, int, str, list[str]]],
    min_ms: int,
    max_ms: int,
) -> list[tuple[int, int, str, list[str]]]:
    if not units:
        return []

    merged = [units[0]]
    for unit in units[1:]:
        start, end, source, warnings = merged[-1]
        next_start, next_end, next_source, next_warnings = unit
        current_len = end - start
        combined = next_end - start
        if current_len < min_ms and combined <= max_ms:
            merged[-1] = (start, next_end, "smart", warnings + next_warnings)
        else:
            merged.append(unit)
    return merged


def _score_unit(
    start: int,
    end: int,
    min_ms: int,
    max_ms: int,
    silences: list[tuple[int, int]],
) -> float:
    length = end - start
    duration_score = 1.0
    if length < min_ms:
        duration_score = 0.3
    elif length > max_ms:
        duration_score = 0.4

    ends_on_silence = any(abs(((silence_start + silence_end) // 2) - end) <= 1500 for silence_start, silence_end in silences)
    return round(duration_score + (0.4 if ends_on_silence else 0.0), 2)
