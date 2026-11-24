---
name: implementing-video-split
description: Use when implementing or changing intelligent video split, clips derivados, parent_video_id, Esquina Video Worker, video_processing_jobs, PySceneDetect, NVENC, 9:16 shorts/reels, or any worker that polls Laravel over outbound HTTPS. Also use before touching SocialVideo storage, Videos Vue pages, or publications of derived clips. Confirmed data model is Approach A.
---

# Implementing video split (Esquina AI)

## Overview

Reutilizar un `SocialVideo` original ya guardado, analizarlo fuera de Laravel y generar clips verticales. Cada clip aprobado es **otro `SocialVideo` hijo**. Publicar con el flujo actual. El número de clips es un objetivo, no una división en intervalos iguales.

**Enfoque A (confirmado 2026-11-23):** no crear una entidad `VideoClip` publicable. El clip es un `SocialVideo` con `parent_video_id`.

## Before writing code

1. Releer este skill y, si vas a tocar tablas o el worker, [references/architecture.md](references/architecture.md).
2. Inspeccionar el código real. No inventar clases, rutas ni columnas.
3. Explicar el cambio y pedir autorización (`AGENTS.md`).
4. No afirmar que una fase está terminada si no está implementada y verificada.

## Hard constraints

- No reemplazar el módulo Videos ni duplicar publishers (YouTube, Facebook, LinkedIn).
- No duplicar ni reescribir el archivo original.
- No cortar cada N segundos. Preferir menos clips coherentes antes que forzar el número pedido.
- No Whisper. Ollama opcional y solo con metadatos; si no hay modelo, el algoritmo sigue.
- No FFmpeg/PySceneDetect/OpenCV dentro de una petición HTTP ni en la VPS.
- No `BackgroundRunQueue` / cache / `dispatchAfterResponse` para este trabajo: MySQL persistente.
- No puertos entrantes en el PC. El worker solo sale por HTTPS.
- No Sanctum solo para esto: token de dispositivo en `video_workers`.
- No modificar `.env` de producción ni Docker de la VPS sin autorización.
- TikTok sigue `coming_soon`; no implementar su API aquí.

## Laravel vs worker

| Laravel (VPS) | Python (`workers/esquina-video-worker/`) |
|---|---|
| UI, validación, auth, jobs, clips, publicaciones | Análisis, segmentación, tracking, FFmpeg/NVENC |
| Sirve el original por endpoint autenticado | Descarga, procesa, sube resultados |
| Sigue en pie si el PC está apagado | Un job a la vez; heartbeat; claim atómico |

## Approach A

- Original: `kind = original`, `parent_video_id` null. El `video_path` no cambia.
- Clip: `kind = clip`, `parent_video_id` = original, archivo en `social-clips/`.
- Al crear el clip, copiar las plataformas del padre como `SocialPublication` en `draft`.
- Index lista solo originales. Show del original muestra hijos. Show del clip = ficha actual.
- Un clip no puede ser padre de otro clip.
- No borrar el original mientras tenga clips; borrar clips con `DeleteSocialVideosAction`.
- Unique `(social_video_id, platform)` se mantiene por clip.

## Job machine

`queued → claimed → analyzing → awaiting_approval → processing → uploading → completed`

También `failed` y `cancelled`. Análisis y render son etapas del mismo job: si el PC se apaga en `awaiting_approval`, el job espera en MySQL.

Claim: `UPDATE … WHERE status = queued`. Heartbeat caducado → el scheduler libera el job. Reintentos no duplican clips (`output_video_id` / índice idempotente).

## Segmentation

Candidatos = escenas (PySceneDetect) + pausas/silencios de audio + score configurable. Agrupar escenas consecutivas. Si una escena supera el máximo y no hay corte interno seguro, warning — no corte arbitrario. Los tres modos (`smart`, `scene`, `manual`) escriben `video_processing_segments` y usan el mismo render.

Encuadre al renderizar: inteligente (OpenCV, fallback si falla) | fondo desenfocado (preservar subtítulos incrustados) | ajuste completo. Salida default 1080×1920.

## UI

Integrar en Videos. Nueva vista de revisión (timeline + preview `#t=` / `currentTime`) **antes** de renderizar. No generar todos los archivos hasta aprobar.

## Tests (PHPUnit, no Pest)

Cubrir al menos: no re-subir original; crear job; claim; segmentos; tiempos aprobados; sin huecos/duplicados; 9:16; relación padre/hijo; publicar por el flujo actual; Laravel vivo sin worker; jobs abandonados; reintentos idempotentes.

## Phases

1. Auditoría (hecha) → 2. Backend jobs/API → 3. Worker esqueleto → 4. Segmentación → 5. Vue → 6. Publicación de clips → 7. Tests y docs.

No implementar todas las fases de golpe.

## Do not

| Excuse | Reality |
|---|---|
| "Un VideoClip es más limpio" | Enfoque A confirmado. Publishers solo conocen SocialVideo. |
| "BackgroundRunQueue ya existe" | Cache + TTL 2 h; no sobrevive un PC apagado. |
| "Parto en 7 intervalos y luego ajusto" | Eso viola el producto. |
| "Lo proceso en el request / en la VPS" | El PC tiene GPU; la VPS no. |
| "Sanctum está en composer" | No está cableado. Token de worker propio. |

## Files

Ver lista concreta en [references/architecture.md](references/architecture.md).
