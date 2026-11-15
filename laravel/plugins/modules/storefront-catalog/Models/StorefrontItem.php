<?php

declare(strict_types=1);

namespace Plugins\Modules\StorefrontCatalog\Models;

use Illuminate\Database\Eloquent\Model;

class StorefrontItem extends Model
{
    protected $fillable = [
        'type',
        'slug',
        'title',
        'category',
        'badge',
        'price',
        'original_price',
        'is_free',
        'pages',
        'stock_count',
        'summary',
        'thumbnail_path',
        'download_url',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'original_price' => 'float',
            'is_free' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
