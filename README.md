# Anime Scraper (Laravel + Docker + Ollama)

Proyecto en Laravel para:

- Hacer scraping de noticias.
- Enriquecer contenido con IA local (Ollama).
- Publicar artículos en WordPress.

## Stack

- PHP 8.3
- Laravel 13
- MySQL 8 (Docker)
- phpMyAdmin (Docker)
- Ollama (host local)

### Arquitectura `app/ProcessScraping/`

```
ProcessScraping/
├── Actions/          # Orquestación y casos de uso (pipeline, IA, imágenes, investigación)
├── Ai/               # Cliente Ollama, parser JSON y selector de modelo
├── Prompts/          # Biblioteca de prompts y clasificador de artículos
├── Research/         # SearXNG: búsquedas y formateo de contexto
├── Images/           # Extracción y generación de imagen destacada (FLUX)
└── Support/          # Utilidades (YouTube, sanitizado HTML, límites de prompt)
```

Fase 3: `Images/Generators/` (ComfyUI + FLUX Schnell como fallback).

## Requisitos

- Docker y Docker Compose
- WSL (si trabajas en Windows)
- Ollama instalado en el host

## Levantar proyecto

1. Clonar repositorio
2. Crear variables de entorno

```bash
cp .env.example .env
```

3. Levantar contenedores

```bash
docker compose up -d --build
```

4. Instalar dependencias y preparar Laravel (dentro del contenedor)

```bash
docker exec -it laravel_app composer install
docker exec -it laravel_app php artisan key:generate
docker exec -it laravel_app php artisan migrate
```

## Fase 2 — SearXNG (investigación web)

### 1. Levantar SearXNG

```bash
docker compose up -d searxng
docker exec -it laravel_app php artisan migrate
```

SearXNG queda en: `http://localhost:8080`

### 2. Variables en `.env`

```env
SEARXNG_ENABLED=true
SEARXNG_URL=http://searxng:8080
SEARXNG_MAX_QUERIES=3
SEARXNG_RESULTS_PER_QUERY=3

OLLAMA_MODEL=qwen3:14b
OLLAMA_MODEL_PREMIUM=qwen3:30b-a3b
OLLAMA_PREMIUM_MIN_CHARS=4500
```

El modelo **premium** (`qwen3:30b-a3b`) se usa automáticamente en noticias largas o traducciones de ANN.

### 3. Probar investigación sola

```bash
docker exec -it laravel_app php artisan news:research --limit=2 --force
```

Revisa en `news_details`: columnas `research_context`, `research_raw`, `researched_at`.

### 4. Pipeline con investigación

```bash
docker exec -it laravel_app php artisan news:pipeline --limit=3 --skip-scrape --force --include-raw-html --mode=draft
```

Orden: detalles → imágenes → **investigación (2-3 búsquedas)** → IA → WordPress

Para omitir SearXNG: `--skip-research`

## Fase 3 — ComfyUI + FLUX Schnell (imagen fallback)

Cuando el scrape **no encuentra imagen**, el pipeline puede generar una con **FLUX Schnell** vía ComfyUI en el host WSL (mismo patrón que Ollama).

### 1. Instalar ComfyUI en WSL

Guía detallada: [`docker/comfyui/README.md`](docker/comfyui/README.md)

Resumen:

```bash
# En WSL (fuera de Docker)
git clone https://github.com/comfyanonymous/ComfyUI.git ~/ComfyUI
cd ~/ComfyUI && python3 -m venv venv && source venv/bin/activate
pip install -r requirements.txt

# Descargar modelos FLUX Schnell en las carpetas de ComfyUI:
# models/unet/flux1-schnell.safetensors
# models/clip/clip_l.safetensors + t5xxl_fp16.safetensors
# models/vae/ae.safetensors

python main.py --listen 0.0.0.0 --port 8188
```

### 2. Variables en `.env`

```env
COMFYUI_ENABLED=true
COMFYUI_URL=http://comfyui.host:8188
COMFYUI_HOST_IP=172.31.193.25
COMFYUI_TIMEOUT=180
```

Usa la misma IP WSL que `OLLAMA_HOST_IP` (`hostname -I`).

### 3. Probar generación sola

```bash
docker exec -it laravel_app php artisan migrate
docker exec -it laravel_app php artisan news:generate-images --limit=2
```

Revisa `news_details.featured_image_path` y `featured_image_source` (`scraped` | `generated`).

### 4. Pipeline con fallback FLUX

```bash
docker exec -it laravel_app php artisan news:pipeline --limit=3 --skip-scrape --include-raw-html --mode=draft
```

Orden: detalles → imágenes (scrape → **FLUX si falta**) → investigación → IA → WordPress

Para omitir FLUX: `--skip-generate` o `COMFYUI_ENABLED=false`

## Comandos principales

### Pipeline completo (recomendado)

Un solo comando ejecuta: listado → detalles → imagen → IA → WordPress.

```bash
docker exec -it laravel_app php artisan migrate
docker exec -it laravel_app php artisan storage:link

docker exec -it laravel_app php artisan news:pipeline --limit=5 --mode=draft --include-raw-html --show-errors
```

Opciones útiles:

```bash
# Sin volver a scrapear el listado (solo detalles + IA + WP)
docker exec -it laravel_app php artisan news:pipeline --limit=5 --skip-scrape --mode=draft

# Reprocesar aunque ya estén marcados como procesados
docker exec -it laravel_app php artisan news:pipeline --limit=5 --force --include-raw-html
```

### Migrar base de datos

```bash
docker exec -it laravel_app php artisan migrate
```

### Ejecutar scraper

```bash
docker exec -it laravel_app php artisan scrape:news
docker exec -it laravel_app php artisan scrape:news:details --limit=20
```

### Ejecutar IA (generacion de articulos)

```bash
docker exec -it laravel_app php artisan news:generate-ai --limit=5
docker exec -it laravel_app php artisan news:generate-ai --limit=10 --force --include-raw-html
docker exec -it laravel_app php artisan news:generate-ai --limit=1 --include-raw-html
```

### Enviar a WordPress

```bash
docker exec -it laravel_app php artisan news:send-wordpress --limit=5 --mode=draft
```

### Validar comandos disponibles

```bash
docker exec -it laravel_app php artisan list
```

### Ver logs de Laravel

```bash
docker exec -it laravel_app tail -f storage/logs/laravel.log
```

### Crear una migracion nueva

```bash
docker exec -it laravel_app php artisan make:migration add_sent_wordpress_to_news_ai_articles_table
```

### Limpiar/cachear configuracion

```bash
docker exec -it laravel_app php artisan config:clear
docker exec -it laravel_app php artisan config:cache
```

## Ollama (IA local)

Modelo por defecto: **`qwen3:14b`**

Variables recomendadas en `.env`:

```env
OLLAMA_URL=http://host.docker.internal:11434
OLLAMA_MODEL=qwen3:14b
OLLAMA_FORMAT_JSON=true
OLLAMA_TEMPERATURE=0.75
OLLAMA_NUM_CTX=8192
```

### Correr modelos

```bash
ollama pull qwen3:14b
ollama run qwen3:14b
```

### Prueba local desde WSL (localhost)

```bash
curl http://localhost:11434/api/generate -d '{
  "model": "mistral",
  "prompt": "Escribe un artículo SEO sobre videojuegos en español",
  "stream": false
}'
```

### Prueba desde el contenedor Docker hacia Ollama en host

```bash
docker exec -it laravel_app curl http://172.31.193.25:11434/api/tags

curl http://172.31.193.25:11434/api/generate -d '{
  "model": "mistral",
  "prompt": "Hola desde Laravel"
}'
```

Nota: idealmente usa `OLLAMA_URL=http://host.docker.internal:11434` para evitar depender de una IP fija.

## Servicios y puertos (docker-compose)

- App Laravel: `http://localhost:8000`
- MySQL: `localhost:3307`
- phpMyAdmin: `http://localhost:8081`

## Flujo sugerido de trabajo

**Opción A — un solo paso:**
```bash
docker exec -it laravel_app php artisan news:pipeline --limit=5 --mode=draft --include-raw-html
```

**Opción B — paso a paso (manual):**
1. Scrape noticias base: `scrape:news`
2. Scrape detalles: `scrape:news:details`
3. Generar articulos con IA: `news:generate-ai`
4. Revisar logs/resultado
5. Enviar a WordPress en borrador: `news:send-wordpress --mode=draft`

## Hardware local de referencia

- CPU: Intel Core i7-12700KF
- Motherboard: Gigabyte Z790 EAGLE AX
- RAM: 32 GB DDR5
- GPU: NVIDIA RTX 5070 Ti (16 GB VRAM)
