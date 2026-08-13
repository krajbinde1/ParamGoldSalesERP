<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (HTTP v1)
    |--------------------------------------------------------------------------
    |
    | Place a service-account JSON at storage/app/firebase-service-account.json
    | (or set FIREBASE_CREDENTIALS to an absolute path) and set FIREBASE_PROJECT_ID.
    | When unset/missing, order push notifications are skipped safely (orders still work).
    |
    */
    'project_id' => env('FIREBASE_PROJECT_ID'),
    'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase-service-account.json')),
    'enabled' => env('FIREBASE_PUSH_ENABLED', true),
];
