# ComfyUI + FLUX Schnell (host WSL)

ComfyUI corre **fuera de Docker**, en WSL, igual que Ollama. Laravel lo llama por HTTP cuando no hay imagen del scrape.

## Requisitos

- NVIDIA GPU con drivers en WSL
- Python 3.10+
- ~15 GB libres para modelos FLUX Schnell

## Instalación

```bash
git clone https://github.com/comfyanonymous/ComfyUI.git ~/ComfyUI
cd ~/ComfyUI
python3 -m venv venv
source venv/bin/activate
pip install torch torchvision torchaudio --index-url https://download.pytorch.org/whl/cu124
pip install -r requirements.txt
```

## Modelos FLUX Schnell

Descarga y coloca estos archivos (nombres exactos):

| Archivo | Carpeta ComfyUI |
|---------|-----------------|
| `flux1-schnell.safetensors` | `models/unet/` |
| `clip_l.safetensors` | `models/clip/` |
| `t5xxl_fp16.safetensors` | `models/clip/` |
| `ae.safetensors` | `models/vae/` |

Fuentes habituales: [Hugging Face — black-forest-labs/FLUX.1-schnell](https://huggingface.co/black-forest-labs/FLUX.1-schnell)

## Arrancar ComfyUI

```bash
cd ~/ComfyUI
source venv/bin/activate
python main.py --listen 0.0.0.0 --port 8188
```

Verifica desde WSL:

```bash
curl http://127.0.0.1:8188/system_stats
```

Verifica desde el contenedor Laravel:

```bash
docker exec -it laravel_app curl http://comfyui.host:8188/system_stats
```

## Variables Laravel (`.env`)

```env
COMFYUI_ENABLED=true
COMFYUI_URL=http://comfyui.host:8188
COMFYUI_HOST_IP=172.31.193.25
```

`COMFYUI_HOST_IP` = misma IP que `OLLAMA_HOST_IP` (`hostname -I | awk '{print $1}'`).

## Workflow

El proyecto incluye `resources/comfyui/flux-schnell-featured.json` (formato API).

Si tus modelos tienen otros nombres, ajusta en `.env`:

```env
COMFYUI_UNET_NAME=flux1-schnell.safetensors
COMFYUI_VAE_NAME=ae.safetensors
COMFYUI_CLIP_NAME1=clip_l.safetensors
COMFYUI_CLIP_NAME2=t5xxl_fp16.safetensors
```

Si exportas tu propio workflow desde ComfyUI (**Save API Format**), apunta:

```env
COMFYUI_WORKFLOW_PATH=/var/www/html/resources/comfyui/flux-schnell-featured.json
COMFYUI_PROMPT_NODE=6
```

## VRAM

- FLUX Schnell ~8–10 GB VRAM a 1216×684
- **No corras Ollama y ComfyUI a la vez** si ambos usan GPU al máximo
- El pipeline serializa: imágenes → investigación → IA

## Probar

```bash
docker exec -it laravel_app php artisan news:generate-images --limit=1
```

Imagen guardada en `storage/app/public/featured-images/{news_id}.jpg`.
