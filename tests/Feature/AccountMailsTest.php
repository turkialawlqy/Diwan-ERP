<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Account emails are in Arabic with the product's look, and a user who
 * cannot receive the verification email can be verified from the server.
 */
class AccountMailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_and_reset_emails_are_arabic_and_branded(): void
    {
        $user = User::factory()->create(['name' => 'تركي', 'email_verified_at' => null]);

        $verify = (new VerifyEmail)->toMail($user);
        $this->assertSame('تأكيد بريدك الإلكتروني — '.config('app.name'), $verify->subject);
        $this->assertSame('تأكيد البريد الإلكتروني', $verify->actionText);
        $html = (string) $verify->render();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('مرحباً تركي', $html);
        $this->assertStringContainsString('جميع الحقوق محفوظة', $html);
        $this->assertStringNotContainsString('laravel.com/img', $html);

        $reset = (new ResetPassword('token-123'))->toMail($user);
        $this->assertSame('إعادة تعيين كلمة المرور — '.config('app.name'), $reset->subject);
        $this->assertStringContainsString('token-123', $reset->actionUrl);
    }

    public function test_a_user_can_be_verified_from_the_server(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com', 'email_verified_at' => null]);

        $this->artisan('erp:verify-user', ['email' => 'OWNER@example.com'])->assertSuccessful();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->artisan('erp:verify-user', ['email' => 'nobody@example.com'])->assertFailed();
    }
}
