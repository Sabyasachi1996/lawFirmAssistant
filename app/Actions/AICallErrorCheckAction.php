<?php
namespace App\Actions;
class AICallErrorCheckAction {
    public function execute(array $response){
         //check if error comes from AI call
        if($response['error']){
            return [
                'error'=>true,
                'message'=>'AI provider unreachable or API error'
            ];
        }
        $content = data_get($response,'data.choices.0.message.content');
        //check if ai response actually came and it is a valid json
        if(!$content || !$this->isValidJson($content)){
            return [
                'error' => true,
                'message' => 'AI response was malformed or empty'
            ];
        }
        return [
            'error' => false,
            'message' => 'AI call validation successful'
        ];
    }
    private function isValidJson(string $string):bool{
        json_decode($string,true);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
