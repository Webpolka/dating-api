<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\RateLimitServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    Laravel\Reverb\ReverbServiceProvider::class,
    Mews\Purifier\PurifierServiceProvider::class,
];
