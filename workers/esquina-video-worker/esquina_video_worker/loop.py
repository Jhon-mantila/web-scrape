from __future__ import annotations

import shutil
import time
from pathlib import Path
from typing import Any

from esquina_video_worker.capabilities import detect_capabilities
from esquina_video_worker.client import LaravelClient
from esquina_video_worker.config import WorkerConfig
from esquina_video_worker.probe import probe_video
from esquina_video_worker.render import render_clip
from esquina_video_worker.scenes import detect_scenes
from esquina_video_worker.segment import propose_segments
from esquina_video_worker.silence import detect_silences


def run(config: WorkerConfig | None = None) -> None:
    config = config or WorkerConfig.from_env()
    config.work_dir.mkdir(parents=True, exist_ok=True)
    client = LaravelClient(config.api_url, config.token)
    capabilities = detect_capabilities(config.ollama_url)
    client.log.info("Esquina Video Worker listo. NVENC=%s scenedetect=%s", capabilities.get("nvenc"), capabilities.get("scenedetect"))

    while True:
        try:
            client.heartbeat(capabilities)
            job = client.claim()
            if job is None:
                time.sleep(config.poll_seconds)
                continue
            _handle_job(client, config, capabilities, job)
        except KeyboardInterrupt:
            client.log.info("Worker detenido.")
            return
        except Exception as error:  # noqa: BLE001 — el loop no debe morir
            client.log.exception("Error de ciclo: %s", error)
            time.sleep(config.poll_seconds)


def _handle_job(client: LaravelClient, config: WorkerConfig, capabilities: dict[str, Any], job: dict[str, Any]) -> None:
    job_id = int(job["id"])
    work = config.work_dir / str(job_id)
    if work.exists():
        shutil.rmtree(work, ignore_errors=True)
    work.mkdir(parents=True)

    try:
        source = client.download_source(job_id, work / "original.mp4")
        stage = job.get("stage") or "analyze"
        if stage == "analyze":
            _analyze(client, job, source, capabilities)
        else:
            _render(client, job, source, work, capabilities)
    except Exception as error:  # noqa: BLE001
        client.log.exception("Job %s falló: %s", job_id, error)
        try:
            client.fail(job_id, str(error))
        except Exception:
            pass
    finally:
        shutil.rmtree(work, ignore_errors=True)


def _analyze(client: LaravelClient, job: dict[str, Any], source: Path, capabilities: dict[str, Any]) -> None:
    job_id = int(job["id"])
    params = job.get("params") or {}
    client.progress(job_id, 10, "Sondeando metadatos", "analyzing")
    probe = probe_video(source)

    client.progress(job_id, 35, "Detectando pausas de audio")
    silences = detect_silences(source) if probe.has_audio else []

    client.progress(job_id, 60, "Detectando escenas")
    scenes = detect_scenes(source) if capabilities.get("scenedetect") else []

    mode = str(params.get("mode") or "smart")
    target = int(params.get("target_clip_count") or 7)
    min_ms = int(params.get("min_duration_seconds") or 15) * 1000
    max_ms = int(params.get("max_duration_seconds") or 60) * 1000

    proposal = propose_segments(
        duration_ms=probe.duration_ms,
        target_count=target,
        min_ms=min_ms,
        max_ms=max_ms,
        scenes=scenes,
        silences=silences,
        mode=mode,
    )

    client.progress(job_id, 90, "Enviando propuesta")
    client.submit_analysis(
        job_id,
        {
            "duration_ms": probe.duration_ms,
            "width": probe.width,
            "height": probe.height,
            "fps": probe.fps,
            "target_clip_count": target,
            "recommended_clip_count": proposal.recommended_count,
            "recommendation_reason": proposal.reason,
            "segments": [
                {
                    "index": item.index,
                    "start_ms": item.start_ms,
                    "end_ms": item.end_ms,
                    "source": item.source if item.source in {"smart", "scene", "manual"} else "smart",
                    "score": item.score,
                    "warnings": item.warnings,
                }
                for item in proposal.segments
            ],
        },
    )


def _render(client: LaravelClient, job: dict[str, Any], source: Path, work: Path, capabilities: dict[str, Any]) -> None:
    job_id = int(job["id"])
    params = job.get("params") or {}
    segments = [item for item in (job.get("segments") or []) if item.get("approved")]
    if not segments:
        raise RuntimeError("No hay segmentos aprobados para renderizar.")

    framing = str(params.get("framing") or "smart_crop")
    width = int(params.get("output_width") or 1080)
    height = int(params.get("output_height") or 1920)
    fit_fill = str(params.get("fit_fill") or "blur")
    nvenc = bool(capabilities.get("nvenc"))
    total = len(segments)
    template = None
    if framing == "fit" and (job.get("has_fit_template") or (params.get("fit_template_path"))):
        template = client.download_template(job_id, work / "fit-template")
    show_part_label = framing == "fit" and _flag(params.get("fit_part_label"), default=True)
    part_color = str(params.get("fit_part_label_color") or "#FFFFFF")
    meta = probe_video(source)

    client.progress(job_id, 5, "Renderizando clips", "processing")
    for offset, segment in enumerate(segments):
        index = int(segment["index"])
        if segment.get("output_video_id"):
            continue
        dest = work / f"clip-{index:02d}.mp4"
        client.progress(job_id, 10 + int(80 * offset / total), f"Clip {offset + 1}/{total}", "processing")
        render_clip(
            source=source,
            dest=dest,
            start_ms=int(segment["start_ms"]),
            end_ms=int(segment["end_ms"]),
            framing=framing,
            width=width,
            height=height,
            nvenc=nvenc,
            template=template,
            fit_fill=fit_fill,
            part_label=f"Parte {offset + 1}" if show_part_label else None,
            part_label_color=part_color,
            source_width=meta.width,
            source_height=meta.height,
        )
        client.progress(job_id, 10 + int(80 * (offset + 0.5) / total), f"Subiendo clip {index}", "uploading")
        client.upload_clip(job_id, index, dest)

    client.complete(job_id)


def _flag(value: Any, default: bool = True) -> bool:
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    return str(value).strip().lower() in {"1", "true", "yes", "on"}
