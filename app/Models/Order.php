<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyMinor;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order carries five independent state machines. A customer having paid
 * says nothing about whether the supplier has been ordered from or paid.
 */
class Order extends Model
{
    use HasFactory;

    // payment_state
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_AUTHORISED = 'authorised';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_PARTIALLY_REFUNDED = 'partially_refunded';
    public const PAYMENT_REFUNDED = 'refunded';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_CANCELLED = 'cancelled';

    // approval_state
    public const APPROVAL_NOT_REQUIRED = 'not_required';
    public const APPROVAL_AWAITING = 'awaiting_approval';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';
    public const APPROVAL_ON_HOLD = 'on_hold';

    // supplier_order_state
    public const SUPPLIER_NOT_SUBMITTED = 'not_submitted';
    public const SUPPLIER_SUBMITTING = 'submitting';
    public const SUPPLIER_SUBMITTED = 'submitted';
    public const SUPPLIER_CONFIRMED = 'confirmed';
    public const SUPPLIER_REJECTED = 'rejected';
    public const SUPPLIER_CANCELLED = 'cancelled';
    public const SUPPLIER_RECONCILING = 'reconciling';

    // supplier_payment_state
    public const SUPPLIER_PAY_NOT_PAID = 'not_paid';
    public const SUPPLIER_PAY_AUTHORISING = 'authorising';
    public const SUPPLIER_PAY_PAID = 'paid';
    public const SUPPLIER_PAY_FAILED = 'failed';
    public const SUPPLIER_PAY_INSUFFICIENT = 'insufficient_balance';

    // shipment_state
    public const SHIPMENT_NONE = 'none';
    public const SHIPMENT_PARTIAL = 'partially_shipped';
    public const SHIPMENT_SHIPPED = 'shipped';
    public const SHIPMENT_PARTIALLY_DELIVERED = 'partially_delivered';
    public const SHIPMENT_DELIVERED = 'delivered';
    public const SHIPMENT_RETURNED = 'returned';

    protected $guarded = ['id'];

    protected $hidden = ['access_token_hash'];

    protected function casts(): array
    {
        return [
            'is_guest' => 'boolean',
            'is_demo' => 'boolean',
            'costs_reconciled' => 'boolean',
            'approval_authorised_supplier_charge' => 'boolean',
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'brand_snapshot' => 'array',
            'business_snapshot' => 'array',
            'totals_breakdown' => 'array',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'approved_at' => 'datetime',
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'delivered_at' => 'datetime',
            'access_token_expires_at' => 'datetime',
            'subtotal_minor' => MoneyMinor::class.':currency',
            'discount_minor' => MoneyMinor::class.':currency',
            'shipping_minor' => MoneyMinor::class.':currency',
            'tax_minor' => MoneyMinor::class.':currency',
            'total_minor' => MoneyMinor::class.':currency',
            'refunded_minor' => MoneyMinor::class.':currency',
            'merchandise_cost_minor' => MoneyMinor::class.':currency',
            'supplier_shipping_cost_minor' => MoneyMinor::class.':currency',
            'packaging_cost_minor' => MoneyMinor::class.':currency',
            'gateway_fee_estimate_minor' => MoneyMinor::class.':currency',
            'gateway_fee_actual_minor' => MoneyMinor::class.':currency',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function fulfilments(): HasMany
    {
        return $this->hasMany(Fulfilment::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class);
    }

    public function stateEvents(): HasMany
    {
        return $this->hasMany(OrderStateEvent::class)->latest('id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(OperationalException::class);
    }

    public function packagingRecord(): BelongsTo
    {
        return $this->belongsTo(PackagingRecord::class);
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /* ---------------------------------------------------------------- */
    /* Derived predicates                                               */
    /* ---------------------------------------------------------------- */

    public function isPaid(): bool
    {
        return in_array($this->payment_state, [self::PAYMENT_PAID, self::PAYMENT_PARTIALLY_REFUNDED], true);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isFullyRefunded(): bool
    {
        return $this->payment_state === self::PAYMENT_REFUNDED;
    }

    /**
     * Fulfilment is only ever allowed from a paid, approved, uncancelled,
     * unrefunded order. Every submission path re-checks this.
     */
    public function isFulfillable(): bool
    {
        return $this->isPaid()
            && ! $this->isCancelled()
            && ! $this->isFullyRefunded()
            && $this->approval_state === self::APPROVAL_APPROVED;
    }

    /**
     * Once anything has gone to the supplier, cancellation can no longer be
     * guaranteed and must be reconciled with the supplier first.
     */
    public function cancellationCanBeGuaranteed(): bool
    {
        return in_array($this->supplier_order_state, [self::SUPPLIER_NOT_SUBMITTED, self::SUPPLIER_REJECTED], true)
            && $this->shipment_state === self::SHIPMENT_NONE;
    }

    public function isOnHold(): bool
    {
        return $this->approval_state === self::APPROVAL_ON_HOLD;
    }

    public function money(string $field): ?Money
    {
        $raw = $this->getRawOriginal($field);

        return $raw === null ? null : Money::ofMinor((int) $raw, $this->currency);
    }

    public function refundableMinor(): int
    {
        return max(0, (int) $this->getRawOriginal('total_minor') - (int) $this->getRawOriginal('refunded_minor'));
    }

    /**
     * Contribution before advertising and overheads.
     *
     * Deliberately NOT called profit: advertising and overhead are not known
     * here, and some cost components may still be estimates.
     */
    public function contributionMinor(): ?int
    {
        $merch = $this->getRawOriginal('merchandise_cost_minor');

        if ($merch === null) {
            return null;
        }

        return (int) $this->getRawOriginal('total_minor')
            - (int) $this->getRawOriginal('tax_minor')
            - (int) $this->getRawOriginal('refunded_minor')
            - (int) $merch
            - (int) ($this->getRawOriginal('supplier_shipping_cost_minor') ?? 0)
            - (int) ($this->getRawOriginal('packaging_cost_minor') ?? 0)
            - (int) ($this->getRawOriginal('gateway_fee_actual_minor')
                ?? $this->getRawOriginal('gateway_fee_estimate_minor')
                ?? 0);
    }

    /**
     * Which cost inputs are still missing or estimated. Reporting shows this
     * so an incomplete figure is never presented as a settled one.
     *
     * @return array<int, string>
     */
    public function costGaps(): array
    {
        $gaps = [];

        if ($this->getRawOriginal('merchandise_cost_minor') === null) {
            $gaps[] = 'merchandise cost';
        }
        if ($this->getRawOriginal('supplier_shipping_cost_minor') === null) {
            $gaps[] = 'supplier shipping cost';
        }
        if ($this->getRawOriginal('gateway_fee_actual_minor') === null) {
            $gaps[] = 'actual gateway fee';
        }
        if ($this->packaging_choice !== 'standard' && $this->getRawOriginal('packaging_cost_minor') === null) {
            $gaps[] = 'packaging cost';
        }

        return $gaps;
    }

    /* ---------------------------------------------------------------- */
    /* Scopes                                                           */
    /* ---------------------------------------------------------------- */

    /** Demo orders must never appear in production revenue reporting. */
    public function scopeRealMoney(Builder $query): Builder
    {
        return $query->where('is_demo', false);
    }

    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('approval_state', self::APPROVAL_AWAITING);
    }
}
