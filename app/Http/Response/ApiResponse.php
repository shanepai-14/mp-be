<?php

namespace App\Http\Response;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public function SuccessResponse($message, $data): JsonResponse
    {
        return new JsonResponse([
            'status' => 200,
            'message' => $message,
            'data' => $data
        ], 200);
    }

    public function ErrorResponse($message, $errorCode): JsonResponse
    {
        return new JsonResponse([
            'status' => $errorCode,
            'message' => $message,
        ], $errorCode);
    }

    public function ArrayResponse($message, $success, $failed): JsonResponse
    {
        return new JsonResponse([
            'status' => 200,
            'message' => $message,
            'success' => $success,
            'failed' => $failed
        ], 200);
    }
}
