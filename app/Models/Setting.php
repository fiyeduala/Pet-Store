<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'type', 'is_encrypted', 'description'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_encrypted' => 'boolean',
        ];
    }
}
