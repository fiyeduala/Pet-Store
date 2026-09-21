<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OperationalExceptionResource\Pages;
use App\Models\OperationalException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The exception queue: everything that needs a human decision.
 */
class OperationalExceptionResource extends Resource
{
    protected static ?string $model = OperationalException::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|\UnitEnum|null $navigationGroup = 'Orders';

    protected static ?string $navigationLabel = 'Exception queue';

    protected static ?int $navigationSort = 20;

    public static function getNavigationBadge(): ?string
    {
        $count = OperationalException::open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return OperationalException::open()->where('severity', 'critical')->exists() ? 'danger' : 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Raised')->dateTime('j M H:i')->sortable(),

                TextColumn::make('severity')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'critical' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => str_replace('_', ' ', $state)),

                TextColumn::make('title')->wrap()
                    ->description(fn (OperationalException $r) => $r->detail),

                TextColumn::make('order.number')->label('Order')
                    ->url(fn (OperationalException $r) => $r->order
                        ? OrderResource::getUrl('view', ['record' => $r->order])
                        : null)
                    ->placeholder('—'),

                TextColumn::make('suggested_action')->label('Next step')->wrap()->limit(120),

                TextColumn::make('state')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'resolved' => 'success',
                        'dismissed' => 'gray',
                        'acknowledged' => 'info',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('state')->options([
                    OperationalException::STATE_OPEN => 'Open',
                    OperationalException::STATE_ACKNOWLEDGED => 'Acknowledged',
                    OperationalException::STATE_RESOLVED => 'Resolved',
                    OperationalException::STATE_DISMISSED => 'Dismissed',
                ])->default(OperationalException::STATE_OPEN),

                SelectFilter::make('severity')->options([
                    'critical' => 'Critical',
                    'warning' => 'Warning',
                    'info' => 'Info',
                ]),
            ])
            ->recordActions([
                Action::make('acknowledge')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (OperationalException $r) => $r->state === OperationalException::STATE_OPEN)
                    ->action(fn (OperationalException $r) => $r->forceFill([
                        'state' => OperationalException::STATE_ACKNOWLEDGED,
                    ])->save()),

                Action::make('resolve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (OperationalException $r) => in_array($r->state, ['open', 'acknowledged'], true))
                    ->schema([Textarea::make('resolution_note')->label('What did you do?')->required()->rows(2)])
                    ->action(function (OperationalException $r, array $data): void {
                        $r->forceFill([
                            'state' => OperationalException::STATE_RESOLVED,
                            'resolution_note' => $data['resolution_note'],
                            'resolved_by' => auth()->id(),
                            'resolved_at' => now(),
                        ])->save();

                        Notification::make()->success()->title('Marked resolved')->send();
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->poll('30s');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOperationalExceptions::route('/')];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('orders.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
