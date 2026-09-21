<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and least-privilege permissions.
 *
 * The permissions that can move money or change who has access are granted
 * to the owner role only.
 */
class RoleSeeder extends Seeder
{
    public const PERMISSIONS = [
        // Catalogue
        'catalogue.view', 'catalogue.manage', 'catalogue.publish', 'catalogue.import',
        // Pricing
        'pricing.view', 'pricing.manage',
        // Orders
        'orders.view', 'orders.manage', 'orders.approve', 'orders.cancel',
        // Fulfilment
        'fulfilment.view', 'fulfilment.submit',
        // Money — restricted
        'supplier_payments.authorise',
        'refunds.request', 'refunds.approve',
        'gateways.manage',
        // Returns and support
        'returns.view', 'returns.manage', 'support.view', 'support.manage',
        // Customers
        'customers.view', 'customers.manage',
        // Content and branding
        'content.view', 'content.manage', 'branding.manage',
        // Configuration
        'markets.manage', 'shipping.manage', 'tax.manage', 'packaging.manage', 'discounts.manage',
        // System — restricted
        'integrations.view', 'integrations.manage',
        'staff.manage', 'audit.view', 'reports.view', 'system.manage',
    ];

    public const ROLE_PERMISSIONS = [
        // Owner gets everything, including money and staff management.
        User::ROLE_OWNER => ['*'],

        // Operations runs the shop day to day but cannot authorise a
        // supplier charge, change gateway credentials, or manage staff.
        User::ROLE_OPERATIONS => [
            'catalogue.view', 'catalogue.manage', 'catalogue.publish', 'catalogue.import',
            'pricing.view', 'pricing.manage',
            'orders.view', 'orders.manage', 'orders.approve', 'orders.cancel',
            'fulfilment.view', 'fulfilment.submit',
            'refunds.request',
            'returns.view', 'returns.manage', 'support.view', 'support.manage',
            'customers.view',
            'content.view', 'content.manage',
            'shipping.manage', 'packaging.manage', 'discounts.manage',
            'integrations.view', 'reports.view',
        ],

        // Support can see what it needs to answer a customer and can raise
        // a refund request, but cannot approve one.
        User::ROLE_SUPPORT => [
            'catalogue.view',
            'orders.view',
            'fulfilment.view',
            'refunds.request',
            'returns.view', 'returns.manage', 'support.view', 'support.manage',
            'customers.view',
            'content.view',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            $role->syncPermissions(
                $permissions === ['*'] ? self::PERMISSIONS : $permissions
            );
        }
    }
}
