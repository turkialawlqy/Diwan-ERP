<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/confirm-password');

        $response->assertStatus(200);
    }

    public function test_confirm_password_screen_shows_why_it_was_asked_for_in_the_interface_language(): void
    {
        $user = User::factory()->create();

        // Entering a company as super admin without a recent password confirmation lands here with this note.
        $this->actingAs($user)->withSession(['locale' => 'en', 'status' => 'أكّد كلمة المرور ثم أعد طلب الدخول إلى المنشأة.'])->get('/confirm-password')
            ->assertOk()->assertInertia(fn ($page) => $page->where('status', 'Confirm your password, then request access to the company again.'));
    }

    public function test_password_can_be_confirmed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_password_is_not_confirmed_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();
    }
}
