<?php

return [
    'api_key' => env('BANK_LOOKUP_API_KEY', ''),
    'secret_key' => env('BANK_LOOKUP_API_SECRET_KEY', ''),
    'base_url' => 'https://api.banklookup.net',
    'account_path' => '',
    'qr_path' => '/qr-image',
    'bank_list_path' => '/bank/list',
    'timeout' => 20,
];
