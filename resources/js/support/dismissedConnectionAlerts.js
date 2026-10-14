const STORAGE_KEY = 'esquina_ai_dismissed_connection_alerts';

export function readDismissedConnectionAlerts() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return [];
        }

        const parsed = JSON.parse(raw);

        return Array.isArray(parsed) ? parsed.filter((item) => typeof item === 'string') : [];
    } catch {
        return [];
    }
}

export function dismissConnectionAlert(platform) {
    const current = new Set(readDismissedConnectionAlerts());
    current.add(platform);

    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify([...current]));
    } catch {
        // ignore
    }
}

/** Quita plataformas que ya no están desconectadas (reconectadas). */
export function pruneDismissedConnectionAlerts(activeDisconnectedPlatforms) {
    const active = new Set(activeDisconnectedPlatforms);
    const pruned = readDismissedConnectionAlerts().filter((platform) => active.has(platform));

    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(pruned));
    } catch {
        // ignore
    }

    return pruned;
}
