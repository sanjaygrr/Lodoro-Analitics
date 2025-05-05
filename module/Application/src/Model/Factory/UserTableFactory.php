<?php
// module/Application/src/Model/Factory/UserTableFactory.php

namespace Application\Model\Factory;

use Application\Model\UserTable;
use Application\Model\UserTableGateway;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class UserTableFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $tableGateway = $container->get(UserTableGateway::class);
        return new UserTable($tableGateway);
    }
}