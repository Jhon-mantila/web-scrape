import axios from 'axios';

export async function fetchOllamaModels(target) {
    const { data } = await axios.get(route('scraper.ollama-models'), {
        params: { target },
    });

    return {
        models: data.models ?? [],
        defaultModel: data.default_model ?? 'qwen3:14b',
    };
}

export function pickOllamaModel(names, preferred, fallbackDefault) {
    if (names.includes(preferred)) {
        return preferred;
    }

    if (names.includes(fallbackDefault)) {
        return fallbackDefault;
    }

    return names.length > 0 ? names[0] : preferred || fallbackDefault;
}
