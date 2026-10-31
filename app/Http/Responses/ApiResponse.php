<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/** Consistent envelope for every API response: {data, meta, errors}. */
final class ApiResponse
{
    public static function ok(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        if ($data instanceof ResourceCollection && $data->resource instanceof LengthAwarePaginator) {
            $p = $data->resource;
            $meta += ['pagination' => [
                'current_page' => $p->currentPage(), 'per_page' => $p->perPage(),
                'total' => $p->total(), 'last_page' => $p->lastPage(),
            ]];
            $data = $data->collection->map(fn ($r) => $r->resolve(request()))->all();
        } elseif ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        }

        return new JsonResponse(['data' => $data, 'meta' => (object) $meta, 'errors' => []], $status);
    }

    public static function created(mixed $data = null, array $meta = []): JsonResponse
    {
        return self::ok($data, $meta, 201);
    }

    /** @param list<array{code:string, message:string, field?:string}> $errors */
    public static function error(int $status, array $errors, array $meta = []): JsonResponse
    {
        return new JsonResponse(['data' => null, 'meta' => (object) $meta, 'errors' => $errors], $status);
    }

    public static function fail(int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        return self::error($status, [array_merge(['code' => $code, 'message' => $message], $extra)]);
    }
}
