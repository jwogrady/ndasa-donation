<?php
declare(strict_types=1);

namespace NDASA\Tests\Admin;

use NDASA\Admin\AppConfig;
use NDASA\Tests\Support\DatabaseTestCase;

final class AppConfigTest extends DatabaseTestCase
{
    private AppConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = new AppConfig($this->db);
    }

    public function test_get_returns_default_when_key_missing(): void
    {
        $this->assertSame('fallback', $this->config->get('not_there', 'fallback'));
        $this->assertNull($this->config->get('not_there'));
    }

    public function test_set_then_get_roundtrips(): void
    {
        $this->config->set('my_key', 'hello');
        $this->assertSame('hello', $this->config->get('my_key'));
    }

    public function test_set_updates_existing_key_without_duplicating(): void
    {
        $this->config->set('flag', 'v1');
        $this->config->set('flag', 'v2');
        $this->assertSame('v2', $this->config->get('flag'));
        $this->assertSame(1, $this->countRows('app_config'));
    }

    public function test_stripeMode_defaults_to_live(): void
    {
        $this->assertSame(AppConfig::MODE_LIVE, $this->config->stripeMode());
        $this->assertFalse($this->config->isTestMode());
    }

    public function test_stripeMode_reads_test_setting(): void
    {
        $this->config->set(AppConfig::STRIPE_MODE, AppConfig::MODE_TEST);
        $this->assertSame(AppConfig::MODE_TEST, $this->config->stripeMode());
        $this->assertTrue($this->config->isTestMode());
    }

    public function test_stripeMode_normalizes_unexpected_value_to_live(): void
    {
        // Defence in depth: if someone hand-edits the DB to garbage, we
        // still report a valid mode string instead of propagating the bogus
        // value to credential resolution.
        $this->config->set(AppConfig::STRIPE_MODE, 'weird');
        $this->assertSame(AppConfig::MODE_LIVE, $this->config->stripeMode());
    }

    // ───────────── static credential resolution ─────────────

    public function test_resolveStripeCredentials_live_uses_live_pair_first(): void
    {
        $creds = AppConfig::resolveStripeCredentials(AppConfig::MODE_LIVE, [
            'STRIPE_LIVE_SECRET_KEY'     => 'sk_live_123',
            'STRIPE_LIVE_WEBHOOK_SECRET' => 'whsec_live',
            // Legacy pair present but should be ignored when LIVE pair is set.
            'STRIPE_SECRET_KEY'          => 'sk_legacy',
            'STRIPE_WEBHOOK_SECRET'      => 'whsec_legacy',
        ]);
        $this->assertSame(
            ['secret' => 'sk_live_123', 'webhook' => 'whsec_live'],
            $creds,
        );
    }

    public function test_resolveStripeCredentials_live_falls_back_to_legacy(): void
    {
        $creds = AppConfig::resolveStripeCredentials(AppConfig::MODE_LIVE, [
            'STRIPE_SECRET_KEY'     => 'sk_legacy',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_legacy',
        ]);
        $this->assertSame(
            ['secret' => 'sk_legacy', 'webhook' => 'whsec_legacy'],
            $creds,
        );
    }

    public function test_resolveStripeCredentials_test_requires_explicit_test_pair(): void
    {
        // Legacy live-style keys must NOT satisfy test-mode resolution —
        // that would silently send test traffic at live credentials on a
        // pre-mode-toggle install.
        $this->assertNull(AppConfig::resolveStripeCredentials(AppConfig::MODE_TEST, [
            'STRIPE_SECRET_KEY'     => 'sk_legacy',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_legacy',
        ]));

        $this->assertSame(
            ['secret' => 'sk_test_123', 'webhook' => 'whsec_test'],
            AppConfig::resolveStripeCredentials(AppConfig::MODE_TEST, [
                'STRIPE_TEST_SECRET_KEY'     => 'sk_test_123',
                'STRIPE_TEST_WEBHOOK_SECRET' => 'whsec_test',
            ]),
        );
    }

    public function test_resolveStripeCredentials_returns_null_when_half_missing(): void
    {
        $this->assertNull(AppConfig::resolveStripeCredentials(AppConfig::MODE_LIVE, [
            'STRIPE_LIVE_SECRET_KEY' => 'sk_only',
            // webhook secret deliberately absent
        ]));
        $this->assertNull(AppConfig::resolveStripeCredentials(AppConfig::MODE_TEST, [
            'STRIPE_TEST_WEBHOOK_SECRET' => 'whsec_only',
        ]));
    }

    public function test_resolveStripeCredentials_rejects_empty_strings(): void
    {
        $this->assertNull(AppConfig::resolveStripeCredentials(AppConfig::MODE_LIVE, [
            'STRIPE_LIVE_SECRET_KEY'     => '',
            'STRIPE_LIVE_WEBHOOK_SECRET' => '',
        ]));
    }
}
