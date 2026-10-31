<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional `Idempotency-Key` header on mutating API calls: the first response
 * is stored and replayed for retries with the same key and identical body;
 * a different body with the same key is rejected (422).
 */
class IdempotentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! $key || $request->isMethodSafe()) {
            return $next($request);
        }
        if (! preg_match('/^[A-Za-z0-9_\-:.]{8,120}$/', $key)) {
            return ApiResponse::fail(422, 'invalid_idempotency_key', 'Idempotency-Key 8-120 karakter olmalıdır.');
        }
        $scope = $request->route()?->getName().':'.($request->user()?->id ?? 'guest:'.sha1((string) $request->ip()));
        $hash = hash('sha256', $request->getContent());

        $existing = IdempotencyKey::where('scope', $scope)->where('key', $key)->first();
        if ($existing) {
            if (! hash_equals($existing->request_hash, $hash)) {
                return ApiResponse::fail(422, 'idempotency_mismatch', 'Bu Idempotency-Key farklı bir istek için kullanılmış.');
            }
            if ($existing->response_status) {
                return new JsonResponse(json_decode((string) $existing->response_body, true), $existing->response_status, ['Idempotent-Replayed' => 'true']);
            }

            return ApiResponse::fail(409, 'request_in_progress', 'Aynı istek hâlâ işleniyor.');
        }
        try {
            $record = IdempotencyKey::create(['scope' => $scope, 'key' => $key, 'request_hash' => $hash, 'expires_at' => now()->addDay()]);
        } catch (UniqueConstraintViolationException) {
            return ApiResponse::fail(409, 'request_in_progress', 'Aynı istek hâlâ işleniyor.');
        }
        $request->attributes->set('idempotency_key', $key);

        $response = $next($request);
        if ($response->getStatusCode() < 500) {
            $record->forceFill(['response_status' => $response->getStatusCode(), 'response_body' => $response->getContent()])->save();
        } else {
            $record->delete();
        }

        return $response;
    }
}
