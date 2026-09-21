<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attribute extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'type', 'unit', 'is_filterable', 'is_variant_option', 'position'];

    protected function casts(): array
    {
        return ['is_filterable' => 'boolean', 'is_variant_option' => 'boolean'];
    }

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('position');
    }

    public function scopeFilterable($query)
    {
        return $query->where('is_filterable', true);
    }
}
