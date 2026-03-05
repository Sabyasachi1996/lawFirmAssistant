<?php
namespace App\Actions;
use App\Models\Conversation;
class HistoryRetrievalAction{
    public function execute(Conversation $conversation):string{
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
}
