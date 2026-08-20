<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile APK version (mandatory in-app update)
    |--------------------------------------------------------------------------
    |
    | latest_build is the source of truth for update checks. Increment it
    | for every APK you upload to the permanent download URL.
    |
    | After each release:
    | 1. Bump Flutter pubspec.yaml version AND +build number
    | 2. Build app-release.apk
    | 3. Replace https://paramgold.in/apk/paramgold-latest.apk
    | 4. Set MOBILE_APP_LATEST_VERSION and MOBILE_APP_LATEST_BUILD to match
    |
    | latest_build must match (or stay below) the uploaded APK's build, or
    | installed apps will loop on the update screen.
    |
    */

    'latest_version' => env('MOBILE_APP_LATEST_VERSION', '1.0.0'),

    'latest_build' => (int) env('MOBILE_APP_LATEST_BUILD', 2),

    'apk_url' => env(
        'MOBILE_APP_APK_URL',
        'https://paramgold.in/apk/paramgold-latest.apk',
    ),

    'force_update' => filter_var(env('MOBILE_APP_FORCE_UPDATE', true), FILTER_VALIDATE_BOOLEAN),

    'message' => env(
        'MOBILE_APP_UPDATE_MESSAGE',
        'A new version of ParamGold is available. Please update to continue.',
    ),

];
