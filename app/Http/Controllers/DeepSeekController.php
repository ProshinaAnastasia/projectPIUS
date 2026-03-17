<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class DeepSeekController extends Controller
{
    public function generate(Request $request)
    {
        $prompt = $request->input('prompt');

        if (!$prompt) {
            return response()->json(['error' => 'Prompt is required'], 400);
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(180)
                ->withToken(env('DEEPSEEK_TOKEN'))
                ->post('https://api.deepseek.com/v1/chat/completions', [
                    'model' => 'deepseek-chat',
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if ($response->failed()) {
                return response()->json([
                    'error' => 'DeepSeek API error',
                    'details' => $response->body(),
                ], 500);
            }

            $text = $response->json('choices.0.message.content');

            return response()->json(['text' => $text]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Exception occurred',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
