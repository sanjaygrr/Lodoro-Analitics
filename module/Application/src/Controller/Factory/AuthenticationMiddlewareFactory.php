<?php
// module/Application/src/Middleware/Factory/AuthenticationMiddlewareFactory.php

namespace Application\Middleware\Factory;

use Application\Middleware\AuthenticationMiddleware;
use Interop\Container\ContainerInterface;
use Laminas\Authentication\AuthenticationService;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AuthenticationMiddlewareFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new AuthenticationMiddleware(
            $container->get(AuthenticationService::class)
        );
    }
}