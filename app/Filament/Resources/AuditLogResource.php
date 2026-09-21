<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Append-only audit history.
 *
 * Nothing here can be edited or deleted from the panel: an audit trail you
 * can quietly rewrite is not an audit trail. Secrets and unnecessary
 * personal data are redacted before a row is written, and IP addresses are
 * stored only as a hash.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Audit history';

    protected static ?int $navigationSort = 40;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('j M Y H:i:s')->sortable(),

                TextColumn::make('actor')
                    ->label('Who')
                    ->state(fn (AuditLog $record) => $record->user?->name
                        ?? $record->actor_label
                        ?? 'system')
                    ->description(fn (AuditLog $record) => $record->user?->email),

                TextColumn::make('action')->badge()->searchable()
                    ->color(fn (string $state) => match (true) {
                        str_contains($state, 'role') || str_contains($state, 'two_factor') => 'danger',
                        str_contains($state, 'created') => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('auditable_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '—')
                    ->description(fn (AuditLog $record) => $record->auditable_id ? "#{$record->auditable_id}" : null),

                TextColumn::make('changes')
                    ->label('Detail')
                    ->wrap()
                    ->state(fn (AuditLog $record) => $record->changes
                        ? json_encode($record->changes, JSON_UNESCAPED_SLASHES)
                        : '—')
                    ->limit(140),
            ])
            ->filters([
                SelectFilter::make('action')->options(fn () => AuditLog::query()
                    ->distinct()
                    ->orderBy('action')
                    ->pluck('action', 'action')
                    ->all()),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/')];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('audit.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
