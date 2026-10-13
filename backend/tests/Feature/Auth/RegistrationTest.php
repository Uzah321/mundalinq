<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Tendai Moyo',
            'phone' => '077 123 4567',
            'email' => 'Tendai@Example.com',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'role' => 'farmer',
            'device_name' => 'Pixel 8',
        ], $overrides);
    }

    public function test_farmer_can_register_from_the_mobile_app(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'phone', 'roles']])
            ->assertJsonPath('user.phone', '+263771234567')
            ->assertJsonPath('user.email', 'tendai@example.com')
            ->assertJsonPath('user.phone_verified', false)
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.roles', ['farmer']);

        $this->assertNotNull($this->sms->lastCodeFor('+263771234567'));
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$response->json('token')])
            ->assertOk()
            ->assertJsonPath('data.name', 'Tendai Moyo');
    }

    public function test_email_is_optional(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['email' => '', 'role' => 'buyer']))
            ->assertCreated()
            ->assertJsonPath('user.email', null);
    }

    public function test_staff_roles_cannot_be_self_assigned(): void
    {
        foreach (['admin', 'super_admin', 'support_agent', 'delivery_partner'] as $role) {
            $this->postJson('/api/v1/auth/register', $this->payload(['role' => $role]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('role');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_phone_number_must_be_unique_in_any_format(): void
    {
        User::factory()->create(['phone' => '+263771234567']);

        $this->postJson('/api/v1/auth/register', $this->payload(['phone' => '+263 77 123 4567']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_invalid_phone_number_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['phone' => '12']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_api_returns_json_even_without_accept_header(): void
    {
        $this->post('/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors']);
    }
}
