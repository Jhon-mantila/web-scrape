<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\NewsAiArticle;
use App\Models\NewsDetail;
use App\ProcessScraping\Actions\DownloadFeaturedImagesAction;
use App\ProcessScraping\Actions\ProcessBackgroundRunQueueAction;
use App\ProcessScraping\Actions\GenerateNewsAiArticleAction;
use App\Http\Support\RequestsBackgroundJson;
use App\ProcessScraping\Support\PipelineRunState;
use App\ProcessScraping\Ai\OllamaClient;
use App\ProcessScraping\Support\HtmlArticleSanitizer;
use App\ProcessScraping\Support\YoutubeExtractor;
use App\Scraper\Support\NewsScraperStats;
use App\SendWordpress\Actions\AttachWordpressFeaturedImagesAction;
use App\SendWordpress\Actions\SyncWordpressScraperStatusAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ScraperController extends Controller
{
    /** @var list<int> */
    private const LIST_PER_PAGE_OPTIONS = [10, 15, 25, 50];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $pendingWordpress = $request->boolean('pending_wordpress');
        $pendingAi = $request->boolean('pending_ai');
        $scheduledWp = $request->boolean('scheduled_wp');
        $failedOnly = $request->boolean('failed');
        $perPage = (int) $request->query('per_page', 15);

        if (! in_array($perPage, self::LIST_PER_PAGE_OPTIONS, true)) {
            $perPage = 15;
        }

        $query = News::query()
            ->with(['detail', 'aiArticle'])
            ->orderByDesc('id');

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function ($builder) use ($like) {
                $builder
                    ->where('title', 'like', $like)
                    ->orWhere('url', 'like', $like)
                    ->orWhere('source', 'like', $like);
            });
        }

        if ($pendingWordpress) {
            $query->whereHas('aiArticle', function ($articles) {
                $articles
                    ->where('sent_wordpress', false)
                    ->whereNotNull('body_html');
            });
        }

        if ($pendingAi) {
            $query->whereHas('detail', fn ($details) => $details->where('status', 'processed'))
                ->where(function ($builder) {
                    $builder
                        ->whereDoesntHave('aiArticle')
                        ->orWhereHas('aiArticle', fn ($articles) => $articles->whereNull('body_html'))
                        ->orWhere('status_ia', 'failed');
                });
        }

        if ($scheduledWp) {
            $query->whereHas('aiArticle', fn ($articles) => $articles->where('wordpress_status', 'future'));
        }

        if ($failedOnly) {
            $query->where(function ($builder) {
                $builder
                    ->where('status_ia', 'failed')
                    ->orWhereHas('detail', fn ($details) => $details->where('status', 'failed'));
            });
        }

        $news = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Scraper/Index', [
            'filters' => [
                'q' => $search,
                'pending_wordpress' => $pendingWordpress,
                'pending_ai' => $pendingAi,
                'scheduled_wp' => $scheduledWp,
                'failed' => $failedOnly,
                'per_page' => $perPage,
            ],
            'stats' => NewsScraperStats::compute(),
            'pipeline' => $this->pipelineSettings(),
            'list' => [
                'per_page_options' => self::LIST_PER_PAGE_OPTIONS,
            ],
            'news' => $news->through(fn (News $item) => $this->newsPayload($item)),
        ]);
    }

    public function preview(News $news): JsonResponse
    {
        $news->loadMissing(['detail', 'aiArticle']);

        $payload = $this->previewPayload($news);

        if ($payload === null) {
            return response()->json([
                'message' => 'Este artículo aún no tiene contenido generado por IA.',
            ], 404);
        }

        return response()->json($payload);
    }

    public function regenerateAi(
        Request $request,
        News $news,
        GenerateNewsAiArticleAction $action,
        OllamaClient $ollama,
    ): JsonResponse {
        $news->loadMissing(['detail', 'aiArticle']);

        if ($news->aiArticle?->sent_wordpress) {
            return response()->json([
                'message' => 'Este artículo ya fue enviado a WordPress y no se puede regenerar desde aquí.',
            ], 422);
        }

        if ($news->detail?->status !== 'processed') {
            return response()->json([
                'message' => 'La noticia aún no tiene el detalle procesado para regenerar con IA.',
            ], 422);
        }

        $validated = $request->validate([
            'include_raw_html' => 'boolean',
        ]);

        set_time_limit(0);

        $summary = $action->execute(
            1,
            true,
            (bool) ($validated['include_raw_html'] ?? true),
            [$news->id],
        );

        if ($summary['success'] === 0) {
            $error = $summary['errors'][0]['message'] ?? 'No se pudo regenerar el artículo con IA.';

            return response()->json(['message' => $error], 422);
        }

        if (config('services.ollama.unload_after_generate')) {
            $ollama->unloadModels();
        }

        $news->refresh()->load(['detail', 'aiArticle']);

        $payload = $this->previewPayload($news);

        if ($payload === null) {
            return response()->json([
                'message' => 'La IA terminó pero no se pudo cargar la vista previa.',
            ], 500);
        }

        return response()->json([
            'message' => 'Artículo regenerado con IA.',
            'preview' => $payload,
        ]);
    }

    public function attachWordpressFeaturedImages(Request $request, AttachWordpressFeaturedImagesAction $action): RedirectResponse
    {
        if (! filled(config('services.wordpress.url'))) {
            return back()->with('error', 'WORDPRESS_URL no está configurado.');
        }

        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:200',
            'force' => 'boolean',
        ]);

        set_time_limit(0);

        try {
            $limit = isset($validated['limit']) ? (int) $validated['limit'] : 50;
            $onlyMissing = ! ($validated['force'] ?? false);
            $summary = $action->execute($limit, $onlyMissing);

            if ($summary['attached'] === 0 && $summary['failed'] > 0) {
                return back()->with(
                    'error',
                    'No se pudo adjuntar ninguna imagen ('.$summary['failed'].' errores). Revisa los logs.',
                );
            }

            $message = 'Imágenes destacadas en WP: '.$summary['attached'].' asignadas';

            if ($summary['skipped_has_featured'] > 0) {
                $message .= ' · '.$summary['skipped_has_featured'].' ya tenían destacada';
            }

            if ($summary['skipped_no_local_image'] > 0) {
                $message .= ' · '.$summary['skipped_no_local_image'].' sin archivo local';
            }

            if ($summary['failed'] > 0) {
                $message .= ' · '.$summary['failed'].' errores';
            }

            return back()->with($summary['failed'] > 0 ? 'error' : 'success', $message);
        } catch (Throwable $exception) {
            Log::error('Adjuntar imágenes WP falló', ['message' => $exception->getMessage()]);

            return back()->with('error', 'Error al adjuntar imágenes: '.$exception->getMessage());
        }
    }

    public function syncWordpressStatus(SyncWordpressScraperStatusAction $action): RedirectResponse
    {
        if (! filled(config('services.wordpress.url'))) {
            return back()->with('error', 'WORDPRESS_URL no está configurado.');
        }

        try {
            $summary = $action->execute();

            if ($summary['errors'] > 0 && $summary['updated'] === 0) {
                return back()->with(
                    'error',
                    'No se pudo sincronizar ningún post con WordPress ('.$summary['errors'].' errores).',
                );
            }

            $message = 'Estado WP sincronizado: '.$summary['updated'].' actualizados';

            if ($summary['errors'] > 0) {
                $message .= ' · '.$summary['errors'].' errores';
            }

            if ($summary['total'] === 0) {
                $message = 'No hay posts con ID de WordPress guardado. Los envíos nuevos guardarán la metadata automáticamente.';
            }

            return back()->with($summary['errors'] > 0 ? 'error' : 'success', $message);
        } catch (Throwable $exception) {
            Log::error('Sync WordPress scraper falló', ['message' => $exception->getMessage()]);

            return back()->with('error', 'Error al sincronizar WordPress: '.$exception->getMessage());
        }
    }

    public function runPipeline(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $shouldSendWordpress = $request->boolean('send_wordpress');
        $wordpressOnly = $request->boolean('wordpress_only');

        $validated = $request->validate([
            'limit' => 'required|integer|min:1|max:50',
            'send_wordpress' => 'boolean',
            'wordpress_only' => 'boolean',
            'mode' => [
                Rule::requiredIf($shouldSendWordpress || $wordpressOnly),
                'nullable',
                Rule::in(['draft', 'publish', 'schedule']),
            ],
            'skip_scrape' => 'boolean',
            'skip_research' => 'boolean',
            'skip_generate' => 'boolean',
            'force' => 'boolean',
            'include_raw_html' => 'boolean',
        ]);

        $options = [
            ...$validated,
            'send_wordpress' => $shouldSendWordpress,
            'wordpress_only' => $wordpressOnly,
            'mode' => (string) ($validated['mode'] ?? 'draft'),
        ];

        $run = PipelineRunState::start($user->id, $options);

        try {
            Bus::dispatchAfterResponse(function () use ($user): void {
                app(ProcessBackgroundRunQueueAction::class)->execute($user->id);
            });
        } catch (Throwable $dispatchError) {
            PipelineRunState::fail($user->id, 'No se pudo iniciar en segundo plano: '.$dispatchError->getMessage(), $run['id'] ?? null);
            Log::error('Pipeline dispatch falló', ['message' => $dispatchError->getMessage()]);

            if (RequestsBackgroundJson::matches($request)) {
                return response()->json(['message' => $dispatchError->getMessage()], 500);
            }

            return back()->with('error', 'No se pudo iniciar el pipeline en segundo plano.');
        }

        if (RequestsBackgroundJson::matches($request)) {
            $snapshot = PipelineRunState::snapshot($user->id);

            return response()->json([
                'message' => ($run['status'] ?? '') === 'queued'
                    ? 'Pipeline en cola. Se ejecutará cuando termine el proceso anterior.'
                    : 'Pipeline iniciado en segundo plano.',
                'run' => $run,
                'snapshot' => $snapshot,
            ]);
        }

        return back()->with([
            'success' => 'Pipeline iniciado en segundo plano. Puedes navegar por la app; el progreso aparece abajo a la derecha.',
            'background_run_snapshot' => PipelineRunState::snapshot($user->id),
        ]);
    }

    public function pipelineStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        return response()->json(PipelineRunState::snapshot($user->id));
    }

    public function dismissPipelineRun(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $validated = $request->validate([
            'run_id' => 'nullable|string',
            'clear_finished' => 'boolean',
        ]);

        if ($validated['clear_finished'] ?? false) {
            $snapshot = PipelineRunState::snapshot($user->id);

            foreach ($snapshot['runs'] as $run) {
                if (in_array($run['status'] ?? '', ['completed', 'failed'], true)) {
                    PipelineRunState::dismiss($user->id, $run['id']);
                }
            }

            return response()->json(PipelineRunState::snapshot($user->id));
        }

        $runId = $validated['run_id'] ?? null;

        if ($runId !== null) {
            $run = collect(PipelineRunState::snapshot($user->id)['runs'])->firstWhere('id', $runId);

            if ($run !== null && in_array($run['status'] ?? '', ['running', 'queued'], true)) {
                return response()->json([
                    'message' => 'Ese proceso sigue activo; minimiza el panel para ocultarlo.',
                    ...PipelineRunState::snapshot($user->id),
                ], 422);
            }

            PipelineRunState::dismiss($user->id, $runId);
        }

        return response()->json(PipelineRunState::snapshot($user->id));
    }

    /**
     * @return array<string, mixed>
     */
    private function pipelineSettings(): array
    {
        $intervalMin = config('services.wordpress.schedule_interval_min_hours');
        $intervalMax = config('services.wordpress.schedule_interval_hours');

        return [
            'limit' => 5,
            'limits' => [
                'limit' => ['min' => 1, 'max' => 50],
            ],
            'wordpress_url' => config('services.wordpress.url'),
            'wordpress_configured' => filled(config('services.wordpress.url'))
                && filled(config('services.wordpress.user'))
                && filled(config('services.wordpress.password')),
            'schedule' => [
                'timezone' => config('services.wordpress.schedule_timezone'),
                'max_per_day' => config('services.wordpress.schedule_max_per_day'),
                'start_hour' => config('services.wordpress.schedule_start_hour'),
                'start_minute' => config('services.wordpress.schedule_start_minute'),
                'interval' => $intervalMin
                    ? "{$intervalMin}-{$intervalMax} h"
                    : "{$intervalMax} h",
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newsPayload(News $news): array
    {
        $detail = $news->detail;
        $ai = $news->aiArticle;

        return [
            'id' => $news->id,
            'title' => $news->title,
            'url' => $news->url,
            'source' => $news->source,
            'category' => $news->category,
            'image_url' => $this->resolveImageUrl($news, $detail),
            'status_ia' => $news->status_ia,
            'created_at' => $news->created_at?->toIso8601String(),
            'detail' => $detail ? [
                'status' => $detail->status,
                'has_image' => $detail->status === 'processed'
                    && app(DownloadFeaturedImagesAction::class)->featuredImageExists($news),
                'researched' => $detail->researched_at !== null,
                'scraped_at' => $detail->scraped_at?->toIso8601String(),
                'last_error' => $detail->last_error,
            ] : null,
            'ai' => $ai ? [
                'generated_title' => $ai->generated_title,
                'can_preview' => filled($ai->body_html),
                ...$this->wordpressAiPayload($ai),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function wordpressAiPayload(NewsAiArticle $ai): array
    {
        return [
            'sent_wordpress' => (bool) $ai->sent_wordpress,
            'sent_wordpress_at' => $ai->sent_wordpress_at?->toIso8601String(),
            'wordpress_post_id' => $ai->wordpress_post_id,
            'wordpress_status' => $ai->wordpress_status,
            'wordpress_scheduled_at' => $ai->wordpress_scheduled_at?->toIso8601String(),
            'wordpress_url' => $ai->wordpress_url,
            'wordpress_author' => $ai->wordpress_author,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function previewPayload(News $news): ?array
    {
        $ai = $news->aiArticle;

        if ($ai === null || blank($ai->body_html)) {
            return null;
        }

        $allowedEmbeds = YoutubeExtractor::collect(
            $news->detail?->raw_html,
            $news->detail?->research_context,
            $news->detail?->research_raw,
        );

        return [
            'id' => $news->id,
            'source_title' => $ai->source_title ?? $news->title,
            'title' => trim((string) ($ai->generated_title ?? $news->title)),
            'excerpt' => $ai->excerpt,
            'body_html' => HtmlArticleSanitizer::sanitize($ai->body_html, $allowedEmbeds),
            'image_url' => $this->resolveImageUrl($news, $news->detail),
            'category' => $news->category ?? 'General',
            'source' => $news->source,
            'source_url' => $news->url,
            'model' => $ai->model,
            'article_type' => $ai->article_type,
            ...$this->wordpressAiPayload($ai),
            'image_source' => $news->detail?->featured_image_source,
            'can_regenerate' => ! $ai->sent_wordpress && $news->detail?->status === 'processed',
        ];
    }

    private function resolveImageUrl(News $news, ?NewsDetail $detail): ?string
    {
        $path = $detail?->featured_image_path;

        if (filled($path)) {
            if (Storage::disk('public')->exists($path)) {
                return '/storage/'.ltrim($path, '/');
            }

            foreach ($this->alternateFeaturedImagePaths($path) as $candidate) {
                if (Storage::disk('public')->exists($candidate)) {
                    return '/storage/'.ltrim($candidate, '/');
                }
            }
        }

        $listingImage = $news->image;

        if (filled($listingImage) && str_starts_with($listingImage, 'http')) {
            return $listingImage;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function alternateFeaturedImagePaths(string $path): array
    {
        $info = pathinfo($path);
        $dir = $info['dirname'] ?? '';
        $filename = $info['filename'] ?? '';

        if ($filename === '') {
            return [];
        }

        $prefix = $dir !== '' && $dir !== '.' ? $dir.'/' : '';
        $candidates = [];

        foreach (['webp', 'jpg', 'jpeg', 'png'] as $extension) {
            $candidate = $prefix.$filename.'.'.$extension;

            if ($candidate !== $path) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $options
     */
}
