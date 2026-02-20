<?php
declare(strict_types=1);
namespace App\Services;
use function Codewithkyrian\Transformers\Pipelines\pipeline;
use Codewithkyrian\Transformers\Transformers;
use Cloudstudio\Ollama\Facades\Ollama;
class VectorService
{
    /**
     * Create a new class instance.
     */
    protected $pipe;
    public function __construct(){}
    public function getVector(string $text):array
    {
        try{
            if(empty(trim($text))){
                return [];
            }
            $result = Ollama::model('all-minilm')->embeddings($text);
            return $result['embedding'] ?? [];
        }catch(\Exception $e){
            return [];
        }
    }
}
