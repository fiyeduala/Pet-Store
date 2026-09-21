<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NavigationItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->where('is_active', true)->orderBy('position');
    }

    public function scopeLocation($query, string $location)
    {
        return $query->where('location', $location)
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('position');
    }
}
