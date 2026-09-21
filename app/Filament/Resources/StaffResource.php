<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\StaffResource\Pages;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Password;

/**
 * Staff accounts and their roles.
 *
 * Owner-only: whoever can grant a role can grant themselves the ability to
 * spend money, so this is the most privileged screen in the panel.
 */
class StaffResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Staff';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_staff', true)->with('roles');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')->schema([
                TextInput::make('name')->required()->maxLength(120),

                TextInput::make('email')->email()->required()->maxLength(190)
                    ->unique(ignoreRecord: true),

                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->rule(Password::min(12)->mixedCase()->numbers()->symbols())
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->required(fn (string $operation) => $operation === 'create')
                    ->helperText('At least 12 characters with mixed case, a number and a symbol. Leave blank when editing to keep the current one.'),

                Select::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->required()
                    ->maxItems(1)
                    ->helperText('One role per person.'),

                Toggle::make('is_staff')->default(true)->label('Can sign in to the admin panel'),
            ])->columns(2),

            Section::make('What each role can do')->collapsed()->schema([
                Placeholder::make('roles_explainer')->label('')->content(new HtmlString(
                    '<div class="space-y-2 text-sm">'
                    .'<p><strong>Owner</strong> — everything, including authorising supplier payments, '
                    .'approving refunds, changing payment gateway settings and managing staff.</p>'
                    .'<p><strong>Operations</strong> — runs the shop day to day: catalogue, pricing, orders, '
                    .'approvals and supplier submission. <em>Cannot</em> authorise a supplier charge, approve a '
                    .'refund, change gateway settings or manage staff.</p>'
                    .'<p><strong>Support</strong> — can see what is needed to answer a customer and can raise a '
                    .'refund request, but cannot approve one and cannot approve orders.</p>'
                    .'<p class="text-gray-500">Give the least privilege that lets someone do their job. '
                    .'Anyone who can move money should also have two-factor authentication enabled.</p></div>'
                )),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),

                TextColumn::make('roles.name')->label('Role')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'owner' => 'danger',
                        'operations' => 'warning',
                        default => 'gray',
                    }),

                IconColumn::make('two_factor')
                    ->label('2FA')
                    ->boolean()
                    ->state(fn (User $record) => filled($record->getAppAuthenticationSecret()))
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->tooltip('Anyone who can move money should have this on.'),

                IconColumn::make('is_staff')->boolean()->label('Active'),

                TextColumn::make('last_login_at')->label('Last signed in')
                    ->dateTime('j M Y H:i')->placeholder('Never')->sortable(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaff::route('/'),
            'create' => Pages\CreateStaff::route('/create'),
            'edit' => Pages\EditStaff::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('staff.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('staff.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('staff.manage') ?? false;
    }

    public static function canDelete($record): bool
    {
        // Nobody can delete themselves, so an owner cannot lock the shop out
        // of its own admin panel by accident.
        return (auth()->user()?->can('staff.manage') ?? false)
            && $record->getKey() !== auth()->id();
    }
}
