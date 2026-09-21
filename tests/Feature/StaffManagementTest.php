<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\StaffResource\Pages\CreateStaff;
use App\Filament\Resources\StaffResource\Pages\EditStaff;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    #[Test]
    public function an_owner_can_create_a_staff_account(): void
    {
        Livewire::actingAs($this->owner())
            ->test(CreateStaff::class)
            ->fillForm([
                'name' => 'New Colleague',
                'email' => 'colleague@example.test',
                'password' => 'A-Strong-Passw0rd!',
                'roles' => [\Spatie\Permission\Models\Role::where('name', 'support')->first()->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'colleague@example.test')->firstOrFail();

        $this->assertTrue($created->is_staff);
        $this->assertTrue($created->hasRole('support'));
        // The password was hashed, never stored in the clear.
        $this->assertNotSame('A-Strong-Passw0rd!', $created->password);
    }

    #[Test]
    public function a_weak_password_is_rejected(): void
    {
        Livewire::actingAs($this->owner())
            ->test(CreateStaff::class)
            ->fillForm([
                'name' => 'New Colleague',
                'email' => 'weak@example.test',
                'password' => 'password',
                'roles' => [\Spatie\Permission\Models\Role::where('name', 'support')->first()->getKey()],
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'weak@example.test']);
    }

    #[Test]
    public function creating_a_staff_account_is_recorded_in_the_audit_trail(): void
    {
        Livewire::actingAs($this->owner())
            ->test(CreateStaff::class)
            ->fillForm([
                'name' => 'Audited Colleague',
                'email' => 'audited@example.test',
                'password' => 'A-Strong-Passw0rd!',
                'roles' => [\Spatie\Permission\Models\Role::where('name', 'operations')->first()->getKey()],
            ])
            ->call('create');

        $log = AuditLog::where('action', 'staff.created')->firstOrFail();

        $this->assertSame('audited@example.test', $log->changes['email']);
        $this->assertContains('operations', $log->changes['roles']);
        // The raw IP is never stored, only a hash.
        $this->assertNotNull($log->ip_hash);
        $this->assertSame(64, strlen((string) $log->ip_hash));
    }

    #[Test]
    public function a_role_change_is_recorded_with_what_it_changed_from_and_to(): void
    {
        $colleague = User::factory()->create(['is_staff' => true]);
        $colleague->syncRoles(['support']);

        Livewire::actingAs($this->owner())
            ->test(EditStaff::class, ['record' => $colleague->getKey()])
            ->fillForm(['roles' => [\Spatie\Permission\Models\Role::where('name', 'owner')->first()->getKey()]])
            ->call('save');

        $log = AuditLog::where('action', 'staff.role_changed')->firstOrFail();

        $this->assertSame(['support'], $log->changes['from']);
        $this->assertSame(['owner'], $log->changes['to']);
    }

    #[Test]
    public function an_owner_cannot_delete_their_own_account(): void
    {
        $owner = $this->owner();

        // Guards against locking the business out of its own admin panel.
        $this->assertFalse(\App\Filament\Resources\StaffResource::canDelete($owner));

        $other = User::factory()->create(['is_staff' => true]);
        $other->syncRoles(['support']);

        $this->actingAs($owner);
        $this->assertTrue(\App\Filament\Resources\StaffResource::canDelete($other));
    }

    #[Test]
    public function the_audit_trail_cannot_be_edited_or_deleted(): void
    {
        $log = AuditLog::create([
            'action' => 'test.event',
            'created_at' => now(),
        ]);

        $this->assertFalse(\App\Filament\Resources\AuditLogResource::canCreate());
        $this->assertFalse(\App\Filament\Resources\AuditLogResource::canEdit($log));
        $this->assertFalse(\App\Filament\Resources\AuditLogResource::canDelete($log));
    }

    #[Test]
    public function customers_are_read_only_and_exclude_staff(): void
    {
        $customer = User::factory()->create(['is_staff' => false]);
        $this->owner();

        $this->assertFalse(\App\Filament\Resources\CustomerResource::canCreate());
        $this->assertFalse(\App\Filament\Resources\CustomerResource::canEdit($customer));

        $listed = \App\Filament\Resources\CustomerResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($listed->contains($customer->id));
        $this->assertSame(1, $listed->count(), 'Staff accounts must not appear in the customer list.');
    }

    private function owner(): User
    {
        $user = User::factory()->create(['is_staff' => true]);
        $user->syncRoles(['owner']);
        $this->actingAs($user);

        return $user;
    }
}
