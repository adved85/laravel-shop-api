<?php

namespace App\Support\API;

use \Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\JsonResource;

use App\Enums\HTTPCodes;

class ApiResponse
{
    public function success(
        mixed $data = null,
        string $message = 'Success',
        int $status = 200,
    ): JsonResponse {

        return response()->json([
            'success' => true,
            'message' => $message,
            'code'    => $status,
            'data'    => $data instanceof Arrayable ? $data->toArray() : $data,
        ], $status);
    }

    public function error(
        string $message = 'Error',
        int $status = 400,
        ?array $errors = null,
    ): JsonResponse {

        $body = [
            'success' => false,
            'message' => $message,
            'code'    => $status,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }

    /**
     * success helpers
     */
    public function ok($data, $message = 'Success'): JsonResponse
    {
        return $this->success($data, $message, HTTPCodes::OK->value);
    }

    public function created($data, $message = 'Created'): JsonResponse
    {
        return $this->success($data, $message, HTTPCodes::CREATED->value);
    }

    public function accepted($data, $message = 'Accepted'): JsonResponse
    {
        return $this->success($data, $message, HTTPCodes::ACCEPTED->value);
    }

    public function noContent(): Response
    {
        return response()->noContent(); // HTTP 204
    }

    public function paginated(LengthAwarePaginator $paginator, string $resourceClass, string $message = 'Success'): JsonResponse
    {
        return $this->success([
            'items'      => $resourceClass::collection($paginator->items()),
            'pagination' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last'  => $paginator->url($paginator->lastPage()),
                'prev'  => $paginator->previousPageUrl(),
                'next'  => $paginator->nextPageUrl(),
            ],
        ], $message);
    }

    /**
     * error helpers
     */


    public function badRequest($message = 'Bad Request') : JsonResponse
    {
        return $this->error($message, HTTPCodes::BAD_REQUEST->value);
    }

    public function unauthenticated($message = 'Unauthenticated'): JsonResponse
    {
        return $this->error($message, HTTPCodes::UNAUTHENTICATED->value);
    }

    public function unauthorized($message = 'Unauthorized') : JsonResponse {
        return $this->error($message, HTTPCodes::UNAUTHORIZED->value);
    }

    public function notFound($message = 'Not Found'): JsonResponse
    {
        return $this->error($message, HTTPCodes::NOT_FOUND->value);
    }

    public function conflict($message = 'Conflict'): JsonResponse
    {
        return $this->error($message, HTTPCodes::CONFLICT->value);
    }

    public function validationError($message = 'Validation failed', ?array $errors = null): JsonResponse
    {
        return $this->error($message, HTTPCodes::VALIDATION_ERROR->value, $errors);
    }

    public function serverError($message = 'Internal server error') : JsonResponse
    {
        return $this->error($message, HTTPCodes::INTERNAL_SERVER_ERROR->value);
    }
}
