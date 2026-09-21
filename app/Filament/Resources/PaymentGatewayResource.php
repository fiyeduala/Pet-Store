<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentGatewayResource\Pages;
use App\Models\PaymentGateway;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class PaymentGatewayResource extends Resource
{
    protected static ?string $model = PaymentGateway::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|\UnitEnum|null $navigationGroup = 'Money';

    protected static ?string $navigationLabel = 'Payment methods';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('secrets_notice')
                ->label('')
                ->content(new HtmlString(
                    '<div class="rounded-lg border border-gray-300 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">'
                    .'<p><strong>API keys are not edited here.</strong> They live in the server\'s <code>.env</code> file so '
                    .'they never pass through a browser. This page controls whether a method is offered, in which mode, '
                    .'and which currencies you have confirmed it can accept.</p></div>'
                ))
                ->columnSpanFull(),

            Section::make('Availability')->schema([
                TextInput::make('name')->required(),

                Select::make('mode')->options([
                    PaymentGateway::MODE_DEMO => 'Demo — simulated, no money moves',
                    PaymentGateway::MODE_SANDBOX => 'Sandbox — provider test environment',
                    PaymentGateway::MODE_LIVE => 'Live — real money',
                ])->required()->live(),

                Toggle::make('is_enabled')->label('Offer at checkout'),

                TextInput::make('position')->numeric()->default(50)->label('Sort order'),
            ])->columns(4),

            Section::make('Merchant eligibility')
                ->description('Having working credentials is not the same as being allowed to take these payments. Only tick verified once the provider has confirmed it in writing.')
                ->schema([
                    Placeholder::make('configured')
                        ->label('Credentials present on the server')
                        ->content(fn (?PaymentGateway $r) => $r?->is_configured ? 'Yes' : 'No — set them in .env'),

                    Toggle::make('is_verified')
                        ->label('Merchant account verified with the provider')
                        ->helperText('Confirmed that this account may accept commercial payments in the currencies below. Sandbox success does not count.'),

                    TextInput::make('verified_by')->label('Verified by')->maxLength(120),
                    DateTimePicker::make('verified_at')->label('Verified on')->seconds(false),

                    Select::make('supported_currencies')
                        ->label('Currencies this account may charge')
                        ->multiple()
                        ->options(['USD' => 'USD', 'NGN' => 'NGN', 'GBP' => 'GBP', 'EUR' => 'EUR', 'CAD' => 'CAD'])
                        ->helperText('Only add a currency the provider has confirmed. A method that cannot take the order currency is simply not offered — the store never silently charges a different one.'),

                    Textarea::make('verification_notes')->rows(3)->columnSpanFull()
                        ->label('What was confirmed, and by whom'),

                    Textarea::make('capability_notes')->rows(4)->columnSpanFull()
                        ->label('Outstanding questions for the provider')
                        ->disabled(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),

                TextColumn::make('mode')->badge()
                    ->color(fn (string $state) => match ($state) {
                        PaymentGateway::MODE_LIVE => 'success',
                        PaymentGateway::MODE_SANDBOX => 'warning',
                        default => 'gray',
                    }),

                IconColumn::make('is_enabled')->boolean()->label('Offered'),
                IconColumn::make('is_configured')->boolean()->label('Credentials'),
                IconColumn::make('is_verified')->boolean()->label('Verified'),

                TextColumn::make('supported_currencies')
                    ->label('Currencies')
                    ->formatStateUsing(fn ($state) => $state ? implode(', ', (array) $state) : 'None set')
                    ->color(fn ($state) => $state ? null : 'danger'),

                TextColumn::make('status')
                    ->label('Can take a USD order?')
                    ->state(fn (PaymentGateway $r) => $r->blockingReason('USD') ?? 'Yes')
                    ->color(fn (PaymentGateway $r) => $r->blockingReason('USD') === null ? 'success' : 'warning')
                    ->wrap(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('position');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentGateways::route('/'),
            'edit' => Pages\EditPaymentGateway::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('gateways.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
