<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'mediable_type', 'mediable_id', 'disk', 'path', 'alt', 'mime',
        'width', 'height', 'size_bytes', 'position', 'is_curated', 'source_url',
    ];

    protected function casts(): array
    {
        return ['is_curated' => 'boolean'];
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function altText(string $fallback = ''): string
    {
        return $this->alt ?: $fallback;
    }
}
