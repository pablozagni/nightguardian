<?php

namespace App\Http\Controllers\Api;

use App\Ingest\BatchValidator;
use App\Ingest\IngestHttpException;
use App\Ingest\IngestProcessor;
use App\Ingest\IngestRateLimiter;
use App\Ingest\PayloadDecoder;
use App\Ingest\TokenAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/ingest — stateless, token authenticated (docs/architecture.md section 4).
 */
class IngestController
{
    public function __construct(
        private readonly TokenAuthenticator $tokens,
        private readonly PayloadDecoder $decoder,
        private readonly BatchValidator $validator,
        private readonly IngestRateLimiter $limiter,
        private readonly IngestProcessor $processor,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            return $this->handle($request);
        } catch (IngestHttpException $e) {
            return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->status, $e->headers);
        }
    }

    private function handle(Request $request): JsonResponse
    {
        $ip = (string) $request->ip();

        $wait = $this->limiter->tooManyBadTokens($ip);
        if ($wait !== null) {
            throw IngestHttpException::rateLimited($wait);
        }

        $token = $this->tokens->authenticate($request);
        if ($token === null) {
            $this->limiter->hitBadToken($ip);

            throw IngestHttpException::unauthorized();
        }

        $project = $token->project;
        if ($project->isArchived()) {
            throw IngestHttpException::archived();
        }

        $body = $this->decoder->decode($request);
        $events = $this->validator->events($body);

        $wait = $this->limiter->consume($project, count($events));
        if ($wait !== null) {
            throw IngestHttpException::rateLimited($wait);
        }

        $dryRun = in_array(strtolower((string) $request->header('X-NightGuardian-Dry-Run')), ['1', 'true'], true);
        $report = $this->processor->process($project, $body, $events, $dryRun);

        return response()->json($dryRun ? $report + ['dry_run' => true] : $report, 202);
    }
}
