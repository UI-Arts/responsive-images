<?php

namespace UIArts\ResponsiveImages;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\Util;
use UIArts\ResponsiveImages\Jobs\GenerateResponsiveImages;
use UIArts\ResponsiveImages\Models\ResponsiveImage;

class ResponsiveImages
{
    private $storage;
    private $networkMode;
    private $driver;
    private $image;
    private $loadedImages = [];
    private $size_pc;
    private $size_tablet;
    private $size_mobile;
    private $mode;
    private $lazy = false;
    private $class_name = '';
    private $picture_class_name = '';
    private $picture_title = 'Image';
    private $lastMobileImage;
    private $currentMime;
    private $imageAttributes;

    private function setup($options)
    {
        $default_options = $this->getConfig('default_options');
        $options = array_merge($default_options, $options);

        $this->networkMode = $this->getNetworkMode($options['network_mode']);
        $this->driver = $this->getFileSystemDriver($options['driver']);

        //set storage
        $this->storage = Storage::disk($this->driver);

        $this->size_pc = explode(',', preg_replace('/\s/', '', $options['size_pc']));
        $this->size_tablet = explode(',', preg_replace('/\s/', '', $options['size_tablet']));
        $this->size_mobile = explode(',', preg_replace('/\s/', '', $options['size_mobile']));

        $this->lazy = $options['lazyload'];
        $this->mode = $options['mode'];
        $this->class_name = $options['class_name'];
        $this->picture_title = $options['picture_title'];
        $this->picture_class_name = $options['picture_class_name'];

        $this->lastMobileImage = null;

        $this->imageAttributes = $options['image_attributes'] ?? false;
    }

    private function getConfig($key)
    {
        return config('responsive-images.'. $key);
    }

    public function getFileSystemDriver($driver)
    {
        if ($driver) {
            return $driver;
        }

        return config('responsive-images.driver');
    }

    public function getNetworkMode($networkMode)
    {
        if ($networkMode) {
            return $networkMode;
        }

        return config('responsive-images.network_mode');
    }


    public function generate(
        string $picture,
        array $options
    )
    {
        $this->setup($options);

        $picture = self::isAbsoluteUrl($picture) ? self::getRelativeUrl($picture) : ltrim($picture, '/');

        $picture = $this->checkAndReplaceEncodedFilePath($picture);

        if (
            is_null($picture) ||
            is_array($picture) ||
            !$this->fileExists($picture)
        ) {
            return $this->setPlaceholder();
        }

        $this->currentMime = $this->image->getMimeTypeAttribute();

        if (in_array($this->currentMime, ['image/svg+xml', 'image/svg', 'text/html'])) {

            $svg = $this->generateHtmlImage('', $this->size_pc[0], $this->size_pc[1], $picture);

            return '<picture class="'. $this->picture_class_name .'">'. $svg. '</picture>';
        }

        $arraySizes = self::makeSizesArray([
            'mobile' => $this->size_mobile,
            'tablet' => $this->size_tablet,
            'pc' => $this->size_pc
        ]);

        $result = '';
        $width = $this->image->getWidthAttribute() ?? $this->size_pc[0];
        $height = $this->image->getHeightAttribute() ?? $this->size_pc[1];

        if ($this->currentMime != 'image/gif') {

            $images = $this->getImagePath($picture, $arraySizes);

            if(count($images)){
                foreach ($images as $type => $image){
                    if($type == 'png' || isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/'.$type) >= 0) {
                        $result .= '<source srcset="
                                '.str_replace(' ','%20', $this->storage->url($this->clearPath($image['mobile_x2']))) . ' 2x,
                                '.str_replace(' ','%20', $this->storage->url($this->clearPath($image['mobile']))) .' 1x"
                                media="(max-width: 480px)" type="image/'. $type .'">';
                        $result .= '<source srcset="
                                ' . str_replace(' ', '%20', $this->storage->url($this->clearPath($image['tablet_x2']))) . ' 2x,
                                ' . str_replace(' ', '%20', $this->storage->url($this->clearPath($image['tablet']))) . ' 1x"
                                media="(max-width: 992px)" type="image/'. $type .'">';
                        $result .= '<source srcset="
                                ' . str_replace(' ', '%20', $this->storage->url($this->clearPath($image['pc_x2']))) . ' 2x,
                                ' . str_replace(' ', '%20', $this->storage->url($this->clearPath($image['pc']))) . ' 1x
                                " media="(min-width: 993px)" type="image/'. $type .'">';
                    }
                }
            }

        } else {
            $result .= '<source srcset="'.str_replace(' ','%20', $this->storage->url($picture)) . '">';
        }

        if (
            $this->getLastMobileImage($images) &&
            $this->fileExists($this->getLastMobileImage($images))
        ) {
            $picture = $this->storage->path($this->getLastMobileImage($images));
            $calculatedMinWidth = $this->image->getWidthAttribute() ?? $this->size_pc[0];
            $calculatedMinHeight = $this->image->getHeightAttribute() ?? $this->size_pc[1];
        } else {
            $calculatedMinWidth = intval($arraySizes['mobile']['width']);
            $calculatedMinHeight = intval(($calculatedMinWidth / $width) * $height);
        }

        $result = $this->generateHtmlImage($result, $calculatedMinWidth, $calculatedMinHeight, $picture);

        return '<picture class="'. $this->picture_class_name .'">'. $result. '</picture>';
    }

    public function getImageUrl($picture, $driver = null)
    {
        $picture = self::isAbsoluteUrl($picture) ? self::getRelativeUrl($picture) : ltrim($picture, '/');
        if (!$this->driver) {
            $this->driver = $this->getFileSystemDriver($driver);
        }
        if (!$this->storage) {
            $this->storage = Storage::disk($this->driver);
        }
        return $this->storage->url($picture);
    }

    public function getImage($path, $driver = null)
    {
        $this->driver = $this->getFileSystemDriver($driver);
        $this->storage = Storage::disk($this->driver);
        return $this->storage->get($path);
    }

    public function uploadImage($path, $file, $driver = null)
    {
        $this->driver = $this->getFileSystemDriver($driver);
        $this->storage = Storage::disk($this->driver);
        $status = $this->storage->put($path, $file);
        $sizes = @getimagesizefromstring($file);
        $imageData = [
            'mime_type' => $this->storage->mimeType($path)
        ];
        if ($sizes) {
            $imageData['width'] = $sizes[0];
            $imageData['height'] = $sizes[1];
        }

        ResponsiveImage::create([
            'driver' => $this->driver,
            'path' => $path,
            'image_data' => json_encode($imageData),
        ]);
        return $status;
    }

    public function checkImage($path, $driver = null, $networkMode = null)
    {
        $this->driver = $this->getFileSystemDriver($driver);
        $this->storage = Storage::disk($this->driver);
        $this->networkMode = $this->getNetworkMode($networkMode);
        $this->fileExists($path);
        return $this->image;
    }

    private static function isAbsoluteUrl($url)
    {
        $pattern = "/^(?:ftp|https?|feed)?:?\/\/(?:(?:(?:[\w\.\-\+!$&'\(\)*\+,;=]|%[0-9a-f]{2})+:)*
        (?:[\w\.\-\+%!$&'\(\)*\+,;=]|%[0-9a-f]{2})+@)?(?:
        (?:[a-z0-9\-\.]|%[0-9a-f]{2})+|(?:\[(?:[0-9a-f]{0,4}:)*(?:[0-9a-f]{0,4})\]))(?::[0-9]+)?(?:[\/|\?]
        (?:[\w#!:\.\?\+\|=&@$'~*,;\/\(\)\[\]\-]|%[0-9a-f]{2})*)?$/xi";
        return (bool) preg_match($pattern, $url);
    }

    private static function getRelativeUrl($url) {
        $url = str_replace(config('app.url'), '', $url);
        if (!empty($url) && $url[0] == '/') {
            $url = ltrim($url, '/');
        }
        return $url;
    }

    private function getImagePath($path, $sizes)
    {
        $path = $this->storage->path($path);

        $original = $path;
        $slices = explode('/', $path);
        $filename = array_pop($slices);

        $mask = dirname($path).'/%s-%s/%s/%s';

        $images = [];
        $imagesNotExist = [];

        $types = $this->getConfig('mime_types');

        if($this->currentMime) {
            if($currentExtension = $this->mimeToExtension($this->currentMime)) {
                $types[] = $currentExtension;
            }
        }

        foreach ($types as $type) {
            foreach ($sizes as $s => $size){

                $imagename = pathinfo($filename, PATHINFO_FILENAME).'.'. $type;

                $filePath = sprintf($mask, $size['width'] ?? 'auto', $size['height'] ?? 'auto', $this->mode, $imagename);
                $images[$type][$s] = $this->generateDestinationPath($filePath);

                if(!$this->fileExists($images[$type][$s])){
                    $imagesNotExist[$type][$s] = $images[$type][$s];
                    $images[$type][$s] = $path;
                }
            }
        }

        if (count($imagesNotExist)) {
            dispatch(new GenerateResponsiveImages($original, $imagesNotExist, $sizes, $this->driver, $this->networkMode));
        }

        return $images;
    }

    private function generateDestinationPath($path)
    {
        return str_replace(
            $this->storage->path(''),
            rtrim($this->getConfig('destination'), '/') . '/',
            $path
        );
    }

    private function makeSizesArray($array)
    {
        $result = [];

        foreach ($array as $key => $item) {
            $width = is_numeric($item[0]) ? $item[0] : null;
            $height = is_numeric($item[1]) ? $item[1] : null;

            $result[$key] = [
                'width'  => $width,
                'height' => $height
            ];
            $result[$key.'_x2'] = [
                'width'  => $width ? $width * 2 : null,
                'height' => $height ? $height * 2 : null
            ];
        }

        return $result;
    }

    private function getLastMobileImage($images)
    {
        if ($this->lastMobileImage) {
            return $this->lastMobileImage;
        }

        $lk = array_key_last($images);

        $this->lastMobileImage = $images[$lk]['mobile'] ?? null;

        return $this->lastMobileImage;
    }

    private function fileExists($file)
    {
        if(isset($this->loadedImages[$file])){
            $this->image = $this->loadedImages[$file];
            return true;
        }

        $image = ResponsiveImage::where(['driver' => $this->driver, 'path' => $file])->first();
        $this->image = null;
        if ($image) {
            $this->image = $image;
            $this->loadedImages[$file] = $image;
            return true;
        }
        if (!$this->networkMode && $this->storage->exists($file)) {
            $imageContent = $this->storage->get($file);
            $sizes = @getimagesizefromstring($imageContent);
            $imageData = [
                'mime_type' => $this->storage->mimeType($file),
            ];
            if ($sizes) {
                $imageData['width'] = $sizes[0];
                $imageData['height'] = $sizes[1];
            }

            $this->image = ResponsiveImage::create([
                'driver' => $this->driver,
                'path' => $file,
                'image_data' => json_encode($imageData),
            ]);
            return true;
        }
        return false;
    }

    private function mimeToExtension($mimeType) {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];

        return isset($mimeMap[$mimeType]) ? $mimeMap[$mimeType] : null;
    }

    private function printImageAttributes()
    {
        $result = '';

        if ($this->imageAttributes && is_array($this->imageAttributes)) {
            foreach ($this->imageAttributes as $key => $attr) {
                $result .= $key.'="'. $attr .'" ';
            }
        }

        return $result;
    }

    private function setPlaceholder()
    {
        $placeholderType = config('responsive-images.placeholder_type');

        switch ($placeholderType) {
            case 'static':
                return '<picture class="' . $this->picture_class_name . '">
                            <img class="' . $this->class_name . '"
                                 src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                                 width="' . $this->size_pc[0] . '"
                                 height="' . $this->size_pc[1] . '"
                                 loading="lazy" alt="' . $this->picture_title . '">
                        </picture>';

            case 'dynamic':
                return '<picture class="' . $this->picture_class_name . '">
                            <img class="' . $this->class_name . '"
                                 src="https://picsum.photos/' . $this->size_pc[0] . '/' .
                                    ($this->size_pc[1] >= 1000 ? $this->size_pc[0] / 2 : $this->size_pc[1]) . '"
                                 width="' . $this->size_pc[0] . '"
                                 height="' . ($this->size_pc[1] >= 1000 ? $this->size_pc[0] / 2 : $this->size_pc[1]) . '"
                                 loading="lazy" alt="' . $this->picture_title . '">
                        </picture>';

            case 'none':
            default:
                return false;
        }
    }

    private function clearPath($path)
    {
        if(!$this->networkMode) {
            return str_replace(public_path(), '', $path);
        }

        return $path;
    }

    private function generateHtmlImage($result, $width, $height, $picture)
    {
        if($this->lazy){
            $result .= '<img class="' . $this->class_name . '"
                src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                data-src="'.str_replace(' ','%20', $this->storage->url($this->clearPath($picture))) . '"
                    width="'.$width.'"
                    height="'.$height.'"
                alt="' . $this->picture_title . '"
                loading="lazy"
                '. $this->printImageAttributes() .'>';
        }else{
            $result .= '<img class="' . $this->class_name . '"
                src="'.str_replace(' ','%20', $this->storage->url($this->clearPath($picture))) . '"
                    width="'.$width.'"
                    height="'.$height.'"
                alt="' . $this->picture_title . '"
                '. $this->printImageAttributes() .'>';
        }

        return $result;
    }

    private function checkAndReplaceEncodedFilePath($path)
    {
        if(!$this->networkMode) {
            if($this->storage->exists($path)){
                return $path;
            }else if($this->storage->exists(str_replace('%20', ' ', $path))){
                return str_replace('%20', ' ', $path);
            }
        }

        return $path;
    }
}
