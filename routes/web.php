<?php

use App\Services\GroqCall;
use App\Services\VectorService;
use Cloudstudio\Ollama\Facades\Ollama;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Jobs\ProcessWhatsappRequest;
Route::get('/meta-to-app/webhook', function (Request $request) {
    $hubChallenge = $request->query('hub_challenge');
    $hubVerifyToken = $request->query('hub_verify_token');
    if($hubVerifyToken !== 'criminalcaselawyerappbackendwebhooktoken'){
        abort(403);
    }
    return response($hubChallenge,200);
});
Route::post('/meta-to-app/webhook',function(Request $request){
    $input = $request->all();
    if(count($input) > 0){
       $identifier = $input['entry'][0]['changes'][0]['value'];
       $action = null;
       if(array_key_exists('contacts',$identifier)){
            $action = 'INCOMING_MESSAGE';
       }elseif(array_key_exists('statuses',$identifier)){
            $action = 'INCOMING_STATUS';
       }
       if($action){
         $whatsappRequestProcessingPayload = [
            'action' => $action,
            'data' => $request->all()
         ];
         ProcessWhatsappRequest::dispatch($whatsappRequestProcessingPayload);
       }
    }
    return response('EVENT_RECEIVED', 200);
});
Route::post('/app-to-meta/send-wa-message',function(Request $request){
    $request->validate([
        'name' =>'required'
    ]);
    dd($request);
    return response('fine',200);
});
Route::get('/chats',function(){
    return view('welcome',['title'=>'chats']);
});
Route::get('/demo',function(Request $request, VectorService $vectorService){
    $memoryString = <<<EOD
    1.The billing amount is 500 Ruppees.
    2.You can pay the billing amount online only.
    3.Billing amount should be paid before 2nd March,2026.
    EOD;
    $currentMeta = json_encode([
        "name" => "Ram"
    ]);
    $systemPrompt = $systemPrompt = <<<EOD
    ### ROLE
    You are a JSON Data Formatting Engine. You parse natural language into structured objects.

    ### TASK
    1. Extract values from the latest input.
    2. Maintain the status of the 'metadata' object.
    3. If information is already present in 'EXISTING_DATA', preserve it. Do NOT use "Unknown".

    ### DATA FIELDS
    - client_id (name)
    - interval (age)
    - digital_contact (email)
    - phone (contact_number)
    - activity (occupation)
    - site (address)
    - log_description (case_details)

    ### CONTEXT
    - EXISTING_DATA: $currentMeta
    - ARCHIVE_RECALL: $memoryString

    ### OUTPUT RULE
    Return ONLY valid JSON.
    {
    "reply": "Request missing fields here.",
    "metadata": { ... },
    "is_completed": boolean
    }
    }
    EOD;
    $history = [
        ['role'=>'user','content'=>'so can we finalize the procedure of meeting with the lawyer?'],
        ['role'=>'assistant','content'=>'The lawyer will contact you once you provide the full details, so please proceed with your details first.']
    ];
    $r = Ollama::model('llama3.2')
    ->options([
            'stream'=>false,
            'temperature' => 0, // 0 makes it "strict" and less likely to hallucinate "Unknown"
            'num_ctx' => 1024
        ])
    ->chat([
        [
            'role'=>'system','content'=>$systemPrompt
        ],
        ...$history,
        ['role' => 'user', 'content' => "IMPORTANT: The current known data is $currentMeta. Now, process my next message."],
        ['role'=>'user','content'=>'I am 42 years of age, Live in Tamluk, Purba Medinipur']
    ]);
    return response($r,200);
    // return response($vectorService->getVector('demo text'),200);
});
Route::get('/demo-groq',function(Request $request){
    // $prompt = 'give a motivational line. only the line, no extra anything. return the response in json format like {"line":"the motivational line"}';
    $currentMeta = json_encode([
        // 'name'=>'Troy',
        // 'age'=>20,
        'email'=>'troy@gmail.com',
        'contact_number'=>'1234567890',
        // 'occupation'=>'businessman',
        // 'address'=>'LA',
        // 'case_details'=>'shoot out, victim was shot 5 times'
    ]);
    $memoryString = <<<EOD
    - user: the case is about a shootout, where the victim was shot 5 times.
    EOD;
    $history = <<<EOD
    - user: as you asked, my name is Troy and I am 20.
    - assistant: kindly provide your address.
    - user: I live in LA
    - assistant: nice. now tell me about occupation and contact details.
    - user: i am a businessman. wait till i give you details about the contact.
    - assistant: Kindly provide the other details.
    EOD;
    $currentMessage = 'Before I provide you further details, can you tell me what I told so far about the case? just for a confirmation.';
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
        ### 7. CURRENT CONTEXT
        - **CURRENT_METADATA**: $currentMeta
        - **SEMANTIC_RECALL**: $memoryString
        - **RECENT_HISTORY**: $history
        - **USER_CURRENT_MESSAGE: $currentMessage

        ### 8. MANDATORY OUTPUT FORMAT
        Return ONLY a valid JSON object. No pre-amble, no conversational text outside the JSON.
        {
        "reply": "Your friendly message to the client",
        "metadata": { "name": "...", "age": "...", ... },
        "is_completed": boolean
        }
    EOD;
    $groqInstance = new GroqCall();
    $groqResponse = $groqInstance->createData($prompt);
    dd($groqResponse);
});
Route::get('/send',function(){
    $response = sendWhatsappMessage('text','how may i help you today?');
    return response()->json(
        $response,
        array_key_exists('statusCode',$response)?$response['statusCode']:500);
});
function sendWhatsappMessage(string $type,string $message):array{
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
                "to"=> "917980085798",
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
                "to"=> "917980085798",
                "type"=> $type,
                "text"=> [
                    "body"=>$message
                ]
            ];
        }

        $response = Http::when(app()->environment('local'),function($client){
            return $client->withoutVerifying();
        })->withToken('EAAUfXCxOopQBQ6akW5HFVvLZCWUCkaX0vOVoGJuQU8KyKtKWppefIVZBchJXBjdTafiiJoieSdM0iHIAZA4kO7l4EQuEbp1qGuiZB3HTmhj7CbvAsh8iAFX2cioZCjIIuNKlEWMdaukoaZCgZBCDdnm5Tk7l7ohwYGtJiLEPk4HaaDDdXYnM0IGgpMiWCpZA0WRLm8zUoFWS82lAHqKP7B8VZAdl38OwoQ0TE5bPHwm7ATSfWxfkMYrQ1Frt2wGyZARdKySIWfmuea6iOiNSmZCJkCv')
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
