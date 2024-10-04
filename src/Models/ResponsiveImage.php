<?php

namespace UIArts\ResponsiveImages\Models;

use Illuminate\Database\Eloquent\Model;

class ResponsiveImage extends Model
{
    protected $table = 'responsive_images';

    protected $primaryKey = 'id';

    protected $fillable = [
        'path',
    ];
}
