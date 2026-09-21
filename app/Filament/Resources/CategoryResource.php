<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
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

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $navigationLabel = 'Categories';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120)->live(onBlur: true)
                ->afterStateUpdated(fn ($state, $set, $get) => $get('slug') ? null : $set('slug', Str::slug((string) $state))),
            TextInput::make('slug')->required()->unique(ignoreRecord: true),
            Select::make('parent_id')->relationship('parent', 'name')->searchable()->preload()->label('Parent category'),
            Select::make('petTypes')->relationship('petTypes', 'name')->multiple()->preload()->label('Pet types'),
            Textarea::make('description')->rows(3)->columnSpanFull(),
            FileUpload::make('image_path')->image()->disk('public')->directory('categories')
                ->acceptedFileTypes(['image/jpeg','image/png','image/webp'])->maxSize(4096),
            TextInput::make('seo_title')->maxLength(190),
            Textarea::make('seo_description')->rows(2)->maxLength(320),
            TextInput::make('position')->numeric()->default(0),
            Toggle::make('is_active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
            TextColumn::make('name')->searchable()
                ->description(fn ($record) => $record->parent?->name ? 'in '.$record->parent->name : null),
            TextColumn::make('products_count')->counts('products')->label('Products'),
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

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // The list shows each category's parent, so load it with the page.
        return parent::getEloquentQuery()->with('parent');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategorys::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('catalogue.manage') ?? false;
    }
}
