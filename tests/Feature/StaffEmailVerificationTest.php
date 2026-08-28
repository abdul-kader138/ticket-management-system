<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Auth\VerifyEmail;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StaffEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
    }

    private function staff(): User
    {
        $user = User::factory()->unverified()->create();
        $user->assignRole('panel_user');

        return $user;
    }

    public function test_staff_verification_email_links_to_the_auth_free_panel_route(): void
    {
        Notification::fake();

        $user = $this->staff();
        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_contains($url, '/admin/email/verify/'.$user->getKey().'/');
        });
    }

    public function test_a_signed_staff_link_verifies_without_a_session_and_signs_the_user_in(): void
    {
        $user = $this->staff();

        $url = URL::temporarySignedRoute('staff.verification.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->get($url)->assertRedirect();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_staff_link_with_a_mismatched_hash_is_rejected(): void
    {
        $user = $this->staff();

        $url = URL::temporarySignedRoute('staff.verification.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => 'not-the-right-hash',
        ]);

        $this->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_unsigned_staff_link_is_rejected(): void
    {
        $user = $this->staff();

        $this->get('/admin/email/verify/'.$user->getKey().'/'.sha1($user->getEmailForVerification()))
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_customer_still_gets_the_frontend_api_link(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create(); // no panel role
        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
            return str_contains($notification->toMail($user)->actionUrl, '/api/v1/auth/email/verify/');
        });
    }

    public function test_the_verification_notification_is_queued(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new VerifyEmail,
        );
    }
}
