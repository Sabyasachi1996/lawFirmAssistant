<?php
namespace App\Actions;
use App\Services\GroqCall;
class AICallAction {
    public function execute(string $prompt=''):array{
        $groqCall = new GroqCall();
        $aiResponse = $groqCall->createData($prompt);
        return $aiResponse;
    }
}
