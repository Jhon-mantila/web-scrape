# 1. Configurar Ollama
sudo systemctl edit ollama  # Añadir OLLAMA_HOST=0.0.0.0:11434

# 2. Instalar cloudflared
curl -L https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64.deb -o cloudflared.deb
sudo dpkg -i cloudflared.deb

# 3. Crear túnel
cloudflared tunnel login
cloudflared tunnel create mi-ollama

# 4. Configurar (editar ~/.cloudflared/config.yml)

# 5. Asignar DNS
cloudflared tunnel route dns mi-ollama esquina-api.cloud

# 6. Probar
cloudflared tunnel run mi-ollama

# 7. Instalar como servicio
sudo cloudflared --config /home/jhon/.cloudflared/config.yml service install
sudo systemctl enable cloudflared
sudo systemctl start cloudflared

# 8. Verificar
curl https://esquina-api.cloud/api/tags

# 9. ejemplos

curl https://esquina-api.cloud/api/generate -d '{
  "model": "qwen3:14b",
  "prompt": "Responde en una sola frase: ¿Qué es la inteligencia artificial?",
  "stream": false
}'


curl https://esquina-api.cloud/v1/chat/completions \
  -H "Content-Type: application/json" \
  -d '{
    "model": "qwen3:14b",
    "messages": [{"role": "user", "content": "Dime un chiste corto en español"}]
  }'

# 10. Laravel (Scraper → IA para artículos)

En `.env` del proyecto:

```env
OLLAMA_URL=http://ollama.host:11434
OLLAMA_REMOTE_URL=https://esquina-api.cloud
OLLAMA_MODEL=qwen3:14b
SCRAPER_ARTICLE_AI_MODEL=qwen3:14b
# SCRAPER_ARTICLE_OLLAMA_TARGET=local   # o remote
```

En **Scraper** elige: Ollama local → Local o Cloudflare, y el modelo en el desplegable (lista `/api/tags`).