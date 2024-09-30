<?php

namespace UIArts\ResponsiveImages\Providers;

use Illuminate\Support\ServiceProvider;
use UIArts\ResponsiveImages\ResponsiveImages;

class ResponsiveImagesServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/responsive-images.php', 'responsive-images'
        );

        $this->app->singleton('responsive-images', function () {
            return new ResponsiveImages();
        });
    }

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../../config/responsive-images.php' => config_path('responsive-images.php'),
        ], 'config');
    }
}
