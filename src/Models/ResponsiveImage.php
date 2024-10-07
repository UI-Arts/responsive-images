<?php

namespace UIArts\ResponsiveImages\Models;

use Illuminate\Database\Eloquent\Model;

class ResponsiveImage extends Model
{
    protected $table = 'responsive_images';

    protected $primaryKey = 'id';

    protected $fillable = [
        'path', 'driver', 'image_data',
    ];
    public $timestamps = false;

    protected $casts = [
        'image_data' => 'array'
    ];

    public function getMimeTypeAttribute()
    {
        return $this->image_data['mime_type'] ?? null;
    }

    public function getWidthAttribute()
    {
        return $this->image_data['width'] ?? null;
    }

    public function getHeightAttribute()
    {
        return $this->image_data['height'] ?? null;
    }
}
