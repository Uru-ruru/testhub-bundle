<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use TestHub\Bundle\Controller\ActionController;

return static function (RoutingConfigurator $routes): void {
    $routes->add(ActionController::ROUTE, '/action/{name}')
        ->controller(ActionController::class)
        ->methods(['POST'])
        ->requirements(['name' => '[\w-]+']);
};
