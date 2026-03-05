<?php
return [
    'MAX_INFO_META_KEY_COUNT' => (int) env('MAX_INFO_META_KEY_COUNT',7),

    'GROQ_API_KEY'=> env('GROQ_API_KEY',''),
    'GROQ_URL'=> env('GROQ_URL',''),
    'GROQ_CALL_TIMEOUT'=> (int) env('GROQ_CALL_TIMEOUT',30),
    'GROQ_MODEL'=> env('GROQ_MODEL','llama-3.3-70b-versatile'),

    'APP_NAME'=> env('APP_NAME','Laravel'),
    'APP_ENV'=> env('APP_ENV','local'),
    'APP_KEY'=>env('APP_KEY','base64:aZ95qaWvTjB9l5V3Ra/x4MhQ1JFkiZTGLNr2hv8kyKo='),
    'APP_DEBUG'=>(bool) env('APP_DEBUG','true'),
    'APP_URL'=>env('APP_URL','http://localhost'),

    'WHATSAPP_HELLO_TEMPLATE' => env('WHATSAPP_HELLO_TEMPLATE','hello_world'),
    'WHATSAPP_API_VERSION'=>env('WHATSAPP_API_VERSION','v22.0'),
    'WHATSAPP_PHONE_NUMBER_ID'=>env('WHATSAPP_PHONE_NUMBER_ID','973957632472068'),
    'WHATSAPP_ACCESS_TOKEN'=> env('WHATSAPP_ACCESS_TOKEN','EAAUfXCxOopQBQ7dZAaRAN0qOz3rTo6QwVLGijmZC9xXNZClBbIawLS98FSGeOh2Kjtpt1ONfQZB2Nid7TaXZAgIVQHVrUoaowA8fM4JaykZAbcPJwrssUZAMJu6kL7fAEI8KbTxmKN2XTZAnIE1XUiRT8Mp1mZAO2rwYBbAQoaLGiIvSCHcUvbi6RUSs1OP5kZAsdGwEZCfaDvZAlqSEd2NvyI8dUHT34NOaw1jnV9xzhsyavP9R37Gvdcrjw2OF1aqhXao1owp5YbQvrEFDZASmYnq6HuRkZD'),

    'MAILGUN_DOMAIN'=>env('MAILGUN_DOMAIN',''),
    'MAILGUN_SECRET'=>env('MAILGUN_SECRET',''),
    'MAILGUN_ENDPOINT'=>env('MAILGUN_ENDPOINT','api.mailgun.net'),
    'MAILGUN_SCHEME'=>env('MAILGUN_SCHEME','https')
];
