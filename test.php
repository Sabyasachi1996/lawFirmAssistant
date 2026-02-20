<?php
$sendMessageResponse = [
    "messaging_product"=> "whatsapp",
    "contacts"=> [
        [
            "input"=> "917980085798",
            "wa_id"=> "917980085798"
        ]
    ],
    "messages"=> [
        [
            "id"=> "wamid.HBgMOTE3OTgwMDg1Nzk4FQIAERgSRDkwRDMzN0EzOEMxOTIxRjk2AA==",
            "message_status"=> "accepted"
        ]
    ]
];
$receiveMessagePayload = [
  'object' => 'whatsapp_business_account',
  'entry' =>[
        0 =>[
            'id' => '897985563409470',
            'changes' =>[
                0 =>[
                'value' =>[
                    'messaging_product' => 'whatsapp',
                    'metadata' =>[
                        'display_phone_number' => '15551436253',
                        'phone_number_id' => '973957632472068',
                    ],
                    'contacts' =>[
                        0 =>[
                            'profile' =>[
                                'name' => 'tripathy.dev',
                            ],
                            'wa_id' => '917980085798',
                        ],
                    ],
                    'messages' =>[
                        0 =>[
                            'from' => '917980085798',
                            'id' => 'wamid.HBgMOTE3OTgwMDg1Nzk4FQIAEhggQUMxMjkzRjI0NzRFNTgxNEMyNDdBMTA2RTI1RjhGODYA',
                            'timestamp' => '1771162444',
                            'text' =>[
                                'body' => 'Hey there',
                            ],
                            'type' => 'text',
                        ],
                    ],
                ],
                'field' => 'messages',
                ],
            ],
        ],
    ],
];

$audio= array (
  'object' => 'whatsapp_business_account',
  'entry' =>
  array (
    0 =>
    array (
      'id' => '897985563409470',
      'changes' =>
      array (
        0 =>
        array (
          'value' =>
          array (
            'messaging_product' => 'whatsapp',
            'metadata' =>
            array (
              'display_phone_number' => '15551436253',
              'phone_number_id' => '973957632472068',
            ),
            'contacts' =>
            array (
              0 =>
              array (
                'profile' =>
                array (
                  'name' => 'tripathy.dev',
                ),
                'wa_id' => '917980085798',
              ),
            ),
            'messages' =>
            array (
              0 =>
              array (
                'from' => '917980085798',
                'id' => 'wamid.HBgMOTE3OTgwMDg1Nzk4FQIAEhggQUNBMUJDN0Q1NzdGRjY0RjI1NkIwNUMxNEUyRENDQkQA',
                'timestamp' => '1771162482',
                'type' => 'audio',
                'audio' =>
                array (
                  'mime_type' => 'audio/ogg; codecs=opus',
                  'sha256' => 'Zcc2GSdwb6Fs09fJnBgAo1RO3hzCCaxAkGuux5fsSdY=',
                  'id' => '1809285416425655',
                  'url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1809285416425655&source=webhook&ext=1771162783&hash=ARkplZHkNdrPISQR1Kns_g0KnnVupcDRJqWnVp61CR-JsQ',
                  'voice' => true,
                ),
              ),
            ),
          ),
          'field' => 'messages',
        ),
      ),
    ),
  ),
);
