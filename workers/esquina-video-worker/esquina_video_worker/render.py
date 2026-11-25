from __future__ import annotations

import re
import shutil
import subprocess
from pathlib import Path


def build_ffmpeg_command(
    *,
    source: Path,
    dest: Path,
    start_ms: int,
    end_ms: int,
    framing: str,
    width: int,
    height: int,
    nvenc: bool,
    template: Path | None = None,
    fit_fill: str = "blur",
    part_label: str | None = None,
    part_label_color: str = "#FFFFFF",
    source_width: int = 0,
    source_height: int = 0,
) -> list[str]:
    ffmpeg = shutil.which("ffmpeg") or "ffmpeg"
    duration = max(1, end_ms - start_ms) / 1000
    start = max(0, start_ms) / 1000
    encoder = ["-c:v", "h264_nvenc", "-preset", "p4", "-cq", "19"] if nvenc else ["-c:v", "libx264", "-preset", "fast", "-crf", "18"]
    use_template = framing == "fit" and template is not None
    fill = fit_fill if framing == "fit" else "black"
    label = part_label if framing == "fit" else None
    uses_complex = framing == "blurred_background" or (framing == "fit" and (fill == "blur" or use_template or bool(label)))

    command = [
        ffmpeg,
        "-hide_banner",
        "-y",
        "-ss",
        f"{start:.3f}",
        "-t",
        f"{duration:.3f}",
        "-i",
        str(source),
    ]
    if use_template:
        command += ["-loop", "1", "-i", str(template)]

    filters = _video_filter(
        framing,
        width,
        height,
        use_template,
        fill,
        part_label=label,
        part_label_color=part_label_color,
        source_width=source_width,
        source_height=source_height,
    )

    if uses_complex:
        command += ["-filter_complex", filters, "-map", "[vout]"]
    else:
        command += ["-vf", filters, "-map", "0:v:0"]

    command += [
        *encoder,
        "-map",
        "0:a?",
        "-c:a",
        "aac",
        "-b:a",
        "192k",
        "-shortest",
        "-movflags",
        "+faststart",
        "-pix_fmt",
        "yuv420p",
        str(dest),
    ]
    return command


def render_clip(
    *,
    source: Path,
    dest: Path,
    start_ms: int,
    end_ms: int,
    framing: str,
    width: int,
    height: int,
    nvenc: bool,
    template: Path | None = None,
    fit_fill: str = "blur",
    part_label: str | None = None,
    part_label_color: str = "#FFFFFF",
    source_width: int = 0,
    source_height: int = 0,
) -> Path:
    dest.parent.mkdir(parents=True, exist_ok=True)
    command = build_ffmpeg_command(
        source=source,
        dest=dest,
        start_ms=start_ms,
        end_ms=end_ms,
        framing=framing,
        width=width,
        height=height,
        nvenc=nvenc,
        template=template,
        fit_fill=fit_fill,
        part_label=part_label,
        part_label_color=part_label_color,
        source_width=source_width,
        source_height=source_height,
    )
    completed = subprocess.run(command, check=False, capture_output=True, text=True)
    if completed.returncode != 0:
        if nvenc:
            return render_clip(
                source=source,
                dest=dest,
                start_ms=start_ms,
                end_ms=end_ms,
                framing=framing,
                width=width,
                height=height,
                nvenc=False,
                template=template,
                fit_fill=fit_fill,
                part_label=part_label,
                part_label_color=part_label_color,
                source_width=source_width,
                source_height=source_height,
            )
        raise RuntimeError(completed.stderr[-4000:] if completed.stderr else "ffmpeg falló")
    return dest


def _video_filter(
    framing: str,
    width: int,
    height: int,
    use_template: bool,
    fit_fill: str,
    *,
    part_label: str | None = None,
    part_label_color: str = "#FFFFFF",
    source_width: int = 0,
    source_height: int = 0,
) -> str:
    lanczos = "flags=lanczos:force_divisible_by=2"
    label_filter = _part_label_filter(
        part_label,
        part_label_color,
        width,
        height,
        source_width,
        source_height,
    )

    if framing == "blurred_background":
        return _blur_stack(width, height, lanczos, use_template)

    if framing == "smart_crop":
        return (
            f"crop='min(iw,ih*{width}/{height})':'min(ih,iw*{height}/{width})':(iw-ow)/2:(ih-oh)/2,"
            f"scale=w='min(iw\\,{width})':h='min(ih\\,{height})':force_original_aspect_ratio=decrease:{lanczos}"
        )

    if framing == "fit" and fit_fill == "blur":
        graph = _blur_stack(width, height, lanczos, use_template)
        return _append_label(graph, label_filter, complex_graph=True)

    pad_color = "white" if fit_fill == "white" else "black"
    if use_template:
        graph = (
            f"color=c={pad_color}:s={width}x{height}:r=30[base];"
            f"[1:v]scale={width}:{height}:force_original_aspect_ratio=increase:{lanczos},"
            f"crop={width}:{height},format=rgba,setsar=1[tpl];"
            f"[0:v]scale={width}:{height}:force_original_aspect_ratio=decrease:{lanczos},setsar=1[fg];"
            f"[base][tpl]overlay=0:0:shortest=1:format=auto[filled];"
            f"[filled][fg]overlay=(W-w)/2:(H-h)/2:shortest=1:format=auto[vout]"
        )
        return _append_label(graph, label_filter, complex_graph=True)

    graph = (
        f"scale={width}:{height}:force_original_aspect_ratio=decrease:{lanczos},"
        f"pad={width}:{height}:({width}-iw)/2:({height}-ih)/2:{pad_color}"
    )
    if label_filter:
        return f"[0:v]{graph},{label_filter}[vout]"
    return graph


def _blur_stack(width: int, height: int, lanczos: str, use_template: bool) -> str:
    stack = (
        f"[0:v]split[bg][fg];"
        f"[bg]scale={width}:{height}:force_original_aspect_ratio=increase:{lanczos},"
        f"crop={width}:{height},gblur=sigma=24[blur];"
        f"[fg]scale={width}:{height}:force_original_aspect_ratio=decrease:{lanczos}[fit];"
    )
    if use_template:
        return (
            stack
            + f"[1:v]scale={width}:{height}:force_original_aspect_ratio=increase:{lanczos},"
            + f"crop={width}:{height},format=rgba,setsar=1[tpl];"
            + "[blur][tpl]overlay=0:0:shortest=1:format=auto[filled];"
            + "[filled][fit]overlay=(W-w)/2:(H-h)/2:shortest=1:format=auto[vout]"
        )
    return stack + "[blur][fit]overlay=(W-w)/2:(H-h)/2:format=auto[vout]"


def _append_label(graph: str, label_filter: str | None, *, complex_graph: bool) -> str:
    if not label_filter:
        return graph
    if complex_graph:
        if graph.endswith("[vout]"):
            return graph[: -len("[vout]")] + f"[composed];[composed]{label_filter}[vout]"
        return graph
    return f"{graph},{label_filter}"


def _part_label_filter(
    text: str | None,
    color: str,
    canvas_width: int,
    canvas_height: int,
    source_width: int,
    source_height: int,
) -> str | None:
    if not text:
        return None

    fontsize = max(42, canvas_width // 15)
    y = _bottom_bar_center_y(canvas_width, canvas_height, source_width, source_height, fontsize)
    fontcolor = _ffmpeg_color(color)
    escaped = text.replace("\\", "\\\\").replace("'", "\\'").replace(":", "\\:")
    font = _font_path()
    pieces = [
        f"drawtext=text='{escaped}'",
        f"fontsize={fontsize}",
        f"fontcolor={fontcolor}",
        "x=(w-text_w)/2",
        f"y={y}",
        "borderw=4",
        "bordercolor=black",
        "expansion=none",
    ]
    if font:
        pieces.append(f"fontfile='{_escape_font_path(font)}'")
    return ":".join(pieces)


def _bottom_bar_center_y(canvas_w: int, canvas_h: int, source_w: int, source_h: int, fontsize: int) -> int:
    fitted_h = canvas_h
    if source_w > 0 and source_h > 0:
        scale = min(canvas_w / source_w, canvas_h / source_h)
        fitted_h = int(source_h * scale)
    bar = max(0, (canvas_h - fitted_h) // 2)
    if bar < fontsize + 16:
        return max(0, canvas_h - fontsize - 24)
    return canvas_h - bar // 2 - fontsize // 2


def _ffmpeg_color(value: str) -> str:
    raw = value.strip().lstrip("#")
    if re.fullmatch(r"[0-9A-Fa-f]{6}", raw):
        return "0x" + raw.upper()
    if raw.lower() in {"white", "black", "yellow", "red", "cyan"}:
        return raw.lower()
    return "white"


def _font_path() -> str | None:
    candidates = [
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
        "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
        "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf",
        "/usr/share/fonts/truetype/freefont/FreeSansBold.ttf",
        "/usr/share/fonts/TTF/DejaVuSans-Bold.ttf",
        "C:/Windows/Fonts/arialbd.ttf",
        "C:/Windows/Fonts/arial.ttf",
    ]
    for path in candidates:
        if Path(path).is_file():
            return path
    return None


def _escape_font_path(path: str) -> str:
    return path.replace("\\", "/").replace(":", "\\:").replace("'", "\\'")
