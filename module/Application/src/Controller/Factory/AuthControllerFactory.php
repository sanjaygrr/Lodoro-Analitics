<?php
// module/Application/src/Controller/Factory/AuthControllerFactory.php

namespace Application\Controller\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Application\Controller\AuthController;
use Laminas\Authentication\AuthenticationService;
use Application\Model\UserTable;

class AuthControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        // Obtener los servicios del contenedor
        $authService = $container->get(AuthenticationService::class);
        $userTable = $container->get(UserTable::class);
        
        // Crear e inyectar los servicios en el controlador
        return new AuthController($authService, $userTable);
    }
}