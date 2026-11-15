<?php

declare(strict_types=1);

namespace Plugins\Modules\CmsWebsite\Models;

use Illuminate\Database\Eloquent\Model;

class CmsPage extends Model
{
    protected $fillable = ['slug', 'title', 'banner_image', 'blocks_json', 'seo_title', 'seo_description', 'is_published'];

    protected $casts = ['blocks_json' => 'array', 'is_published' => 'boolean'];
}
