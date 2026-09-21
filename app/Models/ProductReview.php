<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sample reviews may exist so the product page layout can be validated, but
 * they are flagged and are never rendered as genuine customer testimonials.
 */
class ProductReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_sample' => 'boolean',
            'is_published' => 'boolean',
            'is_verified_purchase' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Only genuine, published reviews are shown to shoppers. */
    public function scopeGenuine($query)
    {
        return $query->where('is_published', true)->where('is_sample', false);
    }

    public function scopeSample($query)
    {
        return $query->where('is_sample', true);
    }
}
