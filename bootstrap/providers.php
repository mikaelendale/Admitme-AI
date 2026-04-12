<?php

use App\Providers\AppServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\FortifyServiceProvider;
use EragLaravelPwa\EragLaravelPwaServiceProvider;

return [
    BroadcastServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    EragLaravelPwaServiceProvider::class,
];
