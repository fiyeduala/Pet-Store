<?php

declare(strict_types=1);

namespace App\Filament\Resources\StaffResource\Pages;

use App\Filament\Resources\StaffResource;
use App\Models\AuditLog;
use Filament\Resources\Pages\CreateRecord;

class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['is_staff'] = true;

        return $data;
    }

    protected function afterCreate(): void
    {
        // `email_verified_at` is deliberately not mass-assignable, so that a
        // public registration form can never mark itself verified. A staff
        // account is created by an administrator who already knows the
        // address belongs to a colleague, so it is set explicitly here.
        if ($this->record->email_verified_at === null) {
            $this->record->forceFill(['email_verified_at' => now()])->save();
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'actor_label' => auth()->user()?->email,
            'action' => 'staff.created',
            'auditable_type' => $this->record::class,
            'auditable_id' => $this->record->getKey(),
            'changes' => ['email' => $this->record->email, 'roles' => $this->record->getRoleNames()->all()],
            'ip_hash' => hash('sha256', (string) request()->ip()),
            'created_at' => now(),
        ]);
    }
}
