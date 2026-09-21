<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_on_site')
                ->label('View on site')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (Product $record) => route('product.show', $record))
                ->openUrlInNewTab()
                ->visible(fn (Product $record) => $record->isPublished()),

            Action::make('publish')
                ->label('Publish')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('This makes the product visible in the shop straight away.')
                ->visible(fn (Product $record) => $record->status !== Product::STATUS_PUBLISHED)
                ->action(function (Product $record): void {
                    $record->forceFill([
                        'status' => Product::STATUS_PUBLISHED,
                        'published_at' => $record->published_at ?? now(),
                    ])->save();

                    Notification::make()->title('Product published')->success()->send();
                }),

            DeleteAction::make(),
        ];
    }

    /**
     * Any field an administrator edits becomes owner-owned, so a later
     * supplier sync will leave it alone.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $locked = $this->record->locked_fields ?? [];

        foreach (Product::OWNER_OWNED_FIELDS as $field) {
            $changed = array_key_exists($field, $data)
                && (string) $data[$field] !== (string) $this->record->getOriginal($field);

            if ($changed && filled($data[$field]) && ! in_array($field, $locked, true)) {
                $locked[] = $field;
            }
        }

        $data['locked_fields'] = array_values($locked);

        return $data;
    }

    protected function getSavedNotification(): ?Notification
    {
        $locked = count($this->record->locked_fields ?? []);

        return Notification::make()
            ->success()
            ->title('Product saved')
            ->body($locked > 0
                ? "{$locked} field(s) are now owner-owned and will not be overwritten by a supplier sync."
                : null);
    }
}
