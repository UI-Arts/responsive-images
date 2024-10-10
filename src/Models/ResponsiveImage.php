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
        $array = $this->convertJsonIfCastNotWork();

        return $array['mime_type'] ?? null;
    }

    public function getWidthAttribute()
    {
        $array = $this->convertJsonIfCastNotWork();

        return $array['width'] ?? null;
    }

    public function getHeightAttribute()
    {
        $array = $this->convertJsonIfCastNotWork();

        return $array['height'] ?? null;
    }

    private function convertJsonIfCastNotWork()
    {
        if(!is_array($this->image_data)) {
            return json_decode($this->image_data, true);
        }else{
            return $this->image_data;
        }
    }

}
