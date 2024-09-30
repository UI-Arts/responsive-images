<?php

namespace UIArts\ResponsiveImages\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Intervention\Image\Facades\Image;

class GenerateResponsiveImages implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected $imageUrl;
    protected $paths;
    protected $sizes;
    protected $storage;

    public function __construct($imageUrl, $paths, $sizes, $storage)
    {
        $this->imageUrl = $imageUrl;
        $this->paths = $paths;
        $this->sizes = $sizes;
        $this->storage = $storage;
    }

    public function handle()
    {
        foreach ($this->paths as $mime => $links){

            foreach ($links as $key => $link){
                if (!$this->storage->exists($link)) {
                    $encoded = null;
                    $image = Image::make($this->imageUrl);

                    $image->resize($this->sizes[$key]['width'], $this->sizes[$key]['height'], function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });

                    if($mime == 'webp') {
                        $encoded = $image
                            ->contrast(3)
                            ->sharpen(4)
                            ->brightness(1)
                            ->encode($mime);
                    }else{
                        $encoded = $image->encode($mime);
                    }

                    if($encoded){
                        $this->storage->put($link, (string) $encoded);
                    }
                }
            }
        }

    }
}
