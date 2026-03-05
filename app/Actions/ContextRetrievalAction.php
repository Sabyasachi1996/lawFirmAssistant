<?php
namespace App\Actions;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\VectorService;
class ContextRetrievalAction {
    public function __construct(private VectorService $vectorService){}
    public function execute(Conversation $conversation,string $currentMessage):string{

        $currentMessageVector = $this->vectorService->getVector($currentMessage);
        $vectorLiteral = '['.implode(',',$currentMessageVector).']';
        //get the ID of those messages which are already present in the history string
        $excludeIds = Message::where('conversation_id',$conversation->id)
        ->whereNotNull('body')
        ->latest()
        ->limit(10)
        ->pluck('id');
        //last 3 entry fetch close to the current message context
        $semanticRecallData = Message::select('direction','body')
        ->where('conversation_id',$conversation->id)
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

}
