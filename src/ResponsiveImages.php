<?php

namespace UIArts\ResponsiveImages;

use Illuminate\Support\Facades\Storage;
use UIArts\ResponsiveImages\Jobs\GenerateResponsiveImages;

class ResponsiveImages
{
    private $storage;
    private $size_pc;
    private $size_tablet;
    private $size_mobile;
    private $mode;
    private $lazy = false;
    private $class_name = '';
    private $picture_title = 'Image';
    private $lastMobileImage;
    private $currentMime;

    private function setup($options)
    {
        $default_options = $this->getConfig('default_options');
        $options = array_merge($default_options, $options);

        //set storage
        $this->storage = Storage::disk($this->getFileSystemDriver($options['driver']));

        $this->size_pc = explode(',', preg_replace('/\s/', '', $options['size_pc']));
        $this->size_tablet = explode(',', preg_replace('/\s/', '', $options['size_tablet']));
        $this->size_mobile = explode(',', preg_replace('/\s/', '', $options['size_mobile']));

        $this->lazy = $options['lazyload'];
        $this->mode = $options['mode'];
        $this->class_name = $options['class_name'];
        $this->picture_title = $options['picture_title'];
    }

    private function getConfig($key)
    {
        return config('responsive-images.'. $key);
    }

    public function getFileSystemDriver($driver)
    {
        if($driver){
            return $driver;
        }

        return config('responsive-images.driver');
    }

    public function generate(
        string $picture,
        array $options
    )
    {
        $this->setup($options);

        $picture = self::isAbsoluteUrl($picture) ? self::getRelativeUrl($picture) : ltrim($picture, '/');

        if(
            is_null($picture) ||
            is_array($picture) ||
            !$this->fileExists($picture)
        ){
            return false;
        }

        $arraySizes = self::makeSizesArray([
            'mobile' => $this->size_mobile,
            'tablet' => $this->size_tablet,
            'pc' => $this->size_pc
        ]);

        $result = '';

        $sizes = getimagesize($this->storage->path($picture));
        $width = ($sizes && count($sizes) && $sizes[0]) ? $sizes[0] : $this->size_pc[0];
        $height = ($sizes && count($sizes) && $sizes[1]) ? $sizes[1] : $this->size_pc[1];

        $this->currentMime = $this->storage->mimeType($picture);

        if (
            $this->currentMime == 'image/svg+xml' ||
            $this->currentMime == 'image/svg' ||
            $this->currentMime == 'text/html'
        ){
            if ($this->lazy) {
                $result .= '<img class="' . $this->class_name . '"
                    data-src="'.url($picture). '"
                        width="'.$width.'"
                        height="'.$height.'"
                        loading="lazy"
                    alt="' . $this->picture_title . '"
                    fetchpriority="low">';
            } else {
                $result .= '<img class="' . $this->class_name . '"
                    src="'. url($picture) . '"
                        width="'.$width.'"
                        height="'.$height.'"
                    alt="' . $this->picture_title . '"
                    fetchpriority="low">';
            }
        } else {

            if ($this->currentMime != 'image/gif') {

                $images = $this->getImagePath($picture, $arraySizes);

                if(count($images)){
                    foreach ($images as $type => $image){
                        if($type == 'png' || isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/'.$type) >= 0){
                            $result .= '<source srcset="
                                    '.str_replace(' ','%20', url($image['mobile_x2'])) . ' 2x,
                                    '.str_replace(' ','%20', url($image['mobile'])) .' 1x"
                                    media="(max-width: 480px)" type="image/'. $type .'">';
                            $result .= '<source srcset="
                                    ' . str_replace(' ', '%20', url($image['tablet_x2'])) . ' 2x,
                                    ' . str_replace(' ', '%20', url($image['tablet'])) . ' 1x"
                                    media="(max-width: 992px)" type="image/'. $type .'">';
                            $result .= '<source srcset="
                                    ' . str_replace(' ', '%20', url($image['pc_x2'])) . ' 2x,
                                    ' . str_replace(' ', '%20', url($image['pc'])) . ' 1x
                                    " media="(min-width: 993px)" type="image/'. $type .'">';
                        }
                    }
                }

            } else {
                $result .= '<source srcset="'.str_replace(' ','%20', url($picture)) . '">';
            }

            if(
                $this->getLastMobileImage($images) &&
                $this->fileExists($this->getLastMobileImage($images))
            ){
                $picture = $this->storage->path($this->getLastMobileImage($images));
                $sizes = getimagesize($picture);
                $calculatedMinWidth = ($sizes && count($sizes) && $sizes[0]) ? $sizes[0] : $this->size_pc[0];
                $calculatedMinHeight = ($sizes && count($sizes) && $sizes[1]) ? $sizes[1] : $this->size_pc[1];
            }else{
                $calculatedMinWidth = $arraySizes['mobile']['width'];
                $calculatedMinHeight = intval(($calculatedMinWidth / $width) * $height);
            }

            $result .= '<img class="' . $this->class_name . '"
                    src="'.str_replace(' ','%20', url($picture)) . '"
                        width="'.$calculatedMinWidth.'"
                        height="'.$calculatedMinHeight.'"
                    alt="' . $this->picture_title . '"
                    fetchpriority="low">';
        }

        return '<picture>'. $result. '</picture>';
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

        foreach ($types as $type){
            foreach ($sizes as $s => $size){

                $imagename = pathinfo($filename, PATHINFO_FILENAME).'.'. $type;

                $filePath = sprintf($mask, $size['width'] ?? 'auto', $size['height'] ?? 'auto', $this->mode, $imagename);
                $images[$type][$s] = $this->generateDestinationPath($filePath);

                if(!$this->fileExists($images[$type][$s])){
                    $imagesNotExist[$type][$s] = $images[$type][$s];
                }
            }
        }

        if(count($imagesNotExist)){
            dispatch(new GenerateResponsiveImages($original, $imagesNotExist, $sizes, $this->storage));
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
        if($this->lastMobileImage){
            return $this->lastMobileImage;
        }

        $lk = array_key_last($images);

        $this->lastMobileImage = $images[$lk]['mobile'] ?? null;

        return $this->lastMobileImage;
    }

    private function fileExists($file)
    {

        //s3 logic here

        return $this->storage->exists($file);
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

}
