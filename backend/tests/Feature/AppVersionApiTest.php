<?php

it('returns the public mobile app version payload', function () {
    config([
        'mobile_app.latest_version' => '1.0.1',
        'mobile_app.latest_build' => 3,
        'mobile_app.apk_url' => 'https://paramgold.in/apk/paramgold-latest.apk',
        'mobile_app.force_update' => true,
        'mobile_app.message' => 'A new version of ParamGold is available. Please update to continue.',
    ]);

    $response = $this->getJson('/api/app-version');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('latest_version', '1.0.1')
        ->assertJsonPath('latest_build', 3)
        ->assertJsonPath('apk_url', 'https://paramgold.in/apk/paramgold-latest.apk')
        ->assertJsonPath('force_update', true)
        ->assertJsonPath('message', 'A new version of ParamGold is available. Please update to continue.');
});

it('does not require authentication', function () {
    $this->getJson('/api/app-version')->assertOk();
});
