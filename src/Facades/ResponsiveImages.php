<?php

namespace UIArts\ResponsiveImages\Facades;

use Illuminate\Support\Facades\Facade;

class ResponsiveImages extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'responsive-images';
    }
}
