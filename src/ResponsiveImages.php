<?php

namespace UIArts\ResponsiveImages;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\Util;
use Illuminate\Support\Facades\Log;
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
        if (isset($networkMode)) {
            return $networkMode;
        }

        return config('responsive-images.network_mode');
    }


    public function generate(
        $picture,
        array $options
    )
    {
        $this->setup($options);

        $pictures = $this->checkImagesSizes(is_array($picture) ? $picture : [$picture]);

        if (is_null($picture) || (!isset($pictures['pc']) && !isset($pictures['tablet']) && !isset($pictures['mobile']))) {
            return $this->setPlaceholder();
        }

        $arraySizes = self::makeSizesArray([
            'mobile' => $this->size_mobile,
            'tablet' => $this->size_tablet,
            'pc' => $this->size_pc
        ]);

        $mediaCondition = self::generateMediaConditions($pictures);

        $result = '';
        $images = $this->getImagePath($pictures, $arraySizes);

        if (count($images)) {
            foreach ($images as $type => $device) {
                if ($type == 'png' || isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/'.$type) >= 0) {
                    foreach ($device as $image) {
                        $result .= $this->generateSourceTag($image, $mediaCondition);
                    }
                }
            }
        }

        $lastMobileImage = $this->getLastMobileImage($images);
        if ($lastMobileImage && $this->fileExists($lastMobileImage)) {
            $picturePath = $lastMobileImage;
            $calculatedMinWidth = (int) $this->image->getWidthAttribute() ?? $this->size_pc[0];
            $calculatedMinHeight = (int) $this->image->getHeightAttribute() ?? $this->size_pc[1];
        } else {
            $lastPicture = end($pictures);
            $picturePath = $lastPicture['path'] ?? null;

            if ($picturePath && isset($this->loadedImages[$picturePath])) {
                $width = (int) $this->loadedImages[$picturePath]->getWidthAttribute() ?? $this->size_pc[0];
                $height = (int) $this->loadedImages[$picturePath]->getHeightAttribute() ?? $this->size_pc[1];

                if ($width > 0) {
                    $calculatedMinWidth = intval($arraySizes['mobile']['width']);
                    $calculatedMinHeight = intval(($calculatedMinWidth / $width) * $height);
                } else {
                    $calculatedMinWidth = $this->size_pc[0];
                    $calculatedMinHeight = $this->size_pc[1];
                }
            } else {
                $calculatedMinWidth = $this->size_pc[0];
                $calculatedMinHeight = $this->size_pc[1];
            }
        }

        $result = $this->generateHtmlImage($result, $calculatedMinWidth, $calculatedMinHeight, $picturePath);

        return '<picture class="'. $this->picture_class_name .'">'. $result. '</picture>';
    }

    private function generateSourceTag(array $image, ?array $mediaCondition)
    {
        $result = '<source srcset="';

        if (isset($image['path_x2'])) {
            $result .= str_replace(' ', '%20', $this->storage->url($image['path_x2'])) . ' 2x, ';
        }

        if (isset($image['path'])) {
            $result .= str_replace(' ', '%20', $this->storage->url($image['path'])) . ' 1x"';
        } else {
            return '';
        }

        if ($mediaCondition) {
            $result .= ' media="' . $mediaCondition[$image['device']] . '"';
        }

        // Add type attribute
        $result .= ' type="image/' . $image['type'] . '">';

        return $result;
    }

    private function generateMediaConditions(?array $pictures): ?array
    {
        $count = count($pictures);
        if ($count <= 1) {
            return null;
        }

        if ($count === 2) {
            if (isset($pictures['mobile']) && isset($pictures['pc'])) {
                return [
                    'mobile' => '(max-width: 480px)',
                    'pc' => '(min-width: 481px)'
                ];
            } elseif (isset($pictures['tablet']) && isset($pictures['pc'])) {
                return [
                  'tablet' => '(max-width: 992px)',
                  'pc' => '(min-width: 993px)'
                ];
            } elseif (isset($pictures['mobile']) && isset($pictures['tablet'])) {
                return [
                    'mobile' => '(max-width: 480px)',
                    'tablet' => '(min-width: 481px)'
                ];
            }
        }

        return [
            'mobile' => '(max-width: 480px)',
            'tablet' => '(min-width: 481px) and (max-width: 992px)',
            'pc' => '(min-width: 993px)',
        ];
    }

    public function getImageUrl($picture, $driver = null)
    {
        $this->driver = $this->getFileSystemDriver($driver);
        $this->storage = Storage::disk($this->driver);

        $generateUrl = function($pic) {
            $pic = self::isAbsoluteUrl($pic) ? self::getRelativeUrl($pic) : ltrim($pic, '/');
            return $this->storage->url($pic);
        };

        return is_array($picture)
            ? array_map($generateUrl, $picture)
            : $generateUrl($picture);
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
        $path = self::isAbsoluteUrl($path) ? self::getRelativeUrl($path) : ltrim($path, '/');
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
        $path = self::isAbsoluteUrl($path) ? self::getRelativeUrl($path) : ltrim($path, '/');
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

    private function getImagePath($imagesData, $sizes)
    {
        $images = [];
        $imagesNotExist = [];

        $types = $this->getConfig('mime_types');

        foreach ($imagesData as $data) {

            if (!$data) {
                continue;
            }

            if (in_array($data['type'], ['svg', 'svg+xml', 'gif', 'html'])) {
                $images[$data['type']][$data['device']] = $data;
                continue;
            }

            $path = $data['path'];

            $original = $path;
            $slices = explode('/', $path);
            $filename = ltrim(array_pop($slices), '/');

            $mask = dirname($path).'/%s-%s/%s/%s';

            $size = $sizes[$data['device']];
            $size2x = $sizes[$data['device'] . '_x2'];
            foreach ($types as $type) {
                $this->processImage($images, $imagesNotExist, $original, $filename, $type, $size, $size2x, $mask, $data);
            }

            if (!in_array($data['type'], $types)) {
                $this->processImage($images, $imagesNotExist, $original, $filename, $data['type'], $size, $size2x, $mask, $data);
            }
        }

        if (count($imagesNotExist)) {
            dispatch(new GenerateResponsiveImages($imagesNotExist, $sizes, $this->driver, $this->networkMode));
        }

        return $images;
    }

    private function processImage(array &$images, array &$imagesNotExist, string $original, string $filename, string $type, array $size, ?array $size2x, string $mask, array $data)
    {
        $imageName = pathinfo($filename, PATHINFO_FILENAME) . '.' . $type;
        $filePath = sprintf($mask, $size['width'] ?? 'auto', $size['height'] ?? 'auto', $this->mode, $imageName);
        $destinationPath = $this->generateDestinationPath($filePath);

        if (!$this->fileExists($destinationPath)) {
            $imagesNotExist[$original][$type][$data['device']] = $destinationPath;
        } else {
            $data['path'] = $destinationPath;
        }

        if ($size2x) {
            $filePath = sprintf($mask, $size2x['width'] ?? 'auto', $size2x['height'] ?? 'auto', $this->mode, $imageName);
            $destinationPath = ltrim($this->generateDestinationPath($filePath));

            if (!$this->fileExists($destinationPath)) {
                $imagesNotExist[$original][$type][$data['device'] . '_x2'] = $destinationPath;
            } else {
                $data['path_x2'] = $destinationPath;
            }
        }
        $data['type'] = $type;
        $images[$type][$data['device']] = $data;
    }

    private function generateDestinationPath($path)
    {
        $destination = $this->getConfig('destination');
        if ($destination) {
            return $destination . '/' .  $path;
        }
        return $path;
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

        $this->lastMobileImage = $images[$lk]['mobile']['path'] ?? null;

        return $this->lastMobileImage;
    }

    private function fileExists($file)
    {
        if (isset($this->loadedImages[$file])) {
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
            'image/svg+xml' => 'svg+xml',
            'image/svg' => 'svg',
            'text/html' => 'html'
        ];

        return $mimeMap[$mimeType] ?? null;
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

    private function generateHtmlImage($result, $width, $height, $picture)
    {
        if ($this->lazy) {
            $result .= '<img class="' . $this->class_name . '"
                src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                data-src="'.str_replace(' ','%20', $this->storage->url($picture)) . '"
                    width="'.$width.'"
                    height="'.$height.'"
                alt="' . $this->picture_title . '"
                loading="lazy"
                '. $this->printImageAttributes() .'>';
        } else {
            $result .= '<img class="' . $this->class_name . '"
                src="'.str_replace(' ','%20', $this->storage->url($picture)) . '"
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

    private function checkImagesSizes(array $pictures)
    {
        $result = [];

        $arraySizes = ['pc', 'tablet', 'mobile'];
        foreach ($arraySizes as $index => $device) {
            if (array_key_exists($index, $pictures) && ($pictures[$index] === null || $pictures[$index] === false)) {
                continue;
            }

            $picturePath = $this->getValidPicturePath($pictures, $index);
            if (!$picturePath) {
                $replacementPicture = $this->findNearestAvailableImage($pictures, $index);
                $picturePath = $replacementPicture ? $replacementPicture : null;
            }

            if (!$picturePath) {
                continue;
            }

            $result[$device] = [
                'path' => $picturePath,
                'type' => $this->mimeToExtension($this->image->getMimeTypeAttribute()),
                'device' => $device,
            ];
        }

        return $result;
    }

    private function getValidPicturePath(array $pictures, int $index): ?string
    {
        $picture = $pictures[$index] ?? null;
        if ($picture) {
            $picture = self::isAbsoluteUrl($picture) ? self::getRelativeUrl($picture) : ltrim($picture, '/');
            $picture = $this->checkAndReplaceEncodedFilePath($picture); //for some pictures which not find in reason spaces
            if ($this->fileExists($picture)) {
                return $picture;
            }
        }
        return null;
    }

    private function findNearestAvailableImage(array $pictures, int $currentIndex): ?string
    {
        $total = count($pictures);

        //search picture in previous indexes
        for ($i = $currentIndex - 1; $i >= 0; $i--) {
            $picture = $this->prepareAndCheckPicture($pictures[$i] ?? null);
            if ($picture) {
                return $picture;
            }
        }

        //search picture in next indexes
        for ($i = $currentIndex + 1; $i < $total; $i++) {
            $picture = $this->prepareAndCheckPicture($pictures[$i] ?? null);
            if ($picture) {
                return $picture;
            }
        }

        return null;
    }

    private function prepareAndCheckPicture(?string $picture): ?string
    {
        if (!$picture) {
            return null;
        }
        $picture = self::isAbsoluteUrl($picture) ? self::getRelativeUrl($picture) : ltrim($picture, '/');
        $picture = $this->checkAndReplaceEncodedFilePath($picture); //for some pictures which not find in reason spaces
        if ($this->fileExists($picture)) {
            $mimeType = $this->image->getMimeTypeAttribute($picture);
            if (!in_array($mimeType, ['image/svg+xml', 'image/svg', 'image/gif', 'text/html'])) {
                return $picture;
            }
        }

        return null;
    }
}
