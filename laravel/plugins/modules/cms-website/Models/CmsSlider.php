<?php

declare(strict_types=1);

namespace Plugins\Modules\CmsWebsite\Models;

use Illuminate\Database\Eloquent\Model;

class CmsSlider extends Model
{
    protected $fillable = ['key_name', 'title', 'subtitle', 'image_path', 'cta_url', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
