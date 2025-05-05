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
                'upload-liquidation' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/upload-liquidation',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'upload-liquidation',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            'liquidation-status' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/liquidation-status/[:id]',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'liquidation-status',
                    ],
                ],
            ],

            'get-liquidation-status' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/application/get-liquidation-status',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'get-liquidation-status',
                    ],
                ],
            ],

            'upload-liquidation' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/upload-liquidation',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'upload-liquidation',
                    ],
                ],
            ],

            'home' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'index',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            'application' => [
                'type'    => Segment::class,
                'options' => [
                    'route'    => '/application[/:action[/:table]]',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'index',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
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
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\IndexController::class => IndexControllerFactory::class,
            Controller\AuthController::class => Controller\Factory\AuthControllerFactory::class,
        ],
    ],
    // AGREGAR ESTA SECCIÓN
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
            'layout/login'            => __DIR__ . '/../view/layout/login.phtml',
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