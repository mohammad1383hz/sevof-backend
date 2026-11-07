<?php

use App\Services\AI\ChatModelResolver;

return [
    // youtix-mini (رایگان) و youtix-2-pro (پولی) + id های قدیمی برای سازگاری؛ نگاشت در ChatModelResolver
    'content_models' => ChatModelResolver::acceptedIds(),
    'image_models' => ['flux', 'nano-banana-2', 'gpt-image-2', 'midjourney'],
];
