import { reactive } from 'vue';

const STORAGE_KEY = 'esquina_background_runs';
const COLLAPSED_KEY = 'esquina_background_panel_collapsed';

export const backgroundRunState = reactive({
    runs: [],
    activeRunId: null,
    queuedCount: 0,
    runningCount: 0,
    panelCollapsed: false,
    expandedRunId: null,
    lastStartedRunId: null,
});

export function applyBackgroundSnapshot(snapshot) {
    if (!snapshot || typeof snapshot !== 'object') {
        return;
    }

    const previousIds = new Set(backgroundRunState.runs.map((run) => run.id));
    const runs = Array.isArray(snapshot.runs) ? snapshot.runs : [];

    backgroundRunState.runs = runs;
    backgroundRunState.activeRunId = snapshot.active_run_id ?? null;
    backgroundRunState.queuedCount = snapshot.queued_count ?? 0;
    backgroundRunState.runningCount = snapshot.running_count ?? 0;

    const newlyRunning = runs.find(
        (run) => run.status === 'running' && !previousIds.has(run.id),
    );

    const activeRunning =
        runs.find((run) => run.id === snapshot.active_run_id && run.status === 'running')
        ?? runs.find((run) => run.status === 'running');

    if (newlyRunning) {
        backgroundRunState.lastStartedRunId = newlyRunning.id;
    }

    if (activeRunning) {
        backgroundRunState.expandedRunId = activeRunning.id;
    } else if (backgroundRunState.expandedRunId === null) {
        const fallback = runs.find((run) => run.status === 'queued') ?? runs[runs.length - 1];

        if (fallback) {
            backgroundRunState.expandedRunId = fallback.id;
        }
    }

    persistSnapshot(snapshot);
}

export function registerEnqueuedRun(run, snapshot) {
    if (snapshot) {
        applyBackgroundSnapshot(snapshot);
        setPanelCollapsed(false);

        return;
    }

    if (run) {
        const existing = backgroundRunState.runs.filter((item) => item.id !== run.id);
        backgroundRunState.runs = [...existing, run];
        backgroundRunState.expandedRunId = run.id;
        backgroundRunState.lastStartedRunId = run.id;
        setPanelCollapsed(false);

        if (run.status === 'queued') {
            backgroundRunState.queuedCount += 1;
        } else if (run.status === 'running') {
            backgroundRunState.runningCount = 1;
            backgroundRunState.activeRunId = run.id;
        }
    }
}

export function setPanelCollapsed(collapsed) {
    backgroundRunState.panelCollapsed = Boolean(collapsed);

    try {
        sessionStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0');
    } catch {
        // ignore
    }
}

export function hydratePanelCollapsed() {
    try {
        const raw = sessionStorage.getItem(COLLAPSED_KEY);

        if (raw === '1') {
            backgroundRunState.panelCollapsed = true;
        } else if (raw === '0') {
            backgroundRunState.panelCollapsed = false;
        }
    } catch {
        // ignore
    }
}

export function setExpandedRunId(runId) {
    backgroundRunState.expandedRunId = runId;
}

export function visibleRuns() {
    return backgroundRunState.runs.filter((run) => run.status !== 'dismissed');
}

export function hasBackgroundActivity() {
    return backgroundRunState.runs.some((run) =>
        ['running', 'queued', 'completed', 'failed'].includes(run.status),
    );
}

export function persistSnapshot(snapshot) {
    try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(snapshot));
    } catch {
        // ignore
    }
}

export function hydrateBackgroundRunFromSession() {
    try {
        const raw = sessionStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw);

        applyBackgroundSnapshot(parsed);
        hydratePanelCollapsed();

        return parsed;
    } catch {
        return null;
    }
}

export function clearBackgroundRunLocal() {
    backgroundRunState.runs = [];
    backgroundRunState.activeRunId = null;
    backgroundRunState.queuedCount = 0;
    backgroundRunState.runningCount = 0;
    backgroundRunState.expandedRunId = null;
    backgroundRunState.lastStartedRunId = null;

    try {
        sessionStorage.removeItem(STORAGE_KEY);
    } catch {
        // ignore
    }
}
