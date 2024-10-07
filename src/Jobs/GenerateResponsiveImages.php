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
        $originImage = Image::make($this->storage->get($this->imageUrl));
        foreach ($this->paths as $mime => $links) {
            foreach ($links as $key => $link) {
                if (!$this->fileExists($link)) {
                    $encoded = null;
                    $image = clone $originImage;

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
                        $sizes = getimagesizefromstring($encoded);
                        ResponsiveImage::create([
                            'driver' => $this->driver,
                            'path' => $link,
                            'image_data' => json_encode([
                                'mime_type' => $sizes['mime'],
                                'width' => $sizes[0],
                                'height' => $sizes[1],
                            ]),
                        ]);
                    }
                }
            }
        }

    }

    private function fileExists($file)
    {
        if (ResponsiveImage::where(['driver' => $this->driver, 'path' => $file])->exist()) {
            return true;
        }
        if (!$this->networkMode && $this->storage->exists($file)) { //maybe remove network mode here
            $imageContent = $this->storage->get($file);
            $sizes = getimagesizefromstring($imageContent);
            ResponsiveImage::create([
                'driver' => $this->driver,
                'path' => $file,
                'image_data' => json_encode([
                    'mime_type' => $sizes['mime'],
                    'width' => $sizes[0],
                    'height' => $sizes[1],
                ]),
            ]);
            return true;
        }
        return false;
    }
}
