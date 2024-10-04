<?php

namespace UIArts\ResponsiveImages\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Intervention\Image\Facades\Image;
use UIArts\ResponsiveImages\Models\ResponsiveImage;

class GenerateResponsiveImages implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected $imageUrl;
    protected $paths;
    protected $sizes;
    protected $storage;
    protected $driver;
    protected $networkMode;

    public function __construct($imageUrl, $paths, $sizes, $driver, $networkMode)
    {
        $this->imageUrl = $imageUrl;
        $this->paths = $paths;
        $this->sizes = $sizes;
        $this->driver = $driver;
        $this->networkMode = $networkMode;
    }

    public function handle()
    {
        $this->storage = Storage::disk($this->driver);
        foreach ($this->paths as $mime => $links) {
            foreach ($links as $key => $link) {
                if ($this->fileExists($link)) {
                    $encoded = null;
                    $image = Image::make($this->imageUrl); //it not work with network drivers need rewrite

                    $image->resize($this->sizes[$key]['width'], $this->sizes[$key]['height'], function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });

                    if ($mime == 'webp') {
                        $encoded = $image
                            ->contrast(3)
                            ->sharpen(4)
                            ->brightness(1)
                            ->encode($mime);
                    } else {
                        $encoded = $image->encode($mime);
                    }

                    if ($encoded) {
                        $this->storage->put($link, (string) $encoded);
                        if ($this->networkMode) {
                            ResponsiveImage::create(['driver' => $this->driver, 'path' => $link]);
                        }
                    }
                }
            }
        }

    }

    private function fileExists($file)
    {
        if ($this->networkMode) {
            return ResponsiveImage::where(['driver' => $this->driver, 'path' => $file])->exists();
        }

        return $this->storage->exists($file);
    }
}
