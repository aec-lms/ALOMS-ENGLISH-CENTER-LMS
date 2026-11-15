<?php

declare(strict_types=1);

namespace Plugins\Modules\CourseManagement\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'branch_id',
        'category_id',
        'code',
        'title',
        'category',
        'slug',
        'description',
        'thumbnail',
        'price',
        'original_price',
        'duration_weeks',
        'status',
    ];
}
