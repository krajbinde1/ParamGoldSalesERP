<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile APK version (mandatory in-app update)
    |--------------------------------------------------------------------------
    |
    | These values are the fallback for GET /api/app-version until Admin
    | saves App Update Settings. After a row exists in mobile_app_settings,
    | the API uses the database and these env keys are ignored.
    |
    | latest_build is the source of truth for update checks. Increment it
    | for every APK you upload to the permanent download URL.
    |
    | After each release:
    | 1. Bump Flutter pubspec.yaml version AND +build number
    | 2. Build app-release.apk
    | 3. Replace https://paramgold.in/apk/paramgold-latest.apk
    | 4. Admin Web → App Update Settings → save version + build
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
