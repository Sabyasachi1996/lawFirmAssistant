<?php
namespace App\Services;
use App\Actions\AICallAction;
use App\Actions\AICallErrorCheckAction;
use App\Actions\ContextRetrievalAction;
use App\Actions\HistoryRetrievalAction;
use App\Actions\PromptGenerationAction;
use App\Actions\SendWhatsappMessageAction;
use App\Actions\StoreConvoAndMessageAction;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
class WhatsappAutomationService{
    public function __construct(
        private StoreConvoAndMessageAction $storeConvoAndMessageAction,
        private HistoryRetrievalAction $historyRetrievalAction,
        private ContextRetrievalAction $contextRetrievalAction,
        private PromptGenerationAction $promptGenerationAction,
        private AICallAction $aiCallAction,
        private AICallErrorCheckAction $aiCallErrorCheckAction,
        private SendWhatsappMessageAction $sendWhatsappMessageAction
        ){}
    public function handleIncomingMessage(array $payloadData):bool{
        Log::info("incoming message handling started");
        return DB::transaction(function() use($payloadData){
            $now = now();
            $isAIPhaseSuccessfullyDone = false;
            $r1 = $this->storeConvoAndMessageAction->execute($payloadData);
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
                $historyString = $this->historyRetrievalAction->execute($concernedConvo);
                //generate semantic recall string for prompt
                $semanticRecallString = $this->contextRetrievalAction->execute($concernedConvo,$currentMessage);
                //create prompt for ai call
                $prompt = $this->promptGenerationAction->execute($currentMetadata,$historyString,$semanticRecallString,$currentMessage);
                //do the ai call
                $aiResponse = $this->aiCallAction->execute($prompt);
                if($this->aiCallErrorCheckAction->execute($aiResponse)['error']){
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
            $sendMessageResponse = $this->sendWhatsappMessageAction->execute($messageType,$reply,$concernedConvo->phone_number);
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
    public function handleIncomingStatus(array $payloadData):bool{
        try{
            $now = now();
            $messageId = data_get($payloadData,'entry.0.changes.0.value.statuses.0.id');
            $messageStatus = data_get($payloadData,'entry.0.changes.0.value.statuses.0.status');
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
    public function handleDefault($payloadData){}
}
