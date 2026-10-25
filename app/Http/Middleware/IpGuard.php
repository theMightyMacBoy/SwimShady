<?php

namespace App\Http\Middleware;

use App\Services\IpSecurity;
use App\Support\ExactResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For every /api request: 403 banned IPs, then log the outcome. 401/403 responses
 * feed the API ban counter. Login has its own counter, and it is not under /api.
 */
class IpGuard
{
    public function __construct(private readonly IpSecurity $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->path();
        if (! str_starts_with($path, 'api')) {
            return $next($request);
        }

        $ip = $this->security->clientIp($request);

        try {
            if ($this->security->isBlocked($ip)) {
                $response = ExactResponse::json(['error' => 'your IP is temporarily blocked'], 403);
                $this->record($request, $ip, 403, false);

                return $response;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $response = $next($request);

        $this->record($request, $ip, $response->getStatusCode(), true);

        return $response;
    }

    private function record(Request $request, string $ip, int $status, bool $countFailure): void
    {
        try {
            $this->security->recordApi(
                $ip,
                $request->method(),
                '/'.$request->path(),
                $status,
                $request->user()?->id,
                $countFailure,
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
