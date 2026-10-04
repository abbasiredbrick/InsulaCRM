<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProcessingOverlayTest extends TestCase
{
    public function test_the_main_layout_ships_the_processing_overlay(): void
    {
        $this->actingAsAdmin();

        $response = $this->get(route('dashboard'));

        $response->assertOk();

        // The script that builds the overlay, plus the config it reads.
        $response->assertSee('js/processing-overlay.js', false);
        $response->assertSee('__keystoneProcessingLogo', false);
        $response->assertSee('__keystoneProcessingLabel', false);
    }

    public function test_the_overlay_stylesheet_hooks_the_progress_cursor(): void
    {
        $this->actingAsAdmin();

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('keystone-processing-overlay', false);
        $response->assertSee('data-processing-active', false);
        $response->assertSee('cursor: progress', false);
    }

    public function test_the_overlay_assets_exist(): void
    {
        $this->assertFileExists(public_path('js/processing-overlay.js'));
        $this->assertFileExists(public_path('images/logo.png'));
    }
}
