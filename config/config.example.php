<?php
return [
    'database' => [
        'host' => 'localhost',
        'dbname' => 'your_db_name',
        'user' => 'your_db_user',
        'pass' => 'your_db_pass',
    ],
    'discord' => [
        'client_id' => 'your_client_id',
        'client_secret' => 'your_client_secret',
        'redirect_uri' => 'your_redirect_uri',
        'bot_token' => 'your_bot_token',
    ],
    'upload' => [
        'max_file_size' => 10485760, // 10MB
        'allowed_types' => ['text/plain']
    ]
];