<?php
namespace App\Actions;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\VectorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
class StoreConvoAndMessageAction {
    public function __construct(private VectorService $vectorService){}
    public function execute(array $payloadData){
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
            $embeddingArray = $this->vectorService->getVector($messageBody);
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
}
