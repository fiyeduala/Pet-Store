<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PetTypeResource\Pages;
use App\Models\PetType;
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

class PetTypeResource extends Resource
{
    protected static ?string $model = PetType::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $navigationLabel = 'Pet types';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80)->live(onBlur: true)
                ->afterStateUpdated(fn ($state, $set, $get) => $get('slug') ? null : $set('slug', Str::slug((string) $state))),
            TextInput::make('slug')->required()->unique(ignoreRecord: true),
            TextInput::make('tagline')->maxLength(140),
            Textarea::make('description')->rows(3)->columnSpanFull(),
            FileUpload::make('image_path')->image()->disk('public')->directory('pet-types')
                ->acceptedFileTypes(['image/jpeg','image/png','image/webp'])->maxSize(4096),
            TextInput::make('position')->numeric()->default(0),
            Toggle::make('is_active')->default(true),
            Toggle::make('is_primary')->label('Primary category')
                ->helperText('Primary types lead the navigation. Dogs and cats are primary by default.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('slug')->color('gray'),
            TextColumn::make('products_count')->counts('products')->label('Products'),
            IconColumn::make('is_primary')->boolean()->label('Primary'),
            IconColumn::make('is_active')->boolean(),
            TextColumn::make('position')->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->reorderable('position')
            ->defaultSort('position');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPetTypes::route('/'),
            'create' => Pages\CreatePetType::route('/create'),
            'edit' => Pages\EditPetType::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('catalogue.manage') ?? false;
    }
}
