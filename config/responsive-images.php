<?php

return [
    'destination' => 'cache',
    'mime_types' => [
        'avif',
        'webp',
    ],
    'exclude_mimes' => [
//
    ],
    'default_options' => [
        'picture_title' => 'Image',
        'size_pc' => '380, 380',
        'size_tablet' => '354, 354',
        'size_mobile' => '290, 290',
        'mode' => 'crop',
        'class_name' => '',
        'lazyload' => false,
        'driver' => false,
        'network_mode' => false,
        'image_attributes' => [
            //
        ]
    ],
    'network_mode' => env('RESPONSIVE_IMAGES_NETWORK_MODE', false),
    'driver' => env('RESPONSIVE_IMAGES_DRIVER', 'public')
];
