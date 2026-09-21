<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyMinor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PackagingRecord extends Model
{
    public const TYPE_STANDARD = 'standard';
    public const TYPE_BRANDED_BOX = 'branded_box';

    public const STATE_PLANNED = 'planned';
    public const STATE_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATE_APPROVED = 'approved';
    public const STATE_STOCKING = 'stocking';
    public const STATE_AVAILABLE = 'available';
    public const STATE_UNAVAILABLE = 'unavailable';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'api_selectable' => 'boolean',
            'api_reportable' => 'boolean',
            'approved_at' => 'datetime',
            'unit_cost_minor' => MoneyMinor::class.':currency',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'packaging_record_variant');
    }

    /**
     * Usable for a real order only when it is in the available state AND has
     * confirmed stock at the fulfilling warehouse. Having a design uploaded
     * or a logo on file is explicitly not enough.
     */
    public function isUsableFor(?Warehouse $warehouse, int $quantity = 1): bool
    {
        if ($this->state !== self::STATE_AVAILABLE) {
            return false;
        }

        if ($warehouse !== null && $this->warehouse_id !== null && $this->warehouse_id !== $warehouse->id) {
            return false;
        }

        return $this->available_quantity !== null && $this->available_quantity >= $quantity;
    }

    public function isCustomerPromisable(): bool
    {
        return $this->type === self::TYPE_STANDARD || $this->state === self::STATE_AVAILABLE;
    }

    public function stateLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->state));
    }

    /**
     * Why this packaging cannot currently be used, for admin display.
     */
    public function blockingReason(): ?string
    {
        if ($this->state === self::STATE_AVAILABLE && ($this->available_quantity ?? 0) > 0) {
            return null;
        }

        return match ($this->state) {
            self::STATE_PLANNED => 'Design stage only. Not submitted to the supplier.',
            self::STATE_AWAITING_APPROVAL => 'Submitted to the supplier; approval not yet received.',
            self::STATE_APPROVED => 'Approved by the supplier but no stock has been produced yet.',
            self::STATE_STOCKING => 'Being produced or shipped to the warehouse.',
            self::STATE_UNAVAILABLE => 'Marked unavailable by an administrator.',
            default => 'No packaging stock recorded at the warehouse.',
        };
    }
}
