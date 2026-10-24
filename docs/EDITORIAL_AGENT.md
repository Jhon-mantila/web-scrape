# Agente editorial (Neuron AI + Ollama)

## Activación

1. Instala dependencias (`composer install`) y publica config si hace falta: `php artisan vendor:publish --tag=neuron-config`.
2. En `.env`:
   - `NEURON_AI_PROVIDER=ollama`
   - `OLLAMA_BASE_URL=http://localhost:11434` (o `OLLAMA_URL`; se normaliza a `/api` para Neuron)
   - `OLLAMA_MODEL=qwen3:14b` (o tu modelo)
   - `EDITORIAL_AGENT_ENABLED=true`
3. Ejecuta migraciones: `php artisan migrate`.

## Interfaz Scraper

Con `EDITORIAL_AGENT_ENABLED=true`, en **Ejecutar pipeline** y en **Regenerar con IA** (vista previa) aparece la casilla **Agente editorial Neuron**:

- **Desmarcada (predeterminado):** ficha de hechos + Ollama (`SCRAPER_ANIME_NEWS_STAGED`), el flujo que suele dar artículos más largos.
- **Marcada:** ciclo Neuron (plan → borrador → crítica → refinamiento).

Puedes volver a `SCRAPER_ANIME_NEWS_STAGED=true` en `.env`; la UI elige el motor por ejecución.

## Generación

```bash
php artisan news:generate-ai --limit=1
```

Fallback al cliente Ollama clásico:

```bash
php artisan news:generate-ai --limit=1 --use-legacy-ai
```

El pipeline **anime_news_staged** (ficha de hechos) sigue usando el flujo legacy hasta adaptarlo.

## Observabilidad (`EDITORIAL_AGENT_OBSERVER`)

| Valor | Comportamiento |
|-------|----------------|
| `log` | Logs en `storage/logs/editorial-agent.log` + `LogObserver` / `LogListener` de Neuron |
| `inspector` | Traza a [Inspector](https://app.inspector.dev) vía `InspectorSubscriber` (requiere `composer require inspector-apm/inspector-php`) |
| `both` | Log local e Inspector |

Inspector necesita `INSPECTOR_INGESTION_KEY=tu_clave`.

En desarrollo suele bastar `EDITORIAL_AGENT_OBSERVER=log`. En producción, `inspector` o `both`.

## Estado en base de datos

Columnas en `news_details`:

- `ai_status`: `pending` → `planning` → `drafting` → `critiquing` → `refining` (opcional) → `completed` o `failed`
- `ai_status_updated_at`

Consulta rápida:

```sql
SELECT id, news_id, ai_status, ai_status_updated_at
FROM news_details
WHERE ai_status IS NOT NULL
ORDER BY ai_status_updated_at DESC;
```

## Arquitectura (Opción A)

- `App\Neuron\Agents\EditorialAgent` — proveedor Ollama, instrucciones editoriales, tool `research_context`.
- `App\Services\EditorialService` — cuatro llamadas `chat()` (plan, borrador, crítica, refinamiento opcional) con un `setThreadId()` único por generación (requerido en Neuron 4.x).
- Integración en `GenerateNewsAiArticleAction` cuando `EDITORIAL_AGENT_ENABLED=true` y no `--use-legacy-ai`.
