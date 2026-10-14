# Traspaso: por qué EsquinaAnime no se monetiza y cómo mejorar `anime-scraper`

Fecha del análisis: 13 de octubre de 2026.
Repositorio analizado: https://github.com/Jhon-mantila/web-scrape (rama `main`, commit `2604998`).
Proyecto local: `/home/jhon/anime-scraper` (WSL). **Puede estar más actualizado que GitHub: verificar cada punto contra el código local antes de cambiar nada.**

Estado: el usuario pidió **solo análisis**. No se ha implementado nada todavía.

---

## 1. Diagnóstico en el servidor WordPress

Los tres sitios están en el mismo servidor (`/var/www/esquinaweb.com`, `esquinagamers.com`, `esquinaanime.com`), con el mismo `ads.txt` (`pub-1852556021136727`) y Google Site Kit.

| | esquinaweb | esquinagamers | esquinaanime |
|---|---|---|---|
| Módulo AdSense en Site Kit | activo, `ready` | activo, `ready` | **no activado** (sin `clientID`) |
| Script `adsbygoogle.js` en la portada | sí | sí | **no** |
| Contenido desde | oct. 2023 | feb. 2025 | **23 ago. 2026** |
| Ritmo | 4–9/mes | 10–21/mes | **210 en sept., hasta 9/día** |
| Mediana de palabras | 437 | 553 | **259** |
| Entradas < 300 palabras | 50/285 | 8/257 | **258/361 (71 %)** |

Otros hallazgos en esquinaanime:
- Solo 5 de 361 entradas mencionan Anime News Network; 0 enlaces internos.
- Plantilla repetida: resumen, "Contexto adicional" genérico, cierre "¿Qué opinas…? ¡Comparte en los comentarios!".
- Categorías copiadas de ANN en inglés (`games`, `korean`, `people`, `industry`).
- Sin página de Contacto (404); sigue publicada la "Página de ejemplo".
- Restos de mantenimiento: `index-matt.html`, carpeta `backup/` y marcador `# BEGIN MAINTENANCE` sin cierre en `.htaccess`.
- Bien: `ads.txt` 200, `robots.txt` correcto, indexable, política de privacidad menciona AdSense, Search Console y Analytics conectados.

Conclusión: el código de AdSense nunca se activó, y además el contenido encaja con "contenido de poco valor" (traducción reescrita a escala, corta, sin atribución).

---

## 2. Problemas encontrados en el código del scraper (por prioridad)

### 2.1 El prompt pide traducir y acepta textos cortos
- `app/ProcessScraping/Prompts/ArticleGenerationPrompt.php`, líneas ~26-33: para `anime_news` dice "Traduce y adapta al español".
- Misma clase, línea ~120: "si es corto, un artículo breve bien escrito es suficiente". Las noticias de ANN tienen 150–300 palabras, así que el modelo devuelve eso.
- `app/ProcessScraping/Prompts/PromptLibrary.php`, `userExtra()` default: "cierra con una frase que conecte con la comunidad fan" → genera el "¿Qué opinas…?" en casi todos los artículos.
- Líneas ~104-110: exige "enriquecer" con la investigación aunque la investigación no aporte nada → relleno o datos inventados.

### 2.2 La investigación con SearXNG no aporta información nueva
- `app/ProcessScraping/Research/SearchQueryBuilder.php`: las 3 búsquedas son el mismo titular en inglés (`título`, `título anime`, `título anime news español`). Encuentran la misma noticia de ANN y las traducciones de la competencia.
- `ResearchContextFormatter.php`: solo fragmentos de 280 caracteres (3×3 resultados ≈ 2500 caracteres), luego recortado a 3000 en el prompt.
- No excluye `animenewsnetwork.com`; nunca abre páginas para leer contenido completo.
- `SearXngClient::filterRelevantResults()`: acepta cualquier resultado que comparta una palabra de ≥4 letras; si ninguno pasa, devuelve todos.

### 2.3 El clasificador de tono busca palabras en español sobre texto en inglés
- `app/ProcessScraping/Prompts/ArticleTypeClassifier.php`: palabras clave en español (`falleció`, `polémica`, `anuncia`…) pero `content_text` de ANN está en inglés.
- Ejemplo: "Manga Creator Masako Watanabe Dies at 97" no se clasifica como `homenaje` y recibe el tono por defecto con "picardía". Solo funcionan coincidencias bilingües (`trailer`, `premiere`, `leak`, `rumor`).

### 2.4 Sin atribución de fuente y marca de agua sobre imágenes ajenas
- `app/SendWordpress/Actions/SendPostToWordpressAction.php`: envía título, contenido, extracto y categoría; ningún enlace a la fuente.
- `app/ProcessScraping/Images/FeaturedImageWatermarker.php`: pone el logo de EsquinaAnime sobre imágenes descargadas de ANN (material oficial de terceros).

### 2.5 No hay filtrado ni agrupación
- `app/Scraper/Sources/AnimeNewsScraper.php` toma todo `.herald.box.news` del listado: rankings de Famitsu, convenciones locales, listas de lanzamientos, etc.
- No se agrupan noticias de la misma franquicia (p. ej. 3 tráileres de personajes de *The Apothecary Diaries* en 3 días = 3 artículos flojos).

### 2.6 El límite diario solo se aplica en `--mode=schedule`
- `WordPressSchedulePlanner` solo se usa cuando `$mode === 'schedule'`. Con `publish` o varias ejecuciones no hay tope (por eso hubo días con 9 entradas).

### 2.7 Problemas técnicos secundarios
- Una sola llamada al modelo hace todo (traducir, integrar investigación, tono, HTML escapado dentro de JSON) a temperatura 0.75 → frágil (de ahí los 5 reintentos por JSON inválido en `GenerateNewsAiArticleAction`).
- `app/Scraper/Sources/Details/AnimeNewsDetailScraper.php`: `normalizeText()` colapsa todo con `preg_replace('/\s+/', ' ', ...)` y se pierden los párrafos; el selector `#content-zone .KonaBody` va antes que `.meat` (el cuerpo limpio).
- `--include-raw-html` mete 4000 caracteres de HTML, casi todo marcado, poco útil.
- Categorías en inglés tal cual vienen de ANN; sin etiquetas por franquicia; sin enlaces internos; sin meta descripción de Yoast.
- Solo hay `ExampleTest`; sin pruebas para los prompts ni el clasificador.

Lo que está bien: estructura por etapas, `num_ctx=16384`, `think=false` para qwen3, selector de modelo premium (`qwen3:30b-a3b`), sanitizado de HTML, planificador de publicación.

---

## 3. Flujo propuesto

1. **Scrape**: extraer `.meat` conservando párrafos.
2. **Filtro y agrupación (nuevo)**:
   - LLM (JSON, temperatura 0.1–0.2) extrae franquicia, tipo de noticia, estudio, plataformas.
   - Consultar la API de AniList (GraphQL gratuita): sinopsis, estudio, episodios, popularidad, enlaces de streaming.
   - Descartar franquicias con poca popularidad / poco interés hispano.
   - Agrupar noticias de la misma franquicia de las últimas 72 h en un solo artículo.
3. **Investigación dirigida**:
   - Búsquedas por entidad: `"<franquicia>" doblaje latino`, `"<franquicia>" manga editorial español` (Panini, Ivrea, Norma, Kamite), `"<franquicia>" crunchyroll latinoamérica`, temporada anterior.
   - Excluir `-site:animenewsnetwork.com`.
   - Abrir y leer las 2 mejores páginas, no solo fragmentos.
4. **Ficha de hechos**: JSON a temperatura 0.1–0.2, cada dato con su URL de fuente.
5. **Redacción en español desde la ficha** (no traducción), temperatura 0.6–0.7, salida HTML directa; título y meta descripción en una llamada aparte.
6. **Revisión automática**: todos los datos están en la ficha, sin frases prohibidas, extensión mínima. Si no cumple → no se publica (o se agrupa con otra noticia).
7. **Publicación en WordPress**:
   - Bloque "Fuentes" (ANN + web oficial) y crédito de imagen añadidos desde el código, no por el LLM.
   - Marca de agua solo en imágenes generadas con FLUX.
   - Categorías traducidas al español; etiqueta por franquicia; 2–3 enlaces internos a la misma etiqueta.
   - Máximo 2–3 artículos al día en **todos** los modos.

### Ejemplo de prompt para la etapa de redacción

```text
Eres redactor de EsquinaAnime, un medio de anime para lectores de Latinoamérica y España.
Escribe un artículo en español neutro usando EXCLUSIVAMENTE los datos de FICHA.
Si un dato no está en FICHA, no lo menciones ni lo supongas.

Objetivo: que el lector sepa qué se anunció, por qué importa y cómo/dónde podrá verlo o leerlo en español.

Estructura:
- Entradilla de 2-3 frases con lo esencial (qué, cuándo, dónde).
- "Qué se anunció": todos los detalles concretos (fechas, estudio, reparto, staff).
- "Contexto de la obra": de qué trata, temporadas o volúmenes previos, qué parte adaptará.
- "¿Dónde verlo o leerlo en español?": solo con datos de FICHA; si no hay, di que aún no está confirmado.
- "Nuestra lectura": 1 párrafo de análisis (por qué es relevante, qué se puede esperar), sin exagerar.

Reglas de estilo:
- Prohibido: "¿Qué opinas?", "¡Comparte en los comentarios!", "sin duda", "emocionante", "en conclusión".
- Fechas con formato "9 de enero de 2027"; horarios convertidos a CDMX, Bogotá y Madrid si FICHA trae la hora JST.
- No inventes cifras, nombres ni plataformas.

Extensión: 600-900 palabras si FICHA tiene material suficiente; si no, escribe lo que haya
y devuelve "INSUFICIENTE" en el campo estado para que el flujo descarte o combine la noticia.

Salida JSON: {"titulo", "meta_descripcion" (≤155 caracteres), "slug", "html", "etiquetas": [franquicia, estudio], "estado"}

FICHA:
{{ficha_json}}
ESQUEMA:
{{esquema_json}}
```

---

## 4. Acciones fuera del código (WordPress / AdSense)

1. Conectar el módulo AdSense en Site Kit de esquinaanime y añadir el sitio en AdSense → Sitios.
2. Crear página de Contacto; borrar "Página de ejemplo"; dar biografía real a los autores.
3. Limpiar `index-matt.html`, `backup/` y el marcador suelto de `.htaccess`.
4. Contenido existente: ampliar/unir los 50–80 mejores artículos y marcar como `noindex` (o borrar) el resto de los cortos.
5. Con el ritmo nuevo, esperar 4–8 semanas antes de volver a pedir la revisión.

## 5. Orden sugerido de implementación

1. Cambios rápidos: prompt nuevo, clasificador bilingüe (o clasificación por LLM), bloque de fuentes, quitar marca de agua a imágenes ajenas, categorías en español, límite diario en todos los modos.
2. Investigación: AniList + búsquedas por entidad + lectura de páginas + ficha de hechos.
3. Separar la generación en etapas (extracción → redacción → revisión).
4. Filtrado y agrupación por franquicia.
