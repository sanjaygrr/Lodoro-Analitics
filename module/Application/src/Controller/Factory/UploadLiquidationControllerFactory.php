<?php
// module/Application/src/Controller/Factory/UploadLiquidationControllerFactory.php

namespace Application\Controller\Factory;

use Application\Controller\UploadLiquidationController;
use Interop\Container\ContainerInterface;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class UploadLiquidationControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new UploadLiquidationController(
            $container->get(AdapterInterface::class)
        );
    }
}