<?php
declare(strict_types=1);

namespace Application\Controller\Factory;

use Application\Controller\DashboardController;
use Application\Service\DatabaseService;
use Interop\Container\ContainerInterface;
use Laminas\Authentication\AuthenticationService;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Factory para el controlador de Dashboard
 */
class DashboardControllerFactory implements FactoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        // Obtener dependencias
        $dbAdapter = $container->get(AdapterInterface::class);
        $authService = $container->get(AuthenticationService::class);
        
        // Crear DatabaseService si no está registrado en el container
        if ($container->has(DatabaseService::class)) {
            $databaseService = $container->get(DatabaseService::class);
        } else {
            $databaseService = new DatabaseService($dbAdapter);
        }
        
        // Crear y devolver el controlador
        return new DashboardController($dbAdapter, $authService, $databaseService);
    }
}