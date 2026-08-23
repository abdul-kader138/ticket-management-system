<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TwoFactorAuthenticationService
    {
        return app(TwoFactorAuthenticationService::class);
    }

    public function test_generates_a_valid_base32_secret(): void
    {
        $secret = $this->service()->generateSecretKey();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertGreaterThanOrEqual(16, strlen($secret));
    }

    public function test_qr_code_is_an_inline_svg_data_uri(): void
    {
        $service = $this->service();
        $secret = $service->generateSecretKey();
        $user = User::factory()->make(['email' => 'test@example.com']);

        $qr = $service->qrCodeSvg($user, $secret);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $qr);
    }

    public function test_verify_accepts_the_current_valid_totp_code(): void
    {
        $service = $this->service();
        $secret = $service->generateSecretKey();
        $validCode = app(Google2FA::class)->getCurrentOtp($secret);

        $this->assertTrue($service->verify($secret, $validCode));
    }

    public function test_verify_rejects_an_incorrect_code(): void
    {
        $service = $this->service();
        $secret = $service->generateSecretKey();

        $this->assertFalse($service->verify($secret, '000000'));
    }

    public function test_verify_rejects_an_empty_code(): void
    {
        $service = $this->service();
        $secret = $service->generateSecretKey();

        $this->assertFalse($service->verify($secret, ''));
        $this->assertFalse($service->verify($secret, '   '));
    }

    public function test_generates_the_requested_number_of_unique_recovery_codes(): void
    {
        $codes = $this->service()->generateRecoveryCodes(8);

        $this->assertCount(8, $codes);
        $this->assertSame($codes, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{10}-[A-Za-z0-9]{10}$/', $code);
        }
    }

    public function test_verify_and_consume_recovery_code(): void
    {
        $service = $this->service();
        $user = User::factory()->create([
            'two_factor_recovery_codes' => ['AAAA-BBBB', 'CCCC-DDDD'],
        ]);

        $this->assertTrue($service->verifyRecoveryCode($user, 'AAAA-BBBB'));
        $this->assertFalse($service->verifyRecoveryCode($user, 'NOT-A-CODE'));

        $service->consumeRecoveryCode($user, 'AAAA-BBBB');
        $user->refresh();

        $this->assertSame(['CCCC-DDDD'], $user->two_factor_recovery_codes);
        $this->assertFalse($service->verifyRecoveryCode($user, 'AAAA-BBBB'));
    }
}
