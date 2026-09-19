import axios from 'axios';
import {
    applyBackgroundSnapshot,
    hydrateBackgroundRunFromSession,
    registerEnqueuedRun,
    setPanelCollapsed,
} from '@/stores/backgroundRunStore.js';

export function backgroundRunRequestHeaders() {
    return {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
}

export function emitBackgroundRunStarted(run, snapshot) {
    registerEnqueuedRun(run, snapshot);

    window.dispatchEvent(
        new CustomEvent('pipeline-run-started', {
            detail: { run, snapshot },
        }),
    );
}

export async function fetchBackgroundRunStatus() {
    const { data } = await axios.get(route('background-run.status'), {
        headers: backgroundRunRequestHeaders(),
    });

    if (data.runs !== undefined) {
        applyBackgroundSnapshot(data);

        return data;
    }

    if (data.run) {
        applyBackgroundSnapshot({ runs: [data.run], active_run_id: data.run.id, queued_count: 0, running_count: data.run.status === 'running' ? 1 : 0 });
    }

    return data;
}

export function backgroundRunSnapshotFromPage(page) {
    if (!page?.props) {
        return null;
    }

    return (
        page.props.backgroundRun
        ?? page.props.pipelineRun
        ?? page.props.flash?.backgroundRunSnapshot
        ?? null
    );
}

export async function syncBackgroundRunAfterInertiaStart(page) {
    const fromPage = backgroundRunSnapshotFromPage(page);

    if (fromPage?.runs?.length) {
        applyBackgroundSnapshot(fromPage);
        setPanelCollapsed(false);
    }

    let snapshot = fromPage;

    try {
        snapshot = await fetchBackgroundRunStatus();
        setPanelCollapsed(false);
    } catch {
        hydrateBackgroundRunFromSession();
    }

    window.dispatchEvent(
        new CustomEvent('pipeline-run-started', {
            detail: { snapshot },
        }),
    );

    return snapshot;
}

export async function syncBackgroundRunAfterStart(runFromResponse, snapshotFromResponse) {
    let snapshot = snapshotFromResponse;

    if (!snapshot) {
        try {
            snapshot = await fetchBackgroundRunStatus();
        } catch {
            hydrateBackgroundRunFromSession();
        }
    }

    emitBackgroundRunStarted(runFromResponse, snapshot ?? null);

    return snapshot;
}

export async function dismissBackgroundRun(runId = null, clearFinished = false) {
    const { data } = await axios.post(
        route('background-run.dismiss'),
        {
            run_id: runId ?? undefined,
            clear_finished: clearFinished,
        },
        { headers: backgroundRunRequestHeaders() },
    );

    applyBackgroundSnapshot(data);

    return data;
}
