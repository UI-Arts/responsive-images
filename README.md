Usage:

```php
$options = [
    'picture_title' => 'Image',
    'size_pc' => '380, auto',
    'size_tablet' => '354, auto',
    'size_mobile' => '290, auto',
    'mode' => 'fit-x',
    'lazyload' => false,
//    'driver' => 'public_path',
     'image_attributes' => [
        'param' => 'value'
     ]
];

\UIArts\ResponsiveImages\Facades\ResponsiveImages::generate('image.jpg', $options)
```
