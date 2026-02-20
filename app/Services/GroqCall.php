<?php
namespace App\Services;

use App\Interfaces\LLMCall;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqCall implements LLMCall {
    public function createData(string $prompt): array {
        $response = Http::withToken(env('GROQ_API_KEY'))
        ->withoutVerifying() // Hide this in .env later!
        ->timeout((int)env('GROQ_CALL_TIMEOUT')) // Good practice for AI calls
        ->post(env('GROQ_URL'),[
            "model" => env('GROQ_MODEL'),
            "messages" => [[
                "role" => "user",
                "content" => $prompt
            ]],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.7,
        ]);
        if ($response->successful()) {
            // Return the decoded JSON content from the AI
            return [
                'error' => false,
                'message' => 'AI call successful',
                'data' => $response->json()
            ];
        }
        Log::alert('groq call failure',[$response->body()]);
        return ['error' => true, 'message' => 'groq call failed','data' => $response->body()];
    }
}
