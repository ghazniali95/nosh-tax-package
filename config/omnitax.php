<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default authority
    |--------------------------------------------------------------------------
    | Which tax authority a business reports to when it hasn't specified one.
    | fbr | pra | srb | kpra | bra
    */
    'default' => env('FISCAL_AUTHORITY', 'fbr'),

    /*
    |--------------------------------------------------------------------------
    | Sandbox switch
    |--------------------------------------------------------------------------
    | Global default. Per-tenant credentials can override this (see §13).
    | true  = talk to the authority's sandbox (test) endpoints
    | false = production
    */
    'sandbox' => env('FISCAL_SANDBOX', true),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    | 'http' = real HTTPS calls to the authority.
    | 'mock' = an in-package fake authority returning spec-shaped responses,
    |          so the whole flow is testable with NO token. Great for local dev.
    */
    'transport' => env('FISCAL_TRANSPORT', 'http'),

    /*
    |--------------------------------------------------------------------------
    | Seller identity (single-business installs)
    |--------------------------------------------------------------------------
    | Used when credentials.driver = 'env'. In multi-tenant mode these come
    | per-tenant from the fiscal_credentials table instead.
    */
    'seller' => [
        'ntncnic'  => env('FISCAL_SELLER_NTNCNIC'),
        'name'     => env('FISCAL_SELLER_NAME'),
        'province' => env('FISCAL_SELLER_PROVINCE'),
        'address'  => env('FISCAL_SELLER_ADDRESS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials resolution
    |--------------------------------------------------------------------------
    | 'env'      → use FISCAL_TOKEN + seller.* above (one business)
    | 'database' → look up per tenant from the fiscal_credentials table
    | 'callback' → resolve via a closure you register (OmniTax::resolveCredentialsUsing)
    */
    'credentials' => [
        'driver'  => env('FISCAL_CREDENTIALS_DRIVER', 'env'),
        'encrypt' => true, // tokens are encrypted at rest in the database driver
    ],

    /*
    |--------------------------------------------------------------------------
    | Reliability & queue
    |--------------------------------------------------------------------------
    */
    'timeout'        => (int) env('FISCAL_TIMEOUT', 30),
    'retry_attempts' => (int) env('FISCAL_RETRY_ATTEMPTS', 3),
    'retry_backoff'  => [10, 30, 120], // seconds between async retries
    'queue'          => env('FISCAL_QUEUE', 'fiscal-invoices'),

    /*
    |--------------------------------------------------------------------------
    | QR code
    |--------------------------------------------------------------------------
    */
    'qr_code' => [
        'version'    => '2.0',
        'size'       => 25,           // modules (25x25 for FBR v2.0)
        'dimensions' => '1.0x1.0in',
        'render'     => true,         // render PNG/SVG if a QR renderer is installed
    ],

    /*
    |--------------------------------------------------------------------------
    | Reference data cache
    |--------------------------------------------------------------------------
    */
    'reference' => [
        'cache_days' => (int) env('FISCAL_REFERENCE_CACHE_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => (bool) env('FISCAL_LOGGING_ENABLED', true),
        'channel' => env('FISCAL_LOG_CHANNEL', 'daily'),
        'level'   => env('FISCAL_LOG_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorities
    |--------------------------------------------------------------------------
    | One block per authority. Endpoints are pre-filled from each authority's
    | official spec; you normally only supply the token (env or database).
    |
    | FBR: same host for sandbox & production — the "_sb" URLs are sandbox,
    | routing is ultimately decided by the token you send.
    */
    'authorities' => [

        'fbr' => [
            'label'  => 'FBR (Federal / PRAL)',
            'driver' => \Nosh\OmniTax\Drivers\FbrDriver::class,
            'token'  => env('FISCAL_TOKEN'),
            'urls'   => [
                'submit'      => 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata',
                'submit_sb'   => 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb',
                'validate'    => 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata',
                'validate_sb' => 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb',
                'reference'   => 'https://gw.fbr.gov.pk/pdi/v1/',
                'statl'       => 'https://gw.fbr.gov.pk/dist/v1/',
            ],
        ],

        // Provincial authorities share the FbrDriver contract but will get
        // their own driver + endpoints as each one's spec is onboarded.
        'pra' => [
            'label'  => 'Punjab Revenue Authority',
            'driver' => null, // 🚧 rolling out
            'token'  => env('FISCAL_PRA_TOKEN'),
            'urls'   => [],
        ],
        'srb' => [
            'label'  => 'Sindh Revenue Board',
            'driver' => null, // 🚧 rolling out
            'token'  => env('FISCAL_SRB_TOKEN'),
            'urls'   => [],
        ],
        'kpra' => [
            'label'  => 'KP Revenue Authority',
            'driver' => null, // 🚧 rolling out
            'token'  => env('FISCAL_KPRA_TOKEN'),
            'urls'   => [],
        ],
        'bra' => [
            'label'  => 'Balochistan Revenue Authority',
            'driver' => null, // 🗓️ planned
            'token'  => env('FISCAL_BRA_TOKEN'),
            'urls'   => [],
        ],
    ],
];
