<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PetType extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'name', 'tagline', 'description', 'image_path', 'icon',
        'position', 'is_active', 'is_primary',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_primary' => 'boolean'];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
