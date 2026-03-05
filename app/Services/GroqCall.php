<?php
namespace App\Services;

use App\Interfaces\LLMCall;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqCall implements LLMCall {
    public function createData(string $prompt): array {
        $response = Http::when(app()->environment('local'),function($client){
            $client->withoutVerifying();
        })
        ->withToken(config('customparam.GROQ_API_KEY'))
        ->timeout((int)config('customparam.GROQ_CALL_TIMEOUT')) // Good practice for AI calls
        ->retry(3,100)
        ->post(config('customparam.GROQ_URL'),[
            "model" => config('customparam.GROQ_MODEL'),
            "messages" => [
                [
                    "role" => "user",
                    "content" => $prompt
                ]
            ],
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
        Log::alert('Groq API failure', [
            'status' => $response->status(),
            'body' => $response->body(),
            'prompt_preview' => substr($prompt, 0, 100)
        ]);
        return [
            'error' => true,
            'message' => 'groq call failed with status: '.$response->status(),
            'data' => $response->body()
        ];
    }
}
