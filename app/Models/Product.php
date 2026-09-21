<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * Fields a supplier sync may never overwrite once the owner has edited them.
     * Enforced by the per-field sync policy in the catalogue sync service.
     */
    public const OWNER_OWNED_FIELDS = [
        'name', 'subtitle', 'description', 'materials', 'care_instructions',
        'suitable_for', 'seo_title', 'seo_description', 'slug', 'brand',
    ];

    protected $fillable = [
        'slug', 'name', 'subtitle', 'description', 'materials', 'care_instructions',
        'suitable_for', 'brand', 'seo_title', 'seo_description', 'status', 'is_featured',
        'published_at', 'supplier_id', 'supplier_product_id', 'supplier_source',
        'supplier_synced_at', 'locked_fields', 'default_variant_id',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
            'supplier_synced_at' => 'datetime',
            'supplier_source' => 'array',
            'locked_fields' => 'array',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    public function activeVariants(): HasMany
    {
        return $this->variants()->where('is_active', true);
    }

    public function defaultVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'default_variant_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withPivot('position');
    }

    public function petTypes(): BelongsToMany
    {
        return $this->belongsToMany(PetType::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class)->withPivot('position');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('position');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && ($this->published_at === null || $this->published_at->isPast());
    }

    /**
     * Whether a supplier sync is allowed to write this field.
     */
    public function fieldIsOwnerLocked(string $field): bool
    {
        return in_array($field, $this->locked_fields ?? [], true);
    }

    public function lockField(string $field): void
    {
        $locked = $this->locked_fields ?? [];

        if (! in_array($field, $locked, true)) {
            $locked[] = $field;
            $this->locked_fields = $locked;
        }
    }

    public function primaryImage(): ?Media
    {
        return $this->media->first();
    }

    /**
     * Active variants from the already-loaded `variants` relation.
     *
     * Reading the loaded collection rather than the `activeVariants`
     * relation means a view can call this without triggering a second
     * query per product in a listing.
     *
     * @return \Illuminate\Support\Collection<int, ProductVariant>
     */
    public function activeVariantsLoaded(): \Illuminate\Support\Collection
    {
        return $this->loadMissing('variants')->variants
            ->filter(fn (ProductVariant $v) => $v->is_active)
            ->values();
    }

    /**
     * Cheapest currently sellable variant, used for "from" pricing.
     */
    public function cheapestVariant(): ?ProductVariant
    {
        return $this->activeVariantsLoaded()
            ->filter(fn (ProductVariant $v) => $v->effectivePriceMinor() !== null)
            ->sortBy(fn (ProductVariant $v) => $v->effectivePriceMinor())
            ->first();
    }

    public function hasAnyKnownStock(): bool
    {
        return $this->activeVariantsLoaded()->contains(fn (ProductVariant $v) => $v->isPurchasable());
    }
}
