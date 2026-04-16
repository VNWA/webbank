<?php

return [
    'api_key' => env('BANK_LOOKUP_API_KEY', ''),
    'secret_key' => env('BANK_LOOKUP_API_SECRET_KEY', ''),
    'base_url' => env('BANK_LOOKUP_BASE_URL', 'https://api.banklookup.net'),
    'account_path' => env('BANK_LOOKUP_ACCOUNT_PATH', '/api/account-name'),
    'qr_path' => env('BANK_LOOKUP_QR_PATH', '/api/qr-image'),
    'bank_list_path' => '/bank/list',
    'timeout' => 20,
];
