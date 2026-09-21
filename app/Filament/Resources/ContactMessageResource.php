<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ContactMessageResource\Pages;
use App\Models\ContactMessage;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ContactMessageResource extends Resource
{
    protected static ?string $model = ContactMessage::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox';

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Enquiries';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->disabled(),
            TextInput::make('email')->disabled(),
            TextInput::make('order_number')->disabled(),
            TextInput::make('subject')->disabled(),
            Textarea::make('message')->rows(8)->disabled()->columnSpanFull(),
            Select::make('status')->options(['new' => 'New', 'open' => 'Open', 'resolved' => 'Resolved'])->required(),
            Textarea::make('admin_note')->rows(3)->columnSpanFull()->label('Internal note'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
            TextColumn::make('created_at')->dateTime('j M H:i')->sortable()->label('Received'),
            TextColumn::make('name')->searchable(),
            TextColumn::make('subject')->searchable()->wrap()->limit(60),
            TextColumn::make('order_number')->placeholder('—'),
            TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                'new' => 'warning', 'resolved' => 'success', default => 'info',
            }),
            ])
            ->headerActions([
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContactMessages::route('/'),
            'edit' => Pages\EditContactMessage::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('support.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
