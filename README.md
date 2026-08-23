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

1. Levantar contenedores

```bash
docker compose up -d --build
```

1. Instalar dependencias y preparar Laravel (dentro del contenedor)

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

Guía detallada: `[docker/comfyui/README.md](docker/comfyui/README.md)`

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

**Para desactivar FLUX** (solo scrape de imagen, sin generar):

```env
COMFYUI_ENABLED=false
```



### 2b. Arrancar ComfyUI (obligatorio si `COMFYUI_ENABLED=true`)

ComfyUI debe estar **encendido en WSL** antes de `news:download-images` (fallback FLUX) o `news:generate-images`. Si no está corriendo, las noticias sin imagen del scrape quedarán sin imagen destacada.

```bash
# En WSL (fuera de Docker), en una terminal aparte:
cd ~/ComfyUI
source venv/bin/activate
python main.py --listen 0.0.0.0 --port 8188
```

Verifica que responde:

```bash
curl http://127.0.0.1:8188/system_stats
```

Ollama y ComfyUI compiten por VRAM/RAM: cierra uno si el otro falla por memoria, o usa la liberación automática configurada en el proyecto.

### 3. Probar descarga de imágenes (scrape)

```bash
docker exec -it laravel_app php artisan migrate
docker exec -it laravel_app php artisan news:download-images --limit=2 --skip-generate
```

Revisa `news_details.featured_image_path` y `featured_image_source` (`scraped` | `generated`).

### 3b. Probar generación FLUX sola (opcional)

ComfyUI debe estar encendido. Solo genera cuando **aún no hay** imagen:

```bash
docker exec -it laravel_app php artisan news:generate-images --limit=2
```



### 4. Pipeline con fallback FLUX

```bash
docker exec -it laravel_app php artisan news:pipeline --limit=3 --skip-scrape --include-raw-html --mode=draft
```

Orden: detalles → imágenes (scrape → **FLUX si falta**) → investigación → IA → WordPress

Para omitir FLUX: `--skip-generate` o `COMFYUI_ENABLED=false`

## Comandos principales

> **Importante:** todos los comandos `php artisan` deben ejecutarse **dentro del contenedor** con `docker exec -it laravel_app ...`. Si los corres en el host WSL verás errores como `could not find driver` o que no resuelve el host `mysql_db` (solo existe en la red Docker).

Antes del primer pipeline, enlaza storage (solo una vez):

```bash
docker exec -it laravel_app php artisan storage:link
```

---



### Flujo paso a paso (manual)

Cuando quieres **controlar cada etapa por separado** y revisar resultados entre pasos.

#### Paso 1 — Migrar base de datos

```bash
docker exec -it laravel_app php artisan migrate
```



#### Paso 2 — Ejecutar scraper

Listado de noticias (título, URL, categoría) y luego el cuerpo de cada artículo:

```bash
docker exec -it laravel_app php artisan scrape:news
docker exec -it laravel_app php artisan scrape:news:details --limit=20
```

`--limit=20` = cuántas URLs de detalle procesa en esa ejecución. Repite el comando si hay más pendientes.

**Nota:** `scrape:news:details` guarda HTML y texto, **no descarga imágenes**.

#### Paso 3 — Descargar imágenes destacadas

Busca la imagen en la noticia (`og:image`, `<img>` del HTML, etc.) y la guarda en `storage/app/public/featured-images/` con marca de agua.

```bash
# Scrape de imagen; si COMFYUI_ENABLED=true y no hay imagen, intenta FLUX
docker exec -it laravel_app php artisan news:download-images --limit=20

# Solo scrape, sin FLUX (ComfyUI apagado o no lo quieres usar)
docker exec -it laravel_app php artisan news:download-images --limit=20 --skip-generate
```

- `--limit=N` — noticias con detalle procesado y sin `featured_image_path`.
- `--skip-generate` — no llama a ComfyUI aunque esté habilitado en `.env`.

Si el scrape no encuentra imagen y quieres **solo FLUX** (ComfyUI encendido en WSL):

```bash
docker exec -it laravel_app php artisan news:generate-images --limit=20
```

Comprueba en BD: `news_details.featured_image_path` y `featured_image_source` (`scraped` | `generated`).

#### Paso 4 — Investigación web (opcional)

```bash
docker exec -it laravel_app php artisan news:research --limit=20 --force
```

Requiere `SEARXNG_ENABLED=true` y contenedor SearXNG activo.

#### Paso 5 — Ejecutar IA (generación de artículos)

```bash
docker exec -it laravel_app php artisan news:generate-ai --limit=5
docker exec -it laravel_app php artisan news:generate-ai --limit=10 --force --include-raw-html
docker exec -it laravel_app php artisan news:generate-ai --limit=1 --include-raw-html
```

- `--limit=N` — cuántos artículos generar por ejecución.
- `--force` — regenera aunque ya exista artículo IA.
- `--include-raw-html` — incluye el HTML original en el prompt (mejor para noticias largas o traducciones ANN).



#### Paso 6 — Enviar a WordPress

```bash
docker exec -it laravel_app php artisan news:send-wordpress --limit=5 --mode=draft
```

- `--limit=N` — cuántos artículos enviar.
- `--mode=draft` — publica como borrador (`publish` o `schedule` también disponibles).

---



### Pipeline completo (recomendado)

Un solo comando ejecuta todo en orden:

**scrape listado → detalles → imágenes → investigación (SearXNG) → IA → WordPress**

#### Comando base

```bash
docker exec -it laravel_app php artisan migrate
docker exec -it laravel_app php artisan news:pipeline --limit=5 --mode=draft --include-raw-html --show-errors
```



#### Opciones del pipeline


| Opción               | Valor por defecto | Qué hace                                                                                |
| -------------------- | ----------------- | --------------------------------------------------------------------------------------- |
| `--limit=N`          | `5`               | Cuántas noticias procesa **en cada paso** (detalles, imágenes, research, IA, WP).       |
| `--mode=`            | `draft`           | Estado en WordPress: `draft` (borrador), `publish` (publicar) o `schedule` (programar). |
| `--include-raw-html` | off               | Pasa el HTML crudo al prompt de IA. Recomendado para ANN y noticias largas.             |
| `--force`            | off               | Reprocesa detalles, investigación e IA aunque ya estén hechos.                          |
| `--skip-scrape`      | off               | Omite el scrape del listado; usa noticias ya guardadas en BD.                           |
| `--skip-research`    | off               | Omite SearXNG (investigación web).                                                      |
| `--skip-generate`    | off               | Omite FLUX/ComfyUI; solo intenta imagen del scrape.                                     |
| `--show-errors`      | off               | Muestra en consola los errores de generación IA.                                        |




#### Ejemplos habituales

```bash
# Primera corrida completa desde cero
docker exec -it laravel_app php artisan news:pipeline --limit=5 --mode=draft --include-raw-html --show-errors

# Reprocesar sin volver a scrapear el listado (noticias ya en BD)
docker exec -it laravel_app php artisan news:pipeline --limit=5 --skip-scrape --mode=draft --include-raw-html

# Reprocesar todo aunque ya esté marcado como hecho
docker exec -it laravel_app php artisan news:pipeline --limit=5 --force --include-raw-html --mode=draft

# Sin FLUX ni SearXNG (solo scrape + IA + WordPress)
docker exec -it laravel_app php artisan news:pipeline --limit=5 --skip-generate --skip-research --mode=draft
```



#### Servicios que deben estar activos


| Servicio         | Cuándo hace falta                                            |
| ---------------- | ------------------------------------------------------------ |
| Ollama en WSL    | Siempre (paso IA).                                           |
| ComfyUI en WSL   | Solo si `COMFYUI_ENABLED=true` y faltan imágenes del scrape. |
| SearXNG (Docker) | Solo si `SEARXNG_ENABLED=true` y no usas `--skip-research`.  |


Orden interno del pipeline:

1. Scrape listado (`scrape:news`) — salvo `--skip-scrape`
2. Detalles (`scrape:news:details`)
3. Imágenes (scrape → FLUX si falta)
4. Investigación SearXNG — salvo `--skip-research`
5. Generación IA (Ollama)
6. Envío WordPress

---

Comando **fuera del pipeline** para borrar imágenes, investigación o IA sin tener que reprocesar todo manualmente.

#### Reset suave (conserva noticias scrapeadas)

Borra archivos de imagen y limpia campos de procesamiento en BD, pero **mantiene** las filas de `news` y `news_details` (incluye `raw_html` y `content_text`).

```bash
docker exec -it laravel_app php artisan news:reset-processing --force
```

Qué hace por defecto:

- Borra archivos en `storage/app/public/featured-images/`
- Limpia `featured_image_path` y `featured_image_source` en `news_details`
- Limpia `research_context`, `research_raw` y `researched_at` en `news_details`
- Borra todos los registros de `news_ai_articles`
- Resetea `news.status_ia` a `null`

Opciones parciales:

```bash
# Solo imágenes (archivos + columnas de imagen en BD)
docker exec -it laravel_app php artisan news:reset-processing --images-only --force

# Reset sin borrar archivos de imagen
docker exec -it laravel_app php artisan news:reset-processing --no-images --force

# Conservar investigación SearXNG
docker exec -it laravel_app php artisan news:reset-processing --no-research --force

# Conservar artículos IA generados
docker exec -it laravel_app php artisan news:reset-processing --no-ai --force
```

Después del reset suave, reprocesa sin volver a scrapear:

```bash
docker exec -it laravel_app php artisan news:pipeline --limit=5 --skip-scrape --include-raw-html --mode=draft
```



#### Limpieza total (`--truncate`)

Vacía por completo las tablas de noticias y borra imágenes. **Destructivo:** pierdes todo el scrape.

```bash
docker exec -it laravel_app php artisan news:reset-processing --truncate --force
```

Qué hace:

- Borra todos los archivos en `storage/app/public/featured-images/`
- `TRUNCATE news_ai_articles`
- `TRUNCATE news_details`
- `TRUNCATE news`

**No borra** el logo en `storage/app/public/Logo/`.

Sin `--force` pide confirmación extra. Después del truncate hay que empezar desde cero:

```bash
docker exec -it laravel_app php artisan scrape:news
docker exec -it laravel_app php artisan news:pipeline --limit=5 --include-raw-html --mode=draft
```


| Objetivo                                     | Comando                                    |
| -------------------------------------------- | ------------------------------------------ |
| Reprocesar imágenes/IA sin volver a scrapear | `news:reset-processing --force`            |
| Empezar de cero (BD + imágenes)              | `news:reset-processing --truncate --force` |




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

Ollama corre **en WSL**, fuera de Docker. Laravel se conecta con `OLLAMA_URL=http://ollama.host:11434`.

### Variables en `.env`

```env
OLLAMA_HOST_IP=172.31.193.25
OLLAMA_URL=http://ollama.host:11434
OLLAMA_MODEL=gemma4:12b
OLLAMA_FORMAT_JSON=true
OLLAMA_TEMPERATURE=0.75
OLLAMA_NUM_CTX=16384
OLLAMA_NUM_PREDICT=6144
```

Obtén la IP de WSL con `hostname -I | awk '{print $1}'` y actualiza `OLLAMA_HOST_IP`. Luego:

```bash
docker compose up -d --force-recreate laravel_app
docker exec -it laravel_app php artisan config:clear
```

### Correr modelos

```bash
ollama pull gemma4:12b
ollama list
```

### Probar conexión

```bash
# Desde WSL
curl http://127.0.0.1:11434/api/tags

# Desde el contenedor Laravel (debe responder JSON)
docker exec -it laravel_app curl http://ollama.host:11434/api/tags
```

### Si falla Ollama — reiniciar servicio

Error típico en logs:

```
Failed to connect to ollama.host port 11434
```

**1. Ver estado y reiniciar**

```bash
systemctl status ollama
sudo systemctl restart ollama
systemctl is-enabled ollama   # debe decir "enabled"
```

**2. Ollama debe escuchar en todas las interfaces (no solo localhost)**

Si `ss -tlnp | grep 11434` muestra solo `127.0.0.1:11434`, Docker no puede conectar. Configura el override de systemd (ajusta `OLLAMA_MODELS` a tu usuario WSL):

```bash
sudo tee /etc/systemd/system/ollama.service.d/override.conf > /dev/null <<'EOF'
[Service]
Environment="HSA_OVERRIDE_GFX_VERSION=10.3.0"
Environment="ROCM_PATH=/opt/rocm"
Environment="OLLAMA_MODELS=/home/TU_USUARIO/.ollama/models"
Environment="HOME=/usr/share/ollama"
Environment="OLLAMA_HOST=0.0.0.0:11434"
EOF

sudo systemctl daemon-reload
sudo systemctl restart ollama
systemctl status ollama
```

Reemplaza `TU_USUARIO` por tu usuario WSL (ej. `jhon`).

**3. Verificar que responde desde Docker**

```bash
ss -tlnp | grep 11434
curl http://$(hostname -I | awk '{print $1}'):11434/api/tags
docker exec -it laravel_app curl http://ollama.host:11434/api/tags
```

**4. Volver a generar IA**

```bash
docker exec -it laravel_app php artisan news:generate-ai --limit=5 --force --include-raw-html
```

### Permisos del servicio (si ollama.service entra en bucle)

Si el log dice `permission denied` en `/usr/share/ollama`:

```bash
sudo mkdir -p /usr/share/ollama
sudo chown -R ollama:ollama /usr/share/ollama
sudo systemctl restart ollama
```

### Actualizar Ollama

Modelos nuevos (ej. `gemma4:12b`) pueden requerir versión reciente:

```bash
curl -fsSL https://ollama.com/install.sh | sh
ollama --version
sudo systemctl restart ollama
```

## Servicios y puertos (docker-compose)

- App Laravel: `http://localhost:8000`
- MySQL: `localhost:3307`
- phpMyAdmin: `http://localhost:8081`



## Hardware local de referencia

- CPU: Intel Core i7-12700KF
- Motherboard: Gigabyte Z790 EAGLE AX
- RAM: 32 GB DDR5
- GPU: NVIDIA RTX 5070 Ti (16 GB VRAM)

