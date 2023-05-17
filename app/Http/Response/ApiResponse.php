<?php

namespace App\Http\Response;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public function SuccessResponse($message, $data): JsonResponse
    {
        return new JsonResponse([
            'status' => true,
            'message' => $message,
            'data' => $data
        ], 200);
    }

    public function ErrorResponse($message): JsonResponse
    {
        return new JsonResponse([
            'status' => true,
            'message' => $message,
        ], 409);
    }
}
