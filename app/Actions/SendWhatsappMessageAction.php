<?php
namespace App\Actions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
class SendWhatsappMessageAction {
    public function execute(string $messageType, string $message, string $phoneNumber):array{
        try{
            $body = null;
            if(!in_array($messageType,['template','text'])){
                return [
                    'error'=>true,
                    'message'=>'invalid type',
                    'data'=>[
                        'detailed_message' => "Type {$messageType} is not supported"
                    ]
                ];
            }
            $body = [
                "messaging_product"=> "whatsapp",
                "to"=> $phoneNumber,
                "type"=> $messageType,
            ];
            if($messageType === 'template'){
                $body['template'] = [
                    "name"=> 'hello_world',
                    "language"=> [
                        "code"=>"en_US"
                    ]
                ];
            }else{
                $body['recipient_type'] = "individual";
                $body['text'] = [
                    "body"=>$message
                ];
            }
            $apiVersion = config('customparam.WHATSAPP_API_VERSION');
            $phoneNumberId = config('customparam.WHATSAPP_PHONE_NUMBER_ID');
            $apiUrl = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages";
            $token = config('customparam.WHATSAPP_ACCESS_TOKEN');
            $response = Http::when(app()->environment('local'),function($client){
                return $client->withoutVerifying();
            })->withToken($token)
            ->timeout(10)
            ->retry(3,100,function($exception){
                return $exception instanceof ConnectionException;
            })
            ->post($apiUrl,$body);
            $response->throw();
            return [
                'error' => false,
                'message' => 'message sent successfully',
                'statusCode' => $response->status(),
                'data' => $response->json()
            ];
        }catch(RequestException $e){
            return [
                'error' => true,
                'message' => 'some error has occurred',
                'statusCode' => $e->response->status() ?? 500,
                'data' => [
                    'details' => $e->response->json(),
                    'detailed_message' => $e->getMessage()
                ]
            ];
        }catch(ConnectionException $e){
            return [
                'error' => true,
                'message' => 'connection error',
                'data' => [
                    'detailed_message' => $e->getMessage()
                ]
            ];
        }catch(\Exception $e){
            return [
                'error' => true,
                'message'=> 'unexpected error',
                'data' => [
                    'detailed_message'=>$e->getMessage()
                ]
            ];
        }
    }
}
