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
            'import' => ['/admin/import-supplier-products'],
            'pet types' => ['/admin/pet-types'],
            'categories' => ['/admin/categories'],
            'collections' => ['/admin/collections'],
            'discounts' => ['/admin/discounts'],
            'content pages' => ['/admin/content-pages'],
            'faq' => ['/admin/faqs'],
            'enquiries' => ['/admin/contact-messages'],
            'customers' => ['/admin/customers'],
            'staff' => ['/admin/staff'],
            'audit history' => ['/admin/audit-logs'],
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

        // Staff management and the audit trail are owner-only.
        $this->actingAs($support)->get('/admin/staff')->assertForbidden();
        $this->actingAs($support)->get('/admin/audit-logs')->assertForbidden();
    }

    #[Test]
    public function an_operations_user_cannot_manage_staff_or_read_the_audit_trail(): void
    {
        $ops = $this->staff('operations');

        $this->actingAs($ops)->get('/admin/products')->assertSuccessful();
        $this->actingAs($ops)->get('/admin/staff')->assertForbidden();
        $this->actingAs($ops)->get('/admin/audit-logs')->assertForbidden();
        $this->actingAs($ops)->get('/admin/payment-gateways')->assertForbidden();
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
