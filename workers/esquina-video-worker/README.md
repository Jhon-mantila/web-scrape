# Esquina Video Worker

Procesa **División inteligente de videos** en tu PC (WSL). Laravel en la VPS solo orquesta: este proceso sale por HTTPS, **no abre puertos** en el router.

Si el PC está apagado, la web sigue funcionando y los jobs quedan `queued` o `awaiting_approval`.

## Requisitos (WSL)

- Python 3.11+
- FFmpeg con `ffprobe` (`sudo apt install ffmpeg`)
- Token de dispositivo emitido por Laravel
- GPU NVIDIA opcional (NVENC). Si no está, usa CPU (`libx264`)

Opcional:

```bash
pip install scenedetect opencv-python-headless
```

Sin PySceneDetect el worker igual analiza **pausas de audio** con FFmpeg. **No usa Whisper.**

## 1. Crear el token en Laravel

Dentro del contenedor, una vez:

```bash
docker exec -it laravel_app php artisan video-worker:create "PC-Jhon" --user=tu@email
```

Copia `ESQUINA_WORKER_TOKEN=...` (solo se muestra una vez).

## 2. Configurar el worker

```bash
cd workers/esquina-video-worker
cp .env.example .env
# Edita ESQUINA_API_URL y ESQUINA_WORKER_TOKEN
```

`ESQUINA_API_URL` es la URL pública de Esquina AI (`https://...`, sin barra final).

## 3. Arrancar (recomendado: Docker Play)

El servicio `esquina_video_worker` está en `docker-compose.yml`. Trae FFmpeg. En Docker Desktop: Play en **esquina_video_worker**. O:

```bash
docker compose up -d --build esquina_video_worker
```

Dentro de Docker la API es `http://laravel_app` (no `localhost:8000`). El token sigue en `workers/esquina-video-worker/.env`.

Si ya tenías `python3 -m esquina_video_worker` en una terminal, párale (Ctrl+C) para no tener dos workers.

### Manual (WSL, sin Docker)

El módulo se llama **`esquina_video_worker`** (con la r final):

```bash
cd workers/esquina-video-worker
python3 -m esquina_video_worker
```

## Pruebas

```bash
cd workers/esquina-video-worker
PYTHONPATH=. python3 -m unittest discover -s tests -v
```

## Qué hace en esta versión

1. Heartbeat con capacidades (`nvenc`, `ffmpeg`, `scenedetect`, `ollama`).
2. Reclama un job `queued`.
3. **Analizar:** ffprobe + silencios + escenas (si hay PySceneDetect) → propuesta. **No corta en intervalos iguales.**
4. Espera a que apruebes en la web.
5. **Render:** FFmpeg 1080×1920 (NVENC si existe). Encuadre: recorte centrado, fondo desenfocado o ajuste.
6. Sube cada clip; Laravel crea el `SocialVideo` hijo.

La segmentación “inteligente” completa (agrupación + scoring) está en `segment.py` y se mejorará; ya evita partir en N trozos iguales.
