# Video split — facts and target schema

Leer esto al diseñar migraciones, API del worker o el modelo de datos. Auditado contra el repo el 2026-11-23.

## Facts (código actual)

- `SocialVideo`: `user_id`, `title`, `video_path`, `thumbnail_path`, `notes`, campos IA de captions. Sin parent, kind, duración persistida ni clips.
- Archivo original: disco `public`, carpeta `social-videos/`, URL `/storage/...`. Sin signed URLs.
- `SocialPublication`: unique `(social_video_id, platform)`. Título en el video; caption por red.
- Publishers: YouTube, Facebook ×2, LinkedIn ×2. TikTok stub. Facebook Reel si `height > width` (`FacebookReelUpload`). La UI aún hardcodea «Video de página».
- Auth: sesión web, `authorizeVideo` por `user_id`. No Policies. No `routes/api.php`. Sanctum en Composer, no usado.
- Jobs Laravel: no hay `app/Jobs`. Publicación social = `dispatchAfterResponse` + `BackgroundRunQueue` (cache, TTL 7200 s).
- FFmpeg PHP: solo `FacebookVideoPreparer` (normaliza, no recorta).
- Ollama: títulos/captions de video. No análisis audiovisual.
- Tests: PHPUnit. Cero coverage de Videos. No hay factory de `SocialVideo`.
- No hay archivos `.py` en el repo.

Rutas de Videos: `routes/web.php` (`videos.index` … `videos.publications.destroy`). Controlador: `app/Http/Controllers/SocialVideoController.php`. UI: `resources/js/Pages/Videos/{Index,Create,Show}.vue`.

## Target tables

### `social_videos` (ampliar)

- `parent_video_id` nullable FK → `social_videos.id`, `nullOnDelete`
- `kind` string: `original` | `clip` (default `original`)
- `source_job_id` nullable FK → `video_processing_jobs`
- `clip_index` unsigned smallint nullable
- `starts_at_ms` / `ends_at_ms` unsigned int nullable
- Unique `(parent_video_id, clip_index)` donde no sea null

Relaciones Eloquent: `parent()`, `clips()`. Un clip no es padre.

### `video_workers`

`user_id`, `name`, `token_hash`, `capabilities` JSON, `last_seen_at`, `current_job_id`, `is_enabled`. El plaintext del token se muestra una sola vez.

### `video_processing_jobs`

`user_id`, `social_video_id` (original), `type` (`analyze`|`render` como etapa), `status`, `params` JSON, `progress_percent`, `progress_message`, `worker_id`, `claimed_at`, `heartbeat_at`, `attempts`, `idempotency_key`, `analysis_path`, `error_message`, `result_summary` JSON, timestamps de start/finish.

Estados: `queued`, `claimed`, `analyzing`, `awaiting_approval`, `processing`, `uploading`, `completed`, `failed`, `cancelled`.

### `video_processing_segments`

`job_id`, `index`, `start_ms`, `end_ms`, `source` (`smart`|`scene`|`manual`), `score`, `warnings` JSON, `approved`, `output_video_id` nullable.

Si `output_video_id` ya existe, el reintento no crea otro `SocialVideo`.

## Storage

| Qué | Disco / path |
|---|---|
| Original | `public/social-videos/` — solo lectura |
| Análisis | `local` (`storage/app/private/video-jobs/{id}/`) |
| Clips | `public/social-clips/{parent_id}/{job_id}/clip-NN.mp4` |
| Temporales | solo en el worker; borrar al terminar |

Worker **no** descarga por `/storage/...`. Endpoint autenticado en `routes/worker.php`.

## Worker API

Registrar `routes/worker.php` en `bootstrap/app.php`. Sin sesión, sin CSRF. Middleware de token de dispositivo.

- `POST /worker/v1/heartbeat`
- `POST /worker/v1/jobs/claim`
- `GET  /worker/v1/jobs/{job}/source`
- `PUT  /worker/v1/jobs/{job}/progress`
- `PUT  /worker/v1/jobs/{job}/analysis`
- `POST /worker/v1/jobs/{job}/clips/{index}` (idempotente)
- `POST /worker/v1/jobs/{job}/fail`
- `POST /worker/v1/jobs/{job}/complete`

Abandoned jobs: tarea en `ScheduledTaskRegistry` (el tick `app:scheduler-tick` ya corre cada minuto).

## Laravel layout (nuevo)

```
app/VideoProcessing/
├── Actions/
├── Http/          # o app/Http/Controllers/VideoProcessing/
├── Support/
└── Enums/
config/video_processing.php
workers/esquina-video-worker/   # Python, fuera de app/
```

No meter lógica de análisis en `SocialVideoController` (ya es grande).

## Files to create

- Migraciones: columnas en `social_videos` + 3 tablas
- Modelos: `VideoWorker`, `VideoProcessingJob`, `VideoProcessingSegment`
- `routes/worker.php` + middleware token
- Rutas web de split (Inertia)
- `resources/js/Pages/Videos/Split.vue` + timeline
- `workers/esquina-video-worker/` + README de WSL
- Tests Feature PHPUnit + factory `SocialVideo`
- `config/video_processing.php`

## Files to modify (mínimo)

- `app/Models/SocialVideo.php` — `parent()`, `clips()`, fillable
- `SocialVideoController` — Index filtra originales; payload: duración, parent, clips, `facebook_content_type` real (no hardcode)
- `DeleteSocialVideosAction` — bloquear borrado de original con clips
- `routes/web.php`, `bootstrap/app.php`
- `Videos/Index.vue`, `Videos/Show.vue`
- `ScheduledTaskRegistry.php`
- `Dockerfile` / `entrypoint.sh` — dir `social-clips`
- `.env.example` — timeouts, min/max, heartbeat

## Files not to modify

Publishers, `PublishSocialPublicationAction`, OAuth, `GenerateSocialCaptionsAction` / title (se reutilizan desde el clip).

## Clip publications

Al materializar un clip: clonar plataformas del original en `draft` (no `published`). El usuario edita textos y envía con `videos.publications.publish` / `publish-all`.

Clips 9:16 → Facebook usa Reel automáticamente. Corregir la etiqueta hardcoded en el payload de Show.

## Config defaults (propuesta)

- Output: 1080×1920
- Min duración clip: configurable (p. ej. 15 s)
- Max: configurable (p. ej. 60 s, alineado a Shorts)
- Heartbeat timeout: configurable (p. ej. 2 min)
- Concurrencia worker: 1

## Python stack

PySceneDetect, OpenCV, FFmpeg (NVENC si `ffmpeg -hide_banner -encoders` lo lista; si no, CPU). Ollama local opcional, API compatible, no obligatorio. Sin Whisper.
