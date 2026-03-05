<?php

namespace App\Jobs;

use App\Services\WhatsappAutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class ProcessWhatsappWebhookCall implements ShouldQueue
{
    use Queueable;
    public $tries = 3;
    public $timeout = 90;
    public $maxExceptions = 2;
    /**
     * Create a new job instance.
     */
    public function __construct(
        private array $payload
    ){}

    public function backoff():array{
        return [5,20];
    }
    public function middleware():array{
        $waId = data_get($this->payload,'data.entry.0.changes.0.value.contacts.0.wa_id');
        return [
            (new WithoutOverlapping($waId))->releaseAfter(10)
        ];
    }
    /**
     * Execute the job.
     */
    public function handle(WhatsappAutomationService $whatsappAutomationService): void
    {
        Log::withContext([
            'job_id' => $this->job->getJobId(),
            'action' => $this->payload['action'] ?? 'unknown'
        ]);
        Log::info('whatsapp message processing has started in queue');
        try{
            $action = $this->payload['action'];
            $data = $this->payload['data'];
            if(!$action || !$data){
                Log::warning('Webhook handling initiated without any action or data payload');
                return;
            }
            match($action){
                'INCOMING_MESSAGE'=>$whatsappAutomationService->handleIncomingMessage($data),
                'INCOMING_STATUS'=>$whatsappAutomationService->handleIncomingStatus($data),
                default=>$whatsappAutomationService->handleDefault($data)
            };
        }catch(\Throwable $e){
            Log::error('webhook handling error: '.$e->getMessage(),['trace'=>$e->getTraceAsString()]);
            throw $e;
        }
    }
}
