<?php

declare(strict_types=1);

namespace Plugins\Modules\CmsWebsite\Models;

use Illuminate\Database\Eloquent\Model;

class CmsPost extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'category',
        'author',
        'excerpt',
        'content',
        'thumbnail_path',
        'seo_title',
        'seo_description',
        'is_published',
    ];

    protected $casts = ['is_published' => 'boolean'];
}
