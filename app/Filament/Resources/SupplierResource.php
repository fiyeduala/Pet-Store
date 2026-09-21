<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Supplier\Services\SupplierRegistry;
use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Connection')
                ->schema([
                    TextInput::make('name')->required(),

                    Select::make('mode')
                        ->options([
                            Supplier::MODE_DEMO => 'Demo — simulated data, no real orders',
                            Supplier::MODE_SANDBOX => 'Sandbox — supplier test environment',
                            Supplier::MODE_LIVE => 'Live — real orders and real money',
                        ])
                        ->required()
                        ->live()
                        ->helperText('Nothing switches this for you. A live failure will not quietly fall back to demo.'),

                    Toggle::make('is_enabled')->label('Enabled'),
                ])->columns(3),

            Section::make('Credentials')
                ->description('Stored encrypted. Never sent to the browser and never written to logs.')
                ->schema([
                    TextInput::make('credential_email')
                        ->label('Account email')
                        ->email()
                        ->dehydrated(false)
                        ->afterStateHydrated(fn ($component, ?Supplier $record) => $component->state($record?->credential('email'))),

                    TextInput::make('credential_api_key')
                        ->label('API key')
                        ->password()
                        ->revealable()
                        ->dehydrated(false)
                        ->placeholder(fn (?Supplier $record) => $record?->credential('api_key') ? '•••••••• (saved)' : 'Not set')
                        ->helperText('Paste the API key from your supplier dashboard. Leave blank to keep the saved one.'),
                ])->columns(2),

            Section::make('Verified capabilities')
                ->description('Nothing is assumed supported. Each of these is only true once it has been observed working against this account.')
                ->schema([
                    Placeholder::make('capabilities')
                        ->label('')
                        ->content(function (?Supplier $record): HtmlString {
                            if ($record === null) {
                                return new HtmlString('<p class="text-sm text-gray-500">Save the integration first.</p>');
                            }

                            $rows = collect(app(SupplierRegistry::class)->for($record)->capabilities())
                                ->map(fn (bool $ok, string $name) => sprintf(
                                    '<li class="flex items-center gap-2 text-sm"><span>%s</span><span>%s</span></li>',
                                    $ok ? '&#10003;' : '&#10007;',
                                    e(ucfirst(str_replace('_', ' ', $name)))
                                ))
                                ->implode('');

                            return new HtmlString("<ul class=\"space-y-1\">{$rows}</ul>");
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),

                TextColumn::make('mode')->badge()
                    ->color(fn (string $state) => match ($state) {
                        Supplier::MODE_LIVE => 'success',
                        Supplier::MODE_SANDBOX => 'warning',
                        default => 'gray',
                    }),

                IconColumn::make('is_enabled')->boolean()->label('Enabled'),

                TextColumn::make('connection_state')->label('Connection')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'ok' => 'success',
                        'auth_failed', 'error' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('last_authenticated_at')->label('Last authenticated')->dateTime('j M H:i')->placeholder('Never'),

                TextColumn::make('syncLogs_count')->counts('syncLogs')->label('Sync runs'),

                TextColumn::make('last_error')->label('Last error')->wrap()->limit(80)->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('test_connection')
                    ->label('Test connection')
                    ->icon('heroicon-o-signal')
                    ->authorize(fn () => auth()->user()->can('integrations.manage'))
                    ->requiresConfirmation()
                    ->modalHeading('Test the supplier connection')
                    ->modalDescription('Authenticates only. It does not place an order, spend money, or change any data.')
                    ->action(function (Supplier $record): void {
                        $result = app(SupplierRegistry::class)->for($record)->authenticate();

                        $record->forceFill([
                            'connection_state' => $result->ok ? 'ok' : 'auth_failed',
                            'last_checked_at' => now(),
                            'last_error' => $result->ok ? null : $result->message,
                        ])->save();

                        Notification::make()
                            ->title($result->ok ? 'Connection succeeded' : 'Connection failed')
                            ->body($result->message)
                            ->color($result->ok ? 'success' : 'danger')
                            ->persistent(! $result->ok)
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('integrations.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('integrations.manage') ?? false;
    }
}
