<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\AbstractPaginator;

/**
 * Single source of truth for the response envelope mandated by the PRD.
 *
 * Success: { "success": true,  "data": ..., "message": "..." }
 * Failure: { "success": false, "message": "...", "errors": {...} }
 *
 * Keeping this in one class makes a change to the contract a one-file change
 * rather than a search-and-replace across every controller.
 */
final class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Operation successful.',
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        $payload = [
            'success' => true,
            'data' => self::normalise($data),
            'message' => $message,
        ];

        // Paginated collections carry their metadata alongside data rather than
        // nested inside it, so clients read links/meta from a stable location.
        if ($pagination = self::paginationMeta($data)) {
            $payload['meta'] = $pagination['meta'];
            $payload['links'] = $pagination['links'];
        }

        return response()->json($payload, $status);
    }

    public static function created(mixed $data = null, string $message = 'Resource created.'): JsonResponse
    {
        return self::success($data, $message, Response::HTTP_CREATED);
    }

    public static function deleted(string $message = 'Resource deleted.'): JsonResponse
    {
        // 200 rather than 204: the envelope requires a body, and a 204 response
        // carrying a body is invalid HTTP.
        return self::success(null, $message);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function error(
        string $message,
        int $status = Response::HTTP_BAD_REQUEST,
        array $errors = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public static function validationError(array $errors, string $message = 'Validation failed.'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Unwrap resources/paginators into plain arrays so the envelope has the
     * same shape regardless of what the controller handed us.
     */
    private static function normalise(mixed $data): mixed
    {
        if ($data instanceof ResourceCollection || $data instanceof JsonResource) {
            $resolved = $data->response()->getData(assoc: true);

            return $resolved['data'] ?? $resolved;
        }

        if ($data instanceof AbstractPaginator) {
            return $data->items();
        }

        if ($data instanceof Arrayable) {
            return $data->toArray();
        }

        return $data;
    }

    /**
     * @return array{meta: array<string, mixed>, links: array<string, mixed>}|null
     */
    private static function paginationMeta(mixed $data): ?array
    {
        if ($data instanceof ResourceCollection) {
            $resolved = $data->response()->getData(assoc: true);

            return isset($resolved['meta'])
                ? ['meta' => $resolved['meta'], 'links' => $resolved['links'] ?? []]
                : null;
        }

        if ($data instanceof AbstractPaginator) {
            $resolved = $data->toArray();

            return [
                'meta' => array_diff_key($resolved, array_flip(['data', 'links'])),
                'links' => $resolved['links'] ?? [],
            ];
        }

        return null;
    }
}
