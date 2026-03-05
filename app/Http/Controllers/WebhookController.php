<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsappWebhookCall;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function verifyMetaWebhookCall(Request $request){
        try{
            $hubChallenge = $request->query('hub_challenge');
            $hubVerifyToken = $request->query('hub_verify_token');
            if($hubVerifyToken !== 'criminalcaselawyerappbackendwebhooktoken'){
                abort(403);
            }
            return response($hubChallenge,200);
        }catch(\Exception $e){
            return response('FAILED',500);
        }
    }
    public function receiveMetaWebhookCall(Request $request){
        try{
            $input = $request->all();
            $identifier = data_get($input,'entry.0.changes.0.value');
            if(!$identifier){
                return response('unknown response',200);
            }
            $action = null;
            if(isset($identifier['contacts'])){
                $action = 'INCOMING_MESSAGE';
            }elseif(isset($identifier['statuses'])){
                $action = 'INCOMING_STATUS';
            }
            if($action){
                $whatsappRequestProcessingPayload = [
                    'action' => $action,
                    'data' => $input
                ];
                ProcessWhatsappWebhookCall::dispatch($whatsappRequestProcessingPayload);
            }
            return response('EVENT_RECEIVED', 200);
        }catch(\Exception $e){
            Log::error('Error receiving meta webhook: '.$e->getMessage(),['trace'=>$e->getTraceAsString()]);
            return response('FAILED',500);
        }
    }
}
