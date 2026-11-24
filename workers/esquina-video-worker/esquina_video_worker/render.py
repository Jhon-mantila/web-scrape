from __future__ import annotations

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
) -> list[str]:
    ffmpeg = shutil.which("ffmpeg") or "ffmpeg"
    duration = max(1, end_ms - start_ms) / 1000
    start = max(0, start_ms) / 1000
    encoder = ["-c:v", "h264_nvenc", "-preset", "p4", "-cq", "19"] if nvenc else ["-c:v", "libx264", "-preset", "fast", "-crf", "18"]
    use_template = framing == "fit" and template is not None
    fill = fit_fill if framing == "fit" else "black"
    uses_complex = framing == "blurred_background" or (framing == "fit" and (fill == "blur" or use_template))

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

    if uses_complex:
        command += [
            "-filter_complex",
            _video_filter(framing, width, height, use_template, fill),
            "-map",
            "[vout]",
        ]
    else:
        command += ["-vf", _video_filter(framing, width, height, False, fill), "-map", "0:v:0"]

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
            )
        raise RuntimeError(completed.stderr[-4000:] if completed.stderr else "ffmpeg falló")
    return dest


def _video_filter(framing: str, width: int, height: int, use_template: bool, fit_fill: str) -> str:
    lanczos = "flags=lanczos:force_divisible_by=2"

    if framing == "blurred_background":
        return _blur_stack(width, height, lanczos, use_template)

    if framing == "smart_crop":
        return (
            f"crop='min(iw,ih*{width}/{height})':'min(ih,iw*{height}/{width})':(iw-ow)/2:(ih-oh)/2,"
            f"scale=w='min(iw\\,{width})':h='min(ih\\,{height})':force_original_aspect_ratio=decrease:{lanczos}"
        )

    if framing == "fit" and fit_fill == "blur":
        return _blur_stack(width, height, lanczos, use_template)

    pad_color = "white" if fit_fill == "white" else "black"
    if use_template:
        return (
            f"color=c={pad_color}:s={width}x{height}:r=30[base];"
            f"[1:v]scale={width}:{height}:force_original_aspect_ratio=increase:{lanczos},"
            f"crop={width}:{height},format=rgba,setsar=1[tpl];"
            f"[0:v]scale={width}:{height}:force_original_aspect_ratio=decrease:{lanczos},setsar=1[fg];"
            f"[base][tpl]overlay=0:0:shortest=1:format=auto[filled];"
            f"[filled][fg]overlay=(W-w)/2:(H-h)/2:shortest=1:format=auto[vout]"
        )

    return (
        f"scale={width}:{height}:force_original_aspect_ratio=decrease:{lanczos},"
        f"pad={width}:{height}:({width}-iw)/2:({height}-ih)/2:{pad_color}"
    )


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
