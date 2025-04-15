<?php

declare(strict_types=1);

namespace Application;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Application\Controller\Factory\IndexControllerFactory;
use Laminas\Authentication\AuthenticationService;
use Application\Middleware\AuthenticationMiddleware;

return [
    'router' => [
        'routes' => [
            'home' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'application' => [
                'type'    => Segment::class,
                'options' => [
                    // Se agregó el parámetro opcional "table"
                    'route'    => '/application[/:action[/:table]]',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            // Nuevas rutas para autenticación
            'login' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/login',
                    'defaults' => [
                        'controller' => Controller\AuthController::class,
                        'action'     => 'login',
                    ],
                ],
            ],
            'logout' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/logout',
                    'defaults' => [
                        'controller' => Controller\AuthController::class,
                        'action'     => 'logout',
                    ],
                ],
            ],
            'dashboard' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/dashboard[/:action]',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'dashboard',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            // Agrega aquí otras rutas protegidas que necesiten autenticación
        ],
    ],
    'controllers' => [
        'factories' => [
            // Se reemplaza InvokableFactory por la factory personalizada:
            Controller\IndexController::class => IndexControllerFactory::class,
            Controller\AuthController::class => Controller\Factory\AuthControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            Model\UserTable::class => Model\Factory\UserTableFactory::class,
            Model\UserTableGateway::class => Model\Factory\UserTableGatewayFactory::class,
            AuthenticationService::class => function ($container) {
                return new AuthenticationService(
                    new \Laminas\Authentication\Storage\Session('auth')
                );
            },
            Middleware\AuthenticationMiddleware::class => Middleware\Factory\AuthenticationMiddlewareFactory::class,
        ],
    ],
    'view_manager' => [
        'display_not_found_reason' => true,
        'display_exceptions'       => true,
        'doctype'                  => 'HTML5',
        'not_found_template'       => 'error/404',
        'exception_template'       => 'error/index',
        'template_map' => [
            'layout/layout'           => __DIR__ . '/../view/layout/layout.phtml',
            'application/index/index' => __DIR__ . '/../view/application/index/index.phtml',
            'application/auth/login'  => __DIR__ . '/../view/application/auth/login.phtml',
            'error/404'               => __DIR__ . '/../view/error/404.phtml',
            'error/index'             => __DIR__ . '/../view/error/index.phtml',
        ],
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],
        'view_helpers' => [
        'factories' => [
            'authenticationService' => function($container) {
                return new View\Helper\AuthenticationServiceHelper(
                    $container->get(AuthenticationService::class)
                );
            },
        ],
    ],
];