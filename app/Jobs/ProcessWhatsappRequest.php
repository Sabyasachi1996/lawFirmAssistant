<?php
declare(strict_types=1);
namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\GroqCall;
use App\Services\VectorService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessWhatsappRequest implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $incomingInputPayload){}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::withContext([
            'job_id' => $this->job->getJobId(),
            'action' => $this->incomingInputPayload['action'] ?? 'unknown'
        ]);
        Log::info('whatsapp message processing has started in queue');
        try{
            $action = $this->incomingInputPayload['action'];
            $data = $this->incomingInputPayload['data'];
            if(!$action || !$data){
                Log::warning('Webhook handling initiated without any action or data payload');
                return;
            }
            match($action){
                'INCOMING_MESSAGE'=>$this->handleIncomingMessage($data),
                'INCOMING_STATUS'=>$this->handleIncomingStatus($data),
                default=>$this->handleDefault($data)
            };
        }catch(\Throwable $e){
            Log::error('webhook handling error: '.$e->getMessage(),['trace'=>$e->getTraceAsString()]);
            throw $e;
        }
    }
    private function handleIncomingMessage(array $payloadData):bool{
        Log::info("incoming message handling started");
        return DB::transaction(function() use($payloadData){
            $now = now();
            $isAIPhaseSuccessfullyDone = false;
            $r1 = $this->saveConvoAndMessage($payloadData);
            if($r1['error']){
                throw new \Exception('unable to store convo/message');
            }
            //get the wa_id of the conversation
            $waId = data_get($payloadData,'entry.0.changes.0.value.contacts.0.wa_id');
            //take current message for prompt TODO: handle the audio message(important)
            $currentMessage = data_get($payloadData,'entry.0.changes.0.value.messages.0.text.body');
            //get the concerned conversation
            $concernedConvo = Conversation::with('messages')
            ->where('wa_id',$waId)
            ->firstOrFail();
            //take current metadata of conversation for prompt
            $currentMetadata = $concernedConvo->metadata;
            //get the current message timestamp
            $messageTimestampObject = Carbon::createFromTimestamp($r1['message_timestamp']);
            //if the last sent message(means the ongoing message here) sent more than 23 hours ago
            if($messageTimestampObject->diffInHours(now()) > 23){
                $reply = 'How may I help you today?';
                $messageType = 'template';
            }elseif($r1['action'] === 'RESPOND_THROUGH_AI'){
                //generate history string for prompt
                $historyString = $this->generateMessageHistoryString($concernedConvo);
                //generate semantic recall string for prompt
                $semanticRecallString = $this->generateSemanticRecallString($concernedConvo->id,$currentMessage);
                //create prompt for ai call
                $prompt = $this->generatePrompt($currentMetadata,$historyString,$semanticRecallString,$currentMessage);
                //do the ai call
                $aiResponse = $this->doAICall($prompt);
                if($this->checkAICallErrors($aiResponse)['error']){
                    throw new \Exception('AI provider unreachable or returned error');
                }
                $aiContent = json_decode(data_get($aiResponse,'data.choices.0.message.content'),true);
                //gather important informations from the ai response
                $reply = $aiContent['reply'] ?? 'I am sorry, I am having trouble processing that.';
                $metadata = $aiContent['metadata'] ?? json_decode($currentMetadata,true);
                $is_completed = $aiContent['is_completed'] ?? false;
                $metadataCount = count($metadata);
                $isAIPhaseSuccessfullyDone = true;
                $messageType = 'text';
            }elseif($r1['action'] === 'RESPOND'){
                $reply = 'Our team is reviewing your request based on your given details. You will be contacted ASAP. Thank you.';
                $messageType = 'text';
            }else{
                throw new \Exception('no valid action');
            }
            $sendMessageResponse = $this->sendWhatsappMessage($messageType,$reply,$concernedConvo->phone_number);
            if($sendMessageResponse['error']){
                throw new \Exception('sending message failed');
            }
            $messageDataset = [
                'whatsapp_message_id'=>$sendMessageResponse['data']['messages'][0]['id'],
                'direction'=>'outbound',
                'type'=>'text',
                'status'=>'sent',//$sendMessageResponse['data']['messages'][0]['message_status']),//TODO: status is not coming have to check why
                'message_timestamp'=>$now,
                'failed_reason'=>null,
                'body' => $reply,
            ];
            $concernedConvo->messages()->create($messageDataset);
            $convoUpdateDataset = [
                'last_message_at' => $now
            ];
            if($isAIPhaseSuccessfullyDone){
                $convoUpdateDataset['metadata'] = json_encode($metadata);
                $convoUpdateDataset['status'] = $is_completed && $metadataCount >= (int) config('customparam.MAX_INFO_META_KEY_COUNT')?'qualified':'active';
            }
            $concernedConvo->update($convoUpdateDataset);
            return true;
        });
    }
    private function generatePrompt(string|null $currentMeta,string $historyString,string $semantiRecallString, string $currentMessage){
        $meta = $currentMeta ?? '{}';
        $prompt = <<<EOD
                ### 1. ROLE
                    You are the Legal Intake Assistant for a criminal defense law firm. Your voice is professional, empathetic, and direct.

                ### 2. MISSION
                Your goal is to fill the following metadata slots:
                - name, age, email, contact_number, occupation, address, case_details.

                ### 3. STEPS OUTLINE
                    - Check the CURRENT_METADATA section. If all the 7 keys already have values there, skip checking the USER_CURRENT_MESSAGE,
                    SEMANTIC_RECALL,RECENT_HISTORY sections. In the response, mark is_completed as true and provide the CURRENT_METADATA JSON as
                    value of metadata key and fill the reply key with an "Administration wil contact you sortly" kind of message.
                    - If CURRENT_METADATA section does not have all the 7 fields present-
                    a. check the USER_CURRENT_MESSAGE, SEMANTIC_RECALL, RECENT_HISTORY sections.
                    b. Utilize USER_CURRENT_MESSAGE, SEMANTIC_RECALL and RECENT_HISTORY sections to find out the missing key values of metadata.
                    c. In this scenario, if there was a key value already present in the CURRENT_METADATA section and you found a new value of it in USER_CURRENT_MESSAGE, SEMANTIC_RECALL
                    or RECENT_HISTORY section, update that value in the metadata.
                    d. Thus you create a new updated metadata JSON, which you will provide in the metadata key of your response.
                    e. Then, create a reply message for the user.
                    f. If after metadata processing, if all 7 field values are collected, create a message which basically tells the user that
                        the his/her request is being reviewed and the administration will contact him/her after sometime. The is_completed key will also be
                        marked as true in your final response.
                    g. If all metadata key values are still not collected yet, create a message which asks for the missing field details.
                        In addition to these, if the USER_CURRENT_MESSAGE mathes with a context from RECENT_HISTORY or SEMANTIC_CALL section,
                        add some sentences addressing that point in the reply too if possible. But do not move away from your actual motto.
                ### 4. CONSTRAINTS
                - DO NOT give legal advice.
                - DO NOT "preach" or lecture the client.
                - Use the **SEMANTIC_RECALL** and **RECENT_HISTORY** provided to avoid asking questions already answered.
                - In your response, in metadata key, only keep those keys, whose values are already collected.
                Leave out the other keys of metadata in response, which are not yet filled or given.

                ### 6. THINGS TO REMEMBER
                - Your main motto is to collect the necessary details(those 7 keys mentioned earlier) of the metadata.
                - Consider a key of metadata is filled even if it receives a minimum details. This applies for case_details too i.e.
                for case_details key of metadata, if you are able to collect at least one sentence, consider that as a filled value, do not ask for more.
                - All the information of the metadata is necessary. keep asking if not provided.
                - Once you realize that you currently have all the values needed for the metadata, you will reply saying thank you and the
                  administration will contact you sortly.
                - If you already have all the metadata present, reply with a message saying thank you and the administration will contact you shortly.
                ### 7. CURRENT CONTEXT
                - **CURRENT_METADATA**: $meta
                - **SEMANTIC_RECALL**: $semantiRecallString
                - **RECENT_HISTORY**: $historyString
                - **USER_CURRENT_MESSAGE**: $currentMessage

                ### 8. MANDATORY OUTPUT FORMAT
                Return ONLY a valid JSON object. No pre-amble, no conversational text outside the JSON.
                {
                "reply": "Your friendly message to the client",
                "metadata": { "name": "...", "age": "...", ... },
                "is_completed": boolean
                }
            EOD;
            return $prompt;
    }
    private function generateSemanticRecallString(int $convoId,string $currentMessage):string{
        $vectorService = new VectorService();
        $currentMessageVector = $vectorService->getVector($currentMessage);
        $vectorLiteral = '['.implode(',',$currentMessageVector).']';
        //get the ID of those messages which are already present in the history string
        $excludeIds = Message::where('conversation_id',$convoId)
        ->whereNotNull('body')
        ->latest()
        ->limit(10)
        ->pluck('id');
        //last 3 entry fetch close to the current message context
        $semanticRecallData = Message::select('direction','body')
        ->where('conversation_id',$convoId)
        ->whereNotNull('body')
        ->whereNotIn('id',$excludeIds)
        ->orderByRaw("embedding <-> ?",[$vectorLiteral])
        ->limit(3)
        ->get();
        //the semantic recall string creation for prompt
        $semanticRecallString = "";
        foreach($semanticRecallData as $eachMessage){
        $role = $eachMessage->direction === 'inbound'?'user':'assistant';
        $message = trim(str_replace(["\r","\n"]," ",$eachMessage->body));
        $semanticRecallString.="- $role: $message\n";
        }
        return filled($semanticRecallString)?$semanticRecallString:"No relevant past context found.";
    }
    private function generateMessageHistoryString(Conversation $conversation):string{
        //get history of 10 messages of the conversation and take it in a certain format
        $history = $conversation->messages()
        ->whereNotNull('body')
        ->latest()
        ->limit(10)
        ->get()
        ->reverse();
        $historyString = '';
        foreach($history as $eachMessageEntry){
            $role = $eachMessageEntry->direction === 'inbound'?'user':'assistant';
            $message = trim(str_replace(["\r","\n"]," ",$eachMessageEntry->body));
            $historyString .= "- $role: $message\n";
        }
        return filled($historyString)?$historyString:'No previous history.';
    }
    private function doAICall(string $prompt):array{
        $groqCall = new GroqCall();
        $aiResponse = $groqCall->createData($prompt);
        return $aiResponse;
    }
    private function checkAICallErrors(array $aiCallResponse){
        //check if error comes from AI call
        if($aiCallResponse['error']){
            return [
                'error'=>true,
                'message'=>'AI provider unreachable or API error'
            ];
        }
        $content = data_get($aiCallResponse,'data.choices.0.message.content');
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
    //save convo and message and then decide what to do next
    private function saveConvoAndMessage(array $payloadData):array{
        Log::info('convo and message saving function started');
        //collect data to insert in conversations and messages table
        $name  = data_get($payloadData,'entry.0.changes.0.value.contacts.0.profile.name');
        $phoneNumber = data_get($payloadData,'entry.0.changes.0.value.messages.0.from');
        $messageId = data_get($payloadData,'entry.0.changes.0.value.messages.0.id');
        $type = data_get($payloadData,'entry.0.changes.0.value.messages.0.type');
        $messageTimestamp = data_get($payloadData,'entry.0.changes.0.value.messages.0.timestamp');
        //existing message check for duplicacy
        $doesMessageAlreadyExists = Message::where('whatsapp_message_id',$messageId)->exists();
        if($doesMessageAlreadyExists){
            return [
                'error' => true,
                'message' => 'duplicate message entry',
                'action'=>null,
                'metadata'=>null
            ];
        }
        try{
            $now = now();
            $waId = data_get($payloadData,'entry.0.changes.0.value.contacts.0.wa_id');
            $messageBody = null;
            $embeddingArray = null;
            //find or create the conversation table entry
            $convoData = Conversation::firstOrCreate(
                [
                    'wa_id' => $waId,
                ],
                [
                    'phone_number'=>$phoneNumber,
                    'name'=>$name,
                    'status'=> 'active',
                    'created_at'=>$now,
                    'updated_at'=>$now
                ]
            );
            //check if the message is the first message of the conversation
            $isInitial =!$convoData->messages()->exists();
            //create message dataset
            $messageDataset = [
                'whatsapp_message_id'=>$messageId,
                'direction'=>'inbound',
                'type'=>$type,
                'status'=>'received',
                'message_timestamp'=>Carbon::createFromTimestamp($messageTimestamp),
                'failed_reason'=>null,
            ];
            //separation of some data based on text and audio input message
            switch($type){
                case 'text':
                    $messageBody = data_get($payloadData,'entry.0.changes.0.value.messages.0.text.body');
                    break;
                case 'audio':
                    $messageBody = 'demo transcribed text';  //TODO: this message body will come from text transcribed from the audio message
                    $audioData = data_get($payloadData,'entry.0.changes.0.value.messages.0.audio');
                    $messageDataset['file_mime_type'] = $audioData['mime_type'] ?? null;
                    $messageDataset['file_sha256'] = $audioData['sha256'] ?? null;
                    $messageDataset['file_id'] = $audioData['id'] ?? null;
                    $messageDataset['file_url'] = $audioData['url'] ?? null;
                    $messageDataset['is_file_voice'] = $audioData['voice'] ?? null;
                    break;
            }
            $vectorService = new VectorService();
            $embeddingArray = $vectorService->getVector($messageBody);
            $messageDataset['body'] = $messageBody;
            $messageDataset['embedding'] = '['.implode(',',$embeddingArray).']';
            //messages table entry
            $convoData->messages()->create($messageDataset);
            //create the conversation update data
            $convoUpdateData = [
                'last_message_at' => Carbon::createFromTimestamp($messageTimestamp)
            ];
            //based on convo status, decide what to do next
            if($convoData->status === 'closed'){
                $convoUpdateData['metadata'] = null;
                $convoUpdateData['status'] = 'active';
                $convoData->status = 'active';
            }
            $convoData->update($convoUpdateData);
            $action = $convoData->status === 'qualified' ? 'RESPOND' : 'RESPOND_THROUGH_AI';
            $response = [
                'error'=> false,
                'action'=>$action,
                'metadata'=>$convoData->metadata,
                'is_initial' => $isInitial,
                'message_timestamp' => $messageTimestamp,
                'input_message' => $messageBody //TODO:later change the logic here to get the text format of the last message if the last message is an audio message too
            ];
            return $response;
        }catch(\Exception $e){
            Log::error('convo and message storage failure',['line'=> $e->getLine()]);
            throw $e;
        }
    }

    private function handleIncomingStatus(array $payloadData):bool{
        try{
            $now = date('Y-m-d h:i:s');
            $messageId = $payloadData['entry'][0]['changes'][0]['value']['statuses'][0]['id'];
            $messageStatus = $payloadData['entry'][0]['changes'][0]['value']['statuses'][0]['status'];
            $existingMessage = Message::where('whatsapp_message_id',$messageId)->first();
            if(!$existingMessage){
                return false;
            }
            $updateMessageDataset = [
                'status' => $messageStatus,
                'updated_at' => $now
            ];
            $existingMessage->update($updateMessageDataset);
            return true;
        }catch(\Exception $e){
            return false;
        }
    }
    private function handleDefault($payloadData){}
    private function isValidJson(string $string):bool{
        json_decode($string,true);
        return json_last_error() === JSON_ERROR_NONE;
    }
    private function sendWhatsappMessage(string $type,string $message,string $phoneNumber):array{
        try{
            $body = null;
            if(!in_array($type,['template','text'])){
                return [
                    'error'=>true,
                    'message'=>'invalid type',
                    'data'=>[
                        'detailed_message' => "Type {$type} is not supported"
                    ]
                ];
            }
            $body = [
                "messaging_product"=> "whatsapp",
                "to"=> $phoneNumber,
                "type"=> $type,
            ];
            if($type === 'template'){
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
