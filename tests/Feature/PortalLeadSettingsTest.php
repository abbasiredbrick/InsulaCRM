<?php

namespace Tests\Feature;

use Tests\TestCase;

class PortalLeadSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    public function test_update_portal_lead_settings_saves_custom_options(): void
    {
        $this->put(route('settings.updatePortalLeadSettings'), [
            'unmatched'     => 'distribute',
            'notify_admins' => '1',
        ])->assertRedirect();

        $this->tenant->refresh();

        $this->assertSame('distribute', $this->tenant->portalLeadSettings()['unmatched']);
        $this->assertTrue($this->tenant->portalLeadSettings()['notify_admins']);
    }

    public function test_update_portal_lead_settings_defaults_to_unassigned_with_notifications(): void
    {
        $settings = $this->tenant->portalLeadSettings();

        $this->assertSame('unassigned', $settings['unmatched']);
        $this->assertTrue($settings['notify_admins']);
    }

    public function test_update_portal_lead_settings_rejects_invalid_strategy(): void
    {
        $this->put(route('settings.updatePortalLeadSettings'), [
            'unmatched'     => 'bogus',
            'notify_admins' => '0',
        ])->assertSessionHasErrors('unmatched');
    }
}