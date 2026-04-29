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

## Comandos principales

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

### Correr modelos

```bash
ollama run mistral
ollama run llama3
ollama run gemma4:e4b
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

1. Scrape noticias base: `scrape:news`
2. Scrape detalles: `scrape:news:details`
3. Generar articulos con IA: `news:generate-ai`
4. Revisar logs/resultado
5. Enviar a WordPress en borrador: `news:send-wordpress --mode=draft`

## Hardware local de referencia

- CPU: Intel Core i7-12700KF
- Motherboard: Gigabyte Z790 EAGLE AX
- RAM: 32 GB DDR5
- GPU: AMD Radeon RX 6600 (7.98 GB)
