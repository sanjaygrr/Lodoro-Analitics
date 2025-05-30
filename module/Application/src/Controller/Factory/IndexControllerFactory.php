<?php
// module/Application/src/Controller/Factory/IndexControllerFactory.php

namespace Application\Controller\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Application\Controller\IndexController;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Authentication\AuthenticationService;
use Application\Controller\UploadLiquidationController;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        // Obtener el servicio de autenticación
        $authService = $container->get(AuthenticationService::class);

        // Crear e inyectar servicios en el controlador
        return new IndexController($authService);
    }
}

class UploadLiquidationControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $dbAdapter = $container->get(AdapterInterface::class);
        return new UploadLiquidationController($dbAdapter);
    }
}