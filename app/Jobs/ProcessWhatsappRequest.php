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
        Log::info('handle of queue started');
        $action = $this->incomingInputPayload['action'];
        Log::info("action is: $action");
        switch($action){
            case 'INCOMING_MESSAGE':
                Log::info("case selected: INCOMING_MESSAGE");
                $this->handleIncomingMessage($this->incomingInputPayload['data']);
                break;
            case 'INCOMING_STATUS':
                Log::info("case selected: INCOMING_STATUS");
                $this->handleIncomingStatus($this->incomingInputPayload['data']);
                break;
            default:
                Log::info("case selected: default");
                $this->handleDefault($this->incomingInputPayload['data']);
        };
    }
    private function handleIncomingMessage(array $payloadData):bool{
        try{
            Log::info("incoming message handling started");
            $isAIPhaseSuccessfullyDone = false;
            $r1 = $this->saveConvoAndMessage($payloadData);
            Log::info('saved convo and message',$r1);
            if($r1['error']){
               return false;//TODO: handle later
            }
            //get the wa_id of the conversation
            $waId = $payloadData['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id'];
            //get the concerned conversation
            $concernedConvo = Conversation::with('messages')
            ->where('wa_id',$waId)
            ->first();
            //if the last sent message(means the ongoing message here) sent more than 23 hours ago
            if(Carbon::createFromTimestamp($r1['message_timestamp'])->diffInHours(now()) > 23){
                Log::info("this ongoing message was sent more than 23 hours ago");
                $now = date('Y-m-d h:i:s');
                $reply = 'How may I help you today?';
                $sendMessageResponse = $this->sendWhatsappMessage('template',$reply,$concernedConvo->phone_number);
                Log::info("reply sent by system",$sendMessageResponse);
            }elseif($r1['action'] === 'RESPOND_THROUGH_AI'){
                Log::info("flow will be handled by AI");
                $now = date('Y-m-d h:i:s');
                //generate history string for prompt
                $historyString = $this->generateMessageHistoryString($concernedConvo);
                Log::info("history string generated: $historyString");
                //take current message for prompt TODO: handle the audio message(important)
                $currentMessage = $payloadData['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'];
                //generate semantic recall string for prompt
                $semanticRecallString = $this->generateSemanticRecallString($concernedConvo->id,$currentMessage);
                Log::info("semantic recall string generated: $semanticRecallString");
                //take current metadata of conversation for prompt
                $currentMetadata = $concernedConvo->metadata;
                //create prompt for ai call
                $prompt = $this->generatePrompt($currentMetadata,$historyString,$semanticRecallString,$currentMessage);
                Log::info("prompt generated: $prompt");
                //do the ai call
                $aiResponse = $this->doAICall($prompt);
                Log::info("AI call done: ",$aiResponse);
                $aiCallErrorCheck = $this->checkAICallErrors($aiResponse);
                Log::info("AI call error check response,",$aiResponse);
                if($aiCallErrorCheck['error']){
                    return false; //TODO: handle later
                }
                $aiResponseArray = json_decode($aiResponse['data']['choices'][0]['message']['content'],true);
                //gather important informations from the ai response
                $reply = $aiResponseArray['reply'];
                $metadata = $aiResponseArray['metadata'];
                $is_completed = $aiResponseArray['is_completed'];
                $metadataCount = count($metadata);
                $sendMessageResponse = $this->sendWhatsappMessage('text',$reply,$concernedConvo->phone_number);
                $isAIPhaseSuccessfullyDone = true;
                Log::info("reply sent by system",$sendMessageResponse);
            }elseif($r1['action'] === 'RESPOND'){
                Log::info("reply will be handled by only RESPOND by the system itself");
                $now = date('Y-m-d h:i:s');
                $reply = 'Our team is reviewing your request based on your given details. You will be contacted ASAP. Thank you.';
                $sendMessageResponse = $this->sendWhatsappMessage('text',$reply,$concernedConvo->phone_number);
                Log::info("reply sent by system",$sendMessageResponse);
            }else{
                Log::info("no case matched. aborting",);
                return false;//TODO: handle later
            }
            $messageDataset = [
                'whatsapp_message_id'=>$sendMessageResponse['data']['messages'][0]['id'],
                'direction'=>'outbound',
                'type'=>'text',
                'status'=>'sent',//$sendMessageResponse['data']['messages'][0]['message_status']),//TODO: status is not coming have to check why
                'message_timestamp'=>$now,
                'failed_reason'=>null,
                'body' => $reply,
                'created_at'=>$now,
                'updated_at'=>$now
            ];
            Log::info("reply message dataset created",$messageDataset);
            $concernedConvo->messages()->create($messageDataset);
            Log::info("reply message entry created");
            $convoUpdateDataset = [
                'last_message_at' => $now
            ];
            if($isAIPhaseSuccessfullyDone){
                $convoUpdateDataset['metadata'] = json_encode($metadata);
                $convoUpdateDataset['status'] = $is_completed && $metadataCount === (int) env('MAX_INFO_META_KEY_COUNT')?'qualified':'active'; /**TODO:later, make it dynamic using env variable */
                Log::info("message was handled by ai, so metadata and status update entry created for convo update");
            }
            $concernedConvo->update($convoUpdateDataset);
            Log::info("convo updated and flow completed");
            return true;
        }catch(\Exception $e){
           Log::info("unexpected error: ".$e->getMessage().$e->getLine());
           return false; //TODO: handle later
        }
    }
    private function generatePrompt(string|null $currentMeta,string $historyString,string $semantiRecallString, string $currentMessage){
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
                - If you already have all the metadata present, reply with a message saying thank you and the administration will contact you sortly.
                ### 7. CURRENT CONTEXT
                - **CURRENT_METADATA**: $currentMeta
                - **SEMANTIC_RECALL**: $semantiRecallString
                - **RECENT_HISTORY**: $historyString
                - **USER_CURRENT_MESSAGE: $currentMessage

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
        //last 3 entry fetch close to the current message context
        $semanticRecallData = Message::select('direction','body')
        ->where('conversation_id',$convoId)
        ->orderByRaw("embedding <-> ?",[$vectorLiteral])
        ->limit(3)
        ->get();
        //the semantic recall string creation for prompt
        $semanticRecallString = "";
        foreach($semanticRecallData as $eachMessage){
        $role = $eachMessage->direction === 'inbound'?'user':'assistant';
        $message = $eachMessage->body;
        $semanticRecallString.="- $role: $message\n";
        }
        return $semanticRecallString;
    }
    private function generateMessageHistoryString(Conversation $conversation):string{
        //get history of 10 messages of the conversation and take it in a certain format
        $history = $conversation->messages()
        ->latest()
        ->limit(10)
        ->get();
        $historyString = '';
        foreach($history as $eachMessageEntry){
            $role = $eachMessageEntry->direction === 'inbound'?'user':'assistant';
            $message = $eachMessageEntry->body;
            $historyString .= "- $role: $message\n";
        }
        return $historyString;
    }
    private function doAICall(string $prompt):array{
        $groqCall = new GroqCall();
        $aiResponse = $groqCall->createData($prompt);
        return $aiResponse;
    }
    private function checkAICallErrors(array $aiCallResponse){
        Log::info('Ai response messge is : '. $aiCallResponse['message']);
        //check if error comes from AI call
        if($aiCallResponse['error']){
            return [
                'error'=>true,
                'message'=>'AI call error'
            ];
        }
        //check if ai response actually came and it is a valid json
        if(!$aiCallResponse['data']['choices'][0]['message']['content'] || !$this->isValidJson($aiCallResponse['data']['choices'][0]['message']['content'])){
            return [
                'error' => true,
                'message' => 'no response from AI'
            ];
        }
        return [
            'error' => false,
        ];
    }
    //save convo and message and then decide what to do next
    private function saveConvoAndMessage(array $payloadData):array{
        Log::info('convo and message saving function started');
        DB::beginTransaction();
        try{
            //collect data to insert in conversations and messages table
            $now = date('Y-m-d h:i:s');
            $waId = $payloadData['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id'];
            $name  = $payloadData['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'];
            $phoneNumber = $payloadData['entry'][0]['changes'][0]['value']['messages'][0]['from'];
            $messageId = $payloadData['entry'][0]['changes'][0]['value']['messages'][0]['id'];
            $type = $payloadData['entry'][0]['changes'][0]['value']['messages'][0]['type'];
            $messageTimestamp = $payloadData['entry'][0]['changes'][0]['value']['messages'][0]['timestamp'];
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
            //create message dataset
            $messageDataset = [
                'whatsapp_message_id'=>$messageId,
                'direction'=>'inbound',
                'type'=>$type,
                'status'=>'received',
                'message_timestamp'=>Carbon::createFromTimestamp($messageTimestamp),
                'failed_reason'=>null,
                'created_at'=>$now,
                'updated_at'=>$now
            ];
            //separation of some data based on text and audio input message
            $vectorService = new VectorService();
            switch($type){
                case 'text':
                    $embeddingArray = $vectorService->getVector($payloadData['entry'][0]['changes'][0]['value']['messages'][0]['text']['body']);
                    $embeddingTextFormat = '['.implode(',',$embeddingArray).']';
                    $messageDataset['body'] = $payloadData['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'];
                    $messageDataset['embedding'] = $embeddingTextFormat;
                    break;
                case 'audio':
                    //TODO: this portion will change when actual audio transcribe will bring a text, right now , i am keeping a static text
                    $embeddingArray = $vectorService->getVector('demo transcribed text');
                    $embeddingTextFormat = '['.implode(',',$embeddingArray).']';
                    $messageDataset[]=[
                        'file_mime_type' => $payloadData['entry'][0]['changes'][0]['value']['messages'][0]['audio']['mime_type'],
                        'file_sha256' => $payloadData['entry'][0]['changes'][0]['value']['messages'][0]['audio']['sha256'],
                        'file_id' => $payloadData['entry'][0]['changes'][0]['value']['messages'][0]['audio']['id'],
                        'file_url' => $payloadData['entry'][0]['changes'][0]['value']['messages'][0]['audio']['url'],
                        'is_file_voice' => $payloadData['entry'][0]['changes'][0]['value']['messages'][0]['audio']['voice'],
                        'body' => 'demo transcribed text',
                        'embedding' => $embeddingTextFormat
                    ];
                    break;
            }
            //check if the message is the first message of the conversation
            $messagesBeforeCount = $convoData->messages()->count();
            $isInitial = $messagesBeforeCount > 0?false:true;
            //messages table entry
            $convoData->messages()->create($messageDataset);
            //last message time updated in conversations table
            $convoData->update([
                'last_message_at' => Carbon::createFromTimestamp($messageTimestamp)
            ]);
            //based on convo status, decide what to do next
            if($convoData->status === 'closed' ||($convoData->status === 'qualified' && $convoData->updated_at->diffInHours(now()) >24)){
                $convoData->update([
                    'metadata'=> null,
                    'status' => 'active'
                ]);
                $response = [
                    'error'=> false,
                    'action'=>'RESPOND_THROUGH_AI',
                    'metadata'=>$convoData->metadata,
                    'is_initial' => $isInitial,
                    'message_timestamp' => $messageTimestamp,
                    'input_message' => $payloadData['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] //TODO:later change the logic here to get the text format of the last message if the last message is an audio message too
                ];
            }
            if($convoData->status === 'active'){
                $response = [
                    'error'=> false,
                    'action'=>'RESPOND_THROUGH_AI',
                    'metadata'=>$convoData->metadata,
                    'is_initial' => $isInitial,
                    'message_timestamp' => $messageTimestamp,
                    'input_message' => $payloadData['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] //TODO:later change the logic here to get the text format of the last message if the last message is an audio message too
                ];
            }
            if($convoData->status === 'qualified' && $convoData->updated_at->diffInHours(now())<=24){
                $response = [
                    'error' => false,
                    'action'=>'RESPOND',
                    'is_initial'=> $isInitial,
                    'message_timestamp' => $messageTimestamp,
                    'metadata'=>$convoData->metadata,
                    'input_message' => $payloadData['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] //TODO:later change the logic here to get the text format of the last message if the last message is an audio message too
                ];
            }
            DB::commit();
            return $response;
        }catch(\Exception $e){
            Log::info('convo and message saving function ended with error'.$e->getMessage().$e->getLine());
            DB::rollBack();
            return [
                'error' => true,
                'action'=>null,
                'metadata'=>null
            ];
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
                        'detailed_message' => 'invalid type'
                    ]
                ];
            }
            if($type === 'template'){
                $body =[
                    "messaging_product"=> "whatsapp",
                    "to"=> $phoneNumber,
                    "type"=> $type,
                    "template"=> [
                        "name"=> 'hello_world',//$message,
                        "language"=> [
                            "code"=>"en_US"
                        ]
                    ]
                ];
            }else{
                $body = [
                    "messaging_product"=>"whatsapp",
                    "recipient_type"=> "individual",
                    "to"=> $phoneNumber,
                    "type"=> $type,
                    "text"=> [
                        "body"=>$message
                    ]
                ];
            }

            $response = Http::when(app()->environment('local'),function($client){
                return $client->withoutVerifying();
            })->withToken('EAAUfXCxOopQBQ7dZAaRAN0qOz3rTo6QwVLGijmZC9xXNZClBbIawLS98FSGeOh2Kjtpt1ONfQZB2Nid7TaXZAgIVQHVrUoaowA8fM4JaykZAbcPJwrssUZAMJu6kL7fAEI8KbTxmKN2XTZAnIE1XUiRT8Mp1mZAO2rwYBbAQoaLGiIvSCHcUvbi6RUSs1OP5kZAsdGwEZCfaDvZAlqSEd2NvyI8dUHT34NOaw1jnV9xzhsyavP9R37Gvdcrjw2OF1aqhXao1owp5YbQvrEFDZASmYnq6HuRkZD')
            ->timeout(10)
            ->retry(3,100,function($exception){
                return $exception instanceof ConnectionException;
            })
            ->post('https://graph.facebook.com/v22.0/973957632472068/messages',$body);
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
