<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every admin screen must at least render for an owner, and must be
 * refused for a role that lacks the permission.
 */
class AdminPanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public static function ownerPages(): array
    {
        return [
            'dashboard' => ['/admin'],
            'products' => ['/admin/products'],
            'orders' => ['/admin/orders'],
            'integrations' => ['/admin/suppliers'],
            'packaging' => ['/admin/packaging-records'],
            'exceptions' => ['/admin/operational-exceptions'],
            'payment methods' => ['/admin/payment-gateways'],
            'brand settings' => ['/admin/brand-settings'],
            'fulfilment settings' => ['/admin/fulfilment-settings'],
            'system health' => ['/admin/system-health'],
        ];
    }

    #[Test]
    #[DataProvider('ownerPages')]
    public function an_owner_can_open_every_admin_page(string $path): void
    {
        $this->actingAs($this->staff('owner'))
            ->get($path)
            ->assertSuccessful();
    }

    #[Test]
    public function a_support_user_cannot_reach_money_or_staff_screens(): void
    {
        $support = $this->staff('support');

        // Support may read orders but must not manage gateways or settings.
        $this->actingAs($support)->get('/admin/orders')->assertSuccessful();
        $this->actingAs($support)->get('/admin/payment-gateways')->assertForbidden();
        $this->actingAs($support)->get('/admin/fulfilment-settings')->assertForbidden();
        $this->actingAs($support)->get('/admin/brand-settings')->assertForbidden();
    }

    #[Test]
    public function a_customer_account_cannot_reach_the_admin_panel_at_all(): void
    {
        $customer = User::factory()->create(['is_staff' => false]);

        $this->actingAs($customer)->get('/admin')->assertForbidden();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['is_staff' => true, 'email_verified_at' => now()]);
        $user->syncRoles([$role]);

        return $user;
    }
}
