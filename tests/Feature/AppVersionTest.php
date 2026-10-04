<?php

namespace Tests\Feature;

use App\Support\AppVersion;
use Tests\TestCase;

class AppVersionTest extends TestCase
{
    protected function tearDown(): void
    {
        AppVersion::flush();

        parent::tearDown();
    }

    public function test_it_reads_the_version_file(): void
    {
        AppVersion::flush();

        $this->assertSame(trim((string) file_get_contents(base_path('VERSION'))), AppVersion::current());
    }

    public function test_the_version_file_is_the_source_of_truth(): void
    {
        AppVersion::flush();

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', AppVersion::current());
    }

    /**
     * The whole point of the resolver: a cached config must not freeze the
     * number. Simulate config:cache having baked an older version in, and the
     * file having moved on since.
     */
    public function test_a_baked_config_value_does_not_override_the_version_file(): void
    {
        config(['app.version' => '0.0.1-stale']);
        AppVersion::flush();

        $this->assertNotSame('0.0.1-stale', AppVersion::current());
        $this->assertSame(trim((string) file_get_contents(base_path('VERSION'))), AppVersion::current());
    }

    public function test_explicit_env_pin_wins_over_the_file(): void
    {
        putenv('APP_VERSION=9.9.9-pinned');
        $_ENV['APP_VERSION'] = '9.9.9-pinned';
        $_SERVER['APP_VERSION'] = '9.9.9-pinned';
        AppVersion::flush();

        try {
            $this->assertSame('9.9.9-pinned', AppVersion::current());
        } finally {
            putenv('APP_VERSION');
            unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
        }
    }

    public function test_the_result_is_memoized_and_flushable(): void
    {
        AppVersion::flush();

        $first = AppVersion::current();
        $this->assertSame($first, AppVersion::current());

        AppVersion::flush();
        $this->assertSame($first, AppVersion::current());
    }

    /**
     * The service worker prefetches `?v=<APP_ASSET_VERSION>` URLs, so a drift
     * between it and VERSION leaves the app shell primed with files the layout
     * never requests — stale CSS/JS served cache-first after every deploy.
     */
    public function test_service_worker_asset_version_matches_the_version_file(): void
    {
        $sw = (string) file_get_contents(base_path('public/service-worker.js'));

        $this->assertSame(
            1,
            preg_match("/var APP_ASSET_VERSION = '([^']+)';/", $sw, $m),
            'public/service-worker.js must declare APP_ASSET_VERSION'
        );

        $this->assertSame(
            AppVersion::current(),
            $m[1],
            'service-worker.js APP_ASSET_VERSION is out of step with VERSION'
        );
    }

    public function test_asset_version_tracks_the_current_version(): void
    {
        $this->assertSame(AppVersion::current(), AppVersion::assetVersion());
    }

    public function test_about_modal_reports_the_resolved_version(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/dashboard');

        $response->assertOk();
        $response->assertSee('v'.AppVersion::current(), false);
    }

    /**
     * The layout stamps every asset URL with ?v=<version>. If that came from a
     * frozen config, clients would keep the previous deploy's CSS/JS.
     */
    public function test_layout_asset_urls_carry_the_resolved_version(): void
    {
        config(['app.version' => '0.0.1-stale']);
        AppVersion::flush();

        $this->actingAsAdmin();
        $response = $this->get('/dashboard');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('mobile-app.js?v='.AppVersion::current(), $html);
        $this->assertStringContainsString('live-filter.js?v='.AppVersion::current(), $html);
        $this->assertStringNotContainsString('?v=0.0.1-stale', $html);
    }
}
