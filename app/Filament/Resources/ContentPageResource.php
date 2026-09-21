<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ContentPageResource\Pages;
use App\Models\ContentPage;
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

class ContentPageResource extends Resource
{
    protected static ?string $model = ContentPage::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Pages';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(160)->live(onBlur: true)
                ->afterStateUpdated(fn ($state, $set, $get) => $get('slug') ? null : $set('slug', Str::slug((string) $state))),
            TextInput::make('slug')->required()->unique(ignoreRecord: true),
            Textarea::make('excerpt')->rows(2)->columnSpanFull(),
            Textarea::make('body')->rows(20)->columnSpanFull(),
            TextInput::make('seo_title')->maxLength(190),
            Textarea::make('seo_description')->rows(2)->maxLength(320),
            Toggle::make('is_published')->default(false),
            Toggle::make('is_policy')->label('This is a policy page'),
            Toggle::make('requires_owner_review')
                ->label('Still a draft awaiting review')
                ->helperText('While this is on, the page shows a visible "under review" notice to customers. Turn it off once you have checked and completed the wording.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
            TextColumn::make('title')->searchable(),
            TextColumn::make('slug')->color('gray'),
            IconColumn::make('is_published')->boolean()->label('Live'),
            IconColumn::make('requires_owner_review')->boolean()->label('Needs review')
                ->trueColor('warning')->falseColor('success'),
            TextColumn::make('updated_at')->dateTime('j M Y')->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContentPages::route('/'),
            'create' => Pages\CreateContentPage::route('/create'),
            'edit' => Pages\EditContentPage::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('content.manage') ?? false;
    }
}
