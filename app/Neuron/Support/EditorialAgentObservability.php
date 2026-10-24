<?php

declare(strict_types=1);

namespace App\Neuron\Support;

use Illuminate\Support\Facades\Log;
use NeuronAI\Agent\Agent;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Observability\LogListener;
use NeuronAI\Observability\LogObserver;

/**
 * Observabilidad del EditorialAgent: logs locales y trazas Inspector (Neuron 4.x).
 *
 * Modo: config('services.editorial_agent.observer') = log | inspector | both
 */
final class EditorialAgentObservability
{
    public static function attach(Agent $agent): void
    {
        $mode = (string) config('services.editorial_agent.observer', 'log');

        if ($mode === 'log' || $mode === 'both') {
            $logger = Log::channel('editorial_agent');
            $listener = new LogListener($logger);
            $agent->observe(new LogObserver($logger));
            $agent->subscribe(ObservabilityEvent::class, $listener);
        }

        if ($mode === 'inspector' || $mode === 'both') {
            self::attachInspector($agent);
        }
    }

    private static function attachInspector(Agent $agent): void
    {
        if (! class_exists(\Inspector\Neuron\V4\InspectorSubscriber::class)) {
            Log::channel('editorial_agent')->warning(
                '[EditorialAgent] Modo inspector activo pero falta el paquete inspector-apm/inspector-php. '
                .'Instálalo y define INSPECTOR_INGESTION_KEY.'
            );

            return;
        }

        $key = config('services.editorial_agent.inspector_ingestion_key');

        if ($key === null || $key === '') {
            Log::channel('editorial_agent')->warning(
                '[EditorialAgent] INSPECTOR_INGESTION_KEY vacía; no se enviará traza a Inspector.'
            );

            return;
        }

        $subscriber = \Inspector\Neuron\V4\InspectorSubscriber::instance($key);
        $agent->subscribe(ObservabilityEvent::class, $subscriber);
    }
}
