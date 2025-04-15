<?php
// module/Application/src/Controller/Factory/AuthControllerFactory.php

namespace Application\Controller\Factory;

use Application\Controller\AuthController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AuthControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        // Versión simplificada que no depende de UserTable
        return new AuthController();
    }
}