<?php

namespace App\ProcessScraping\Ai;

use App\ProcessScraping\Ai\Support\JsonPayloadParser;

class FactSheetArticleResponseParser
{
    public function __construct(
        private readonly JsonPayloadParser $jsonParser,
    ) {}

    /**
     * @return array{
     *     generated_title: ?string,
     *     excerpt: ?string,
     *     body_html: ?string,
     *     estado: ?string,
     *     slug: ?string,
     *     tags: list<string>
     * }
     */
    public function parse(string $raw): array
    {
        $data = $this->jsonParser->parse($raw);

        if ($data === null) {
            return $this->empty();
        }

        $tags = $data['etiquetas'] ?? $data['tags'] ?? [];

        if (! is_array($tags)) {
            $tags = [];
        }

        $tags = array_values(array_filter(array_map(
            fn ($tag) => is_string($tag) ? trim($tag) : null,
            $tags,
        )));

        return [
            'generated_title' => isset($data['titulo']) ? trim((string) $data['titulo']) : (isset($data['title']) ? trim((string) $data['title']) : null),
            'excerpt' => isset($data['meta_descripcion']) ? trim((string) $data['meta_descripcion']) : (isset($data['excerpt']) ? trim((string) $data['excerpt']) : null),
            'body_html' => isset($data['html']) ? trim((string) $data['html']) : null,
            'estado' => isset($data['estado']) ? trim((string) $data['estado']) : null,
            'slug' => isset($data['slug']) ? trim((string) $data['slug']) : null,
            'tags' => $tags,
        ];
    }

    /**
     * @return array{generated_title: null, excerpt: null, body_html: null, estado: null, slug: null, tags: list<string>}
     */
    private function empty(): array
    {
        return [
            'generated_title' => null,
            'excerpt' => null,
            'body_html' => null,
            'estado' => null,
            'slug' => null,
            'tags' => [],
        ];
    }
}
