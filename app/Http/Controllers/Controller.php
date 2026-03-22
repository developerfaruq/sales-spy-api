<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Return a successful API response.
     */
    protected function successResponse(
        mixed $data = null,
        string $message = 'Success',
        int $statusCode = 200,
        array $meta = []
    ): \Illuminate\Http\JsonResponse {

        $response = [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Return an error API response.
     */
    protected function errorResponse(
        string $message = 'An error occurred',
        mixed $errors = null,
        int $statusCode = 400
    ): \Illuminate\Http\JsonResponse {

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $statusCode);
    }
}
