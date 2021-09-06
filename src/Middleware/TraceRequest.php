<?php

namespace Keepsuit\LaravelOpenTelemetry\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\Trace\Span;
use OpenTelemetry\Trace\SpanStatus;
use Symfony\Component\HttpFoundation\Response;

class TraceRequest
{
    public function handle(Request $request, Closure $next)
    {
        if (config('opentelemetry.exporter', null) === null) {
            return $next($request);
        }

        if ($request->is(config('opentelemetry.excluded_paths', []))) {
            return $next($request);
        }

        Tracer::initFromRequest($request);

        $route = Route::current() ? Route::current()->uri() : $request->path();
        $route = str_starts_with($route, '/') ? $route : '/'.$route;

        Tracer::start($route, function (Span $span) use ($route, $request) {
            $span->setAttribute('http.method', $request->method());
            $span->setAttribute('http.url', $request->getUri());
            $span->setAttribute('http.target',$request->getRequestUri());
            $span->setAttribute('http.route', $route);
            $span->setAttribute('http.host', $request->getHttpHost());
            $span->setAttribute('http.scheme', $request->getScheme());
            $span->setAttribute('http.user_agent', $request->userAgent());
            $span->setAttribute('http.request_content_length', $request->header('Content-Length'));
        });

        /** @var Response $response */
        $response = $next($request);

        Tracer::stop($route, function (Span $span) use ($response) {
            if ($response instanceof Response) {
                $span->setAttribute('http.status_code', $response->getStatusCode());
                $span->setAttribute('http.response_content_length', strlen($response->getContent()));

                if ($span->getStatus()->getCanonicalStatusCode() === SpanStatus::UNSET) {
                    if ($response->isSuccessful()) {
                        $span->setSpanStatus(SpanStatus::OK);
                    }
                    if ($response->isServerError() || $response->isClientError()) {
                        $span->setSpanStatus(SpanStatus::ERROR);
                    }
                }
            }
        });

        return $response;
    }
}
