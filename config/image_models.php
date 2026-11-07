<?php

return [

    'flux' => [
        'label' => 'Flux',
        'provider' => 'cloudflare',
        'is_pro' => false,
    ],

    'nano-banana-2' => [
        'label' => 'Nano Banana 2',
        'provider' => 'shortapi',
        'is_pro' => true,
        'shortapi_model' => 'google/nano-banana-2/text-to-image',
    ],

    'gpt-image-2' => [
        'label' => 'GPT Image 2',
        'provider' => 'shortapi',
        'is_pro' => true,
        'shortapi_model' => 'openai/gpt-image-2/text-to-image',
    ],

    'midjourney' => [
        'label' => 'Midjourney',
        'provider' => 'shortapi',
        'is_pro' => true,
        'shortapi_model' => 'midjourney/midjourney-v7/text-to-image',
    ],

];
