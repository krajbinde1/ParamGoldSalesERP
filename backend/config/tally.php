<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tally connector (ERP outbox API)
    |--------------------------------------------------------------------------
    |
    | Laravel never talks to Tally Prime. A local Windows agent polls these
    | settings via the connector API and posts XML to localhost:9000 itself.
    |
    */

    'connector' => [
        'claim_ttl_seconds' => (int) env('TALLY_CONNECTOR_CLAIM_TTL', 120),
        'pending_limit_default' => 10,
        'pending_limit_max' => 50,
    ],

    'live_balance' => [
        'offline_after_seconds' => (int) env('TALLY_LIVE_OFFLINE_AFTER', 120),
    ],

];
