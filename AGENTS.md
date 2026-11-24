# Instrucciones para el agente

## Idioma

- Responde siempre en español.
- Explica las decisiones técnicas en español.
- Mantén los nombres de clases, métodos, variables y código en su idioma original.

## Seguridad

- No hagas cambios en los archivos sin solicitar autorización primero.
- Antes de realizar cambios importantes, explica qué vas a modificar.
- No elimines archivos ni ejecutes comandos destructivos sin autorización explícita.

## Proyecto

- Este es un proyecto Laravel.
- Respeta la arquitectura existente.
- Antes de modificar código, analiza cómo funciona actualmente.
- Evita cambios innecesarios o reescrituras completas.

## Nuevas implementaciones (Videos / división inteligente)

- Antes de tocar clips, jobs de video, worker Python o el módulo Videos, lee `.cursor/skills/implementing-video-split/SKILL.md`.
- Enfoque A confirmado: cada clip es un `SocialVideo` hijo (`parent_video_id`), no una entidad publicable aparte.
- No implementes esa funcionalidad de golpe; sigue las fases de la skill y no la des por terminada sin verificarla.
