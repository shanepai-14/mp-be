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

    public function ErrorResponse($message, $errorCode): JsonResponse
    {
        return new JsonResponse([
            'status' => true,
            'message' => $message,
        ], $errorCode);
    }

    public function ArrayResponse($message, $success, $failed): JsonResponse
    {
        return new JsonResponse([
            'status' => true,
            'message' => $message,
            'success' => $success,
            'failed' => $failed
        ], 200);
    }
}
