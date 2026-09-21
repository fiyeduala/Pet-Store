<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeSection extends Model
{
    public const TYPE_HERO = 'hero';
    public const TYPE_PET_ENTRY = 'pet_entry';
    public const TYPE_COLLECTION_ROW = 'collection_row';
    public const TYPE_FEATURED_PRODUCTS = 'featured_products';
    public const TYPE_EDITORIAL = 'editorial';
    public const TYPE_INFO_COLUMNS = 'info_columns';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_active' => 'boolean'];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('position');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}
