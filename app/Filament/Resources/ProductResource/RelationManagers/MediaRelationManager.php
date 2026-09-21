<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Media;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Images';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('path')
                ->label('Image')
                ->image()
                ->disk('public')
                ->directory('products')
                ->visibility('public')
                // Validated on upload: type, size and dimensions.
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                ->maxSize(5120)
                ->imageEditor()
                ->required()
                ->helperText('JPEG, PNG, WebP or AVIF. Up to 5 MB.'),

            TextInput::make('alt')
                ->label('Alt text')
                ->maxLength(190)
                ->helperText('Describe the image for screen readers and for when it fails to load.'),

            TextInput::make('position')->numeric()->default(0),

            Toggle::make('is_curated')
                ->label('Curated')
                ->default(true)
                ->helperText('Curated images are yours and survive a supplier sync.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('alt')
            ->reorderable('position')
            ->defaultSort('position')
            ->columns([
                ImageColumn::make('path')->disk('public')->label('Image')->height(60),
                TextColumn::make('alt')->label('Alt text')->wrap()->placeholder('Not set'),
                IconColumn::make('is_curated')->boolean()->label('Curated'),
                TextColumn::make('position')->sortable(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
