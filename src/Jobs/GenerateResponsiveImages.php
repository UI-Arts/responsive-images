<?php

namespace UIArts\ResponsiveImages\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use UIArts\ResponsiveImages\Models\ResponsiveImage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\AvifEncoder;

class GenerateResponsiveImages implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected $paths;
    protected $sizes;
    protected $storage;
    protected $driver;
    protected $networkMode;

    public function __construct($paths, $sizes, $driver, $networkMode)
    {
        $this->paths = $paths;
        $this->sizes = $sizes;
        $this->driver = $driver;
        $this->networkMode = $networkMode;
    }

    public function handle()
    {
        $manager = new ImageManager(new Driver()); // обери відповідний драйвер
        $this->storage = Storage::disk($this->driver);

        $encoders = [
            'webp' => WebpEncoder::class,
            'jpeg' => JpegEncoder::class,
            'jpg'  => JpegEncoder::class,
            'png'  => PngEncoder::class,
            'avif' => AvifEncoder::class,
        ];

        foreach ($this->paths as $originUrl => $paths) {
            // отримуємо зображення з драйвера
            $originImage = $manager->read($this->storage->get($originUrl));

            foreach ($paths as $mime => $links) {
                foreach ($links as $key => $link) {
                    if (!$this->fileExists($link)) {
                        $encoded = null;

                        $image = clone $originImage;

                        // resize з обмеженням пропорцій
                        $width = $this->sizes[$key]['width'] ?? null;
                        $height = $this->sizes[$key]['height'] ?? null;

                        if (!is_null($width) && !is_null($height)) {
                            $image = $image->cover($width, $height); // crop + scale
                        } else {
                            $image = $image->scaleDown($width, $height);
                        }

                        $encoderClass = $encoders[$mime] ?? null;

                        if($encoderClass) {
                            $encoder = new $encoderClass();

                            if ($mime == 'webp') {
                                $encoded = $image
                                    ->contrast(3)
                                    ->sharpen(4)
                                    ->brightness(1)
                                    ->encode($encoder);
                            } else {
                                $encoded = $image->encode($encoder);
                            }

                            if ($encoded) {
                                $this->storage->put($link, (string)$encoded);
                                $sizes = getimagesizefromstring((string)$encoded);

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
                        }else{
                            Log::info($mime.' Encoder not find');
                        }

                    }
                }
            }
        }
    }

    private function fileExists($file)
    {
        if (ResponsiveImage::where(['driver' => $this->driver, 'path' => $file])->exists()) {
            return true;
        }
        if (!$this->networkMode && $this->storage->exists($file)) {
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
