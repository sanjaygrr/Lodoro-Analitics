<?php
// module/Application/src/Controller/Factory/AuthControllerFactory.php

namespace Application\Controller\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Application\Controller\AuthController;
use Laminas\Authentication\AuthenticationService;

class AuthControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        // Obtener el servicio de autenticación del contenedor
        $authService = $container->get(AuthenticationService::class);
        
        // Crear e inyectar el servicio de autenticación en el controlador
        return new AuthController($authService);
    }
}