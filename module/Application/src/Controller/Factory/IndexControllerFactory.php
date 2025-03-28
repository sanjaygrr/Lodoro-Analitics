<?php

namespace Application\Controller\Factory;

use Psr\Container\ContainerInterface;               // <-- OJO: PSR, no Interop
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\Db\Adapter\AdapterInterface;
use Application\Controller\IndexController;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {

        $dbAdapter = $container->get(AdapterInterface::class);

        return new IndexController($dbAdapter);
    }
}
