<?php

declare(strict_types=1);

use NeuronAI\Providers\HuggingFace\InferenceProvider;

$ollamaBase = rtrim((string) env('OLLAMA_BASE_URL', env('OLLAMA_URL', 'http://localhost:11434')), '/');
$ollamaApiUrl = str_ends_with($ollamaBase, '/api') ? $ollamaBase : $ollamaBase.'/api';

return [
    'system_prompt' => [
        'background' => ['You are a helpful AI assistant built with Neuron AI framework.'],
        'steps' => [],
        'output' => [],
    ],

    'provider' => [
        'default' => env('NEURON_AI_PROVIDER', 'ollama'),

        'anthropic' => [
            'key' => env('ANTHROPIC_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-3-7-sonnet-latest'),
            'parameters' => [],
        ],

        'openai' => [
            'key' => env('OPENAI_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-5-mini'),
            'parameters' => [],
        ],

        'openai-responses' => [
            'key' => env('OPENAI_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-5-mini'),
            'parameters' => [],
        ],

        'gemini' => [
            'key' => env('GEMINI_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-3-pro-preview'),
            'parameters' => [],
        ],

        'ollama' => [
            'url' => $ollamaApiUrl,
            'model' => env('OLLAMA_MODEL', 'qwen3:14b'),
            'parameters' => [],
        ],

        'mistral' => [
            'key' => env('MISTRAL_KEY'),
            'model' => env('MISTRAL_MODEL', 'mistral-7b-instruct-v0.2'),
            'parameters' => [],
        ],

        'deepseek' => [
            'key' => env('DEEPSEEK_KEY'),
            'model' => env('DEEPSEEK_MODEL', 'DeepSeek-V3'),
            'parameters' => [],
        ],

        'huggingface' => [
            'key' => env('HUGGINGFACE_KEY'),
            'model' => env('HUGGINGFACE_MODEL', 'meta-llama/Llama-2-7b-hf'),
            'inferenceProvider' => InferenceProvider::HF_INFERENCE,
            'parameters' => [],
        ],

        'cohere' => [
            'key' => env('COHERE_KEY'),
            'model' => env('COHERE_MODEL', 'command-a-reasoning-08-2025'),
            'parameters' => [],
        ],

        'openai-tts' => [
            'key' => env('OPENAI_KEY'),
            'model' => env('OPENAI_TTS_MODEL', 'gpt-5-mini'),
            'voice' => env('OPENAI_VOICE', 'alloy'),
            'parameters' => [],
        ],

        'openai-stt' => [
            'key' => env('OPENAI_KEY'),
            'model' => env('OPENAI_STT_MODEL', 'gpt-4o-transcribe'),
            'language' => 'en',
            'parameters' => [],
        ],

        'elevenlabs-tts' => [
            'key' => env('ELEVENLABS_KEY'),
            'model' => env('ELEVENLABS_TTS_MODEL', 'elevenlabs/tts-v1'),
            'voiceId' => env('ELEVENLABS_VOICE_ID'),
            'parameters' => [],
        ],

        'elevenlabs-stt' => [
            'key' => env('ELEVENLABS_KEY'),
            'model' => env('ELEVENLABS_STT_MODEL', 'elevenlabs/stt-v1'),
            'parameters' => [],
        ],
    ],

    'embedding' => [
        'default' => env('NEURON_EMBEDDING_PROVIDER'),

        'openai' => [
            'key' => env('OPENAI_KEY'),
            'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-ada-002'),
            'dimensions' => 1024,
        ],

        'gemini' => [
            'key' => env('GEMINI_KEY'),
            'model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-pro-embed-v1'),
            'config' => [],
        ],

        'ollama' => [
            'url' => $ollamaApiUrl,
            'model' => env('OLLAMA_EMBEDDING_MODEL', 'openai-embedding-ada-002'),
            'parameters' => [],
        ],

        'voyage' => [
            'key' => env('VOYAGE_KEY'),
            'model' => env('VOYAGE_EMBEDDING_MODEL', 'voyage-2-embed-v1'),
            'dimensions' => null,
        ],

        'mistral' => [
            'baseUri' => env('MISTRAL_BASE_URI', 'https://api.mistral.ai/v1/embeddings'),
            'key' => env('MISTRAL_KEY'),
            'model' => env('MISTRAL_EMBEDDING_MODEL', 'mistral-7b-embed-v1'),
            'dimensions' => 1024,
        ],
    ],

    'store' => [
        'default' => env('NEURON_STORE_PROVIDER', 'file'),

        'file' => [
            'directory' => storage_path('neuron'),
            'topK' => 5,
            'name' => 'neuron',
            'ext' => '.store',
        ],

        'pinecone' => [
            'key' => env('PINECONE_KEY'),
            'indexUrl' => env('PINECONE_INDEX_URL'),
            'topK' => 5,
            'version' => '2025-04',
            'namespace' => '__default__',
        ],

        'qdrant' => [
            'collectionUrl' => env('QDRANT_COLLECTION_URL'),
            'key' => env('QDRANT_KEY'),
            'topK' => 5,
            'dimension' => 1024,
        ],

        'meilisearch' => [
            'indexUid' => env('MEILISEARCH_INDEX_UID'),
            'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
            'key' => env('MEILISEARCH_KEY'),
            'embedder' => 'default',
            'topK' => 5,
            'dimension' => 1024,
        ],

        'chroma' => [
            'collection' => env('CHROMA_COLLECTION'),
            'host' => env('CHROMA_HOST', 'http://localhost:7700'),
            'tenant' => env('CHROMA_TENANT', 'default_tenant'),
            'database' => env('CHROMA_DATABASE', 'default_database'),
            'key' => env('CHROMA_KEY'),
            'topK' => 5,
        ],
    ],
];
