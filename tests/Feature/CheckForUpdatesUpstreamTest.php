<?php

use App\Jobs\CheckForUpdatesJob;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'new_version_available' => false]);
    Once::flush();
    Http::fake(['*' => Http::response(['coolify' => ['v4' => ['version' => '9.9.9']]])]);
    File::partialMock()->shouldReceive('put')->once();
    config(['constants.coolify.version' => '4.0.0']);
});

it('does not announce upstream releases while upstream updates are disabled', function () {
    config(['constants.coolify.upstream_updates' => false]);

    (new CheckForUpdatesJob)->handle();

    expect((bool) InstanceSettings::find(0)->new_version_available)->toBeFalse();
});

it('announces upstream releases when upstream updates are enabled', function () {
    config(['constants.coolify.upstream_updates' => true]);

    (new CheckForUpdatesJob)->handle();

    expect((bool) InstanceSettings::find(0)->new_version_available)->toBeTrue();
});
