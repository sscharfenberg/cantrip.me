<?php

namespace Tests\Feature;

use App\Http\Middleware\ContentSecurityPolicy;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The Content-Security-Policy header. The policy's contents are exercised for
 * real by the Playwright suite, which runs with it enforced and fails on any
 * violation; this pins when and how the header is sent.
 */
class ContentSecurityPolicyTest extends TestCase
{
    private const REPORT_ONLY = 'Content-Security-Policy-Report-Only';

    private const ENFORCED = 'Content-Security-Policy';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/csp-probe', fn () => response('<!DOCTYPE html><title>probe</title>'));
        Route::get('/csp-probe/throws', fn () => throw new RuntimeException('probe'));
    }

    #[Test]
    public function the_policy_is_report_only_until_it_is_enforced(): void
    {
        $response = $this->get('/csp-probe');

        $response->assertHeader(self::REPORT_ONLY, ContentSecurityPolicy::policy(Vite::cspNonce()));
        $response->assertHeaderMissing(self::ENFORCED);
    }

    #[Test]
    public function an_enforced_policy_uses_the_enforcing_header(): void
    {
        config(['cantrip.csp.enforce' => true]);

        $response = $this->get('/csp-probe');

        $response->assertHeader(self::ENFORCED, ContentSecurityPolicy::policy(Vite::cspNonce()));
        $response->assertHeaderMissing(self::REPORT_ONLY);
    }

    #[Test]
    public function every_request_gets_a_new_script_nonce(): void
    {
        $first = $this->get('/csp-probe')->headers->get(self::REPORT_ONLY);
        $second = $this->get('/csp-probe')->headers->get(self::REPORT_ONLY);

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{40}'/", $first);
        $this->assertNotSame($first, $second);
    }

    /**
     * A nonce or hash in a directive makes browsers ignore its 'unsafe-inline'.
     * The error pages and the 2FA code input need inline styles, so a nonce
     * in style-src would block them; inline scripts must never be allowed.
     */
    #[Test]
    public function only_scripts_carry_the_nonce_and_only_styles_may_be_inline(): void
    {
        $directives = collect(explode('; ', ContentSecurityPolicy::policy('n0nce')))
            ->mapWithKeys(fn (string $directive) => [strtok($directive, ' ') => $directive]);

        $this->assertSame("script-src 'self' 'nonce-n0nce'", $directives['script-src']);
        $this->assertSame("style-src 'self' 'unsafe-inline'", $directives['style-src']);
        $this->assertSame("default-src 'self'", $directives['default-src']);
    }

    /**
     * A 404 never reaches the web middleware group, and in debug mode it still
     * renders the error view, not the debug page.
     */
    #[Test]
    public function an_error_page_carries_the_policy_in_debug_mode_too(): void
    {
        foreach ([false, true] as $debug) {
            config(['app.debug' => $debug]);

            $this->get('/no-such-page')
                ->assertNotFound()
                ->assertHeader(self::REPORT_ONLY);
        }
    }

    #[Test]
    public function the_exception_page_carries_the_policy_unless_it_is_the_debug_page(): void
    {
        config(['app.debug' => false]);
        $this->get('/csp-probe/throws')->assertServerError()->assertHeader(self::REPORT_ONLY);

        config(['app.debug' => true]);
        $this->get('/csp-probe/throws')->assertServerError()->assertHeaderMissing(self::REPORT_ONLY);
    }

    /**
     * With Vite's dev server running, scripts and the HMR websocket come from
     * its origin; the policy would block the page it is meant to protect.
     */
    #[Test]
    public function no_policy_while_the_vite_dev_server_runs(): void
    {
        $hotFile = tempnam(sys_get_temp_dir(), 'vite-hot');
        file_put_contents($hotFile, 'http://localhost:5173');

        try {
            $this->withVite();
            Vite::useHotFile($hotFile);

            $this->get('/csp-probe')
                ->assertHeaderMissing(self::REPORT_ONLY)
                ->assertHeaderMissing(self::ENFORCED);
        } finally {
            unlink($hotFile);
        }
    }
}
