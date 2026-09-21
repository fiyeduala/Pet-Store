<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariantAttributeValue extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_variant_id', 'attribute_id', 'attribute_value_id', 'value_text'];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function attributeValue(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class);
    }

    public function display(): string
    {
        return $this->attributeValue?->displayLabel() ?? (string) $this->value_text;
    }
}
