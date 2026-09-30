<?php

namespace App\Http\Controllers;

use App\Ai\Agents\JcAgent;
use App\Services\GoDaddyEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function chat(Request $request, GoDaddyEmailService $emailService): JsonResponse
    {
        try {
            $request->validate([
                'message' => ['required', 'string'],
                'conversation_id' => ['nullable', 'string'],
            ]);

            $agent = new JcAgent($emailService);

            if ($request->filled('conversation_id')) {
                $response = $agent
                    ->continue(
                        $request->conversation_id,
                        as: $request->user()
                    )
                    ->prompt($request->message);
            } else {
                $response = $agent
                    ->forUser($request->user())
                    ->prompt($request->message);
            }

            return response()->json([
                'success' => true,
                'response' => $response->text,
                'conversation_id' => $response->conversationId,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
