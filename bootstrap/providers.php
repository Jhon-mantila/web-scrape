<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    App\Providers\SocialPublishingServiceProvider::class,
    NeuronAI\Laravel\NeuronAIServiceProvider::class,
];
