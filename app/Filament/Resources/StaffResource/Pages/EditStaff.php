<?php

declare(strict_types=1);

namespace App\Filament\Resources\StaffResource\Pages;

use App\Filament\Resources\StaffResource;
use App\Models\AuditLog;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    /** @var array<int, string> */
    private array $rolesBefore = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset_two_factor')
                ->label('Reset two-factor')
                ->icon('heroicon-o-shield-exclamation')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Clears this person\'s authenticator enrolment so they can set it up again. Only do this when you have confirmed who you are talking to.')
                ->visible(fn () => filled($this->record->getAppAuthenticationSecret()))
                ->action(function (): void {
                    $this->record->saveAppAuthenticationSecret(null);
                    $this->record->saveAppAuthenticationRecoveryCodes(null);

                    AuditLog::create([
                        'user_id' => auth()->id(),
                        'actor_label' => auth()->user()?->email,
                        'action' => 'staff.two_factor_reset',
                        'auditable_type' => $this->record::class,
                        'auditable_id' => $this->record->getKey(),
                        'ip_hash' => hash('sha256', (string) request()->ip()),
                        'created_at' => now(),
                    ]);

                    Notification::make()->success()->title('Two-factor enrolment cleared')->send();
                }),

            DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        $this->rolesBefore = $this->record->getRoleNames()->all();
    }

    /**
     * Role changes are the highest-privilege edit in the panel, so they are
     * always recorded, with who made them.
     */
    protected function afterSave(): void
    {
        $after = $this->record->fresh()->getRoleNames()->all();

        if ($after === $this->rolesBefore) {
            return;
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'actor_label' => auth()->user()?->email,
            'action' => 'staff.role_changed',
            'auditable_type' => $this->record::class,
            'auditable_id' => $this->record->getKey(),
            'changes' => ['from' => $this->rolesBefore, 'to' => $after],
            'ip_hash' => hash('sha256', (string) request()->ip()),
            'created_at' => now(),
        ]);
    }
}
