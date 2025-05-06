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

            'search-ean' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/application/search-ean',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'search-ean',
                    ],
                ],
            ],
            'mark-product-processed' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/application/mark-product-processed',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'mark-product-processed',
                    ],
                ],
            ],

            'scan-orders' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/scan-orders',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'scan-orders',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],

            'order-detail' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/order-detail/:id/:table',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'order-detail',
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
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            'dashboard' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/dashboard[/:action]',
                    'defaults' => [
                        'controller' => \Application\Controller\IndexController::class, // Usar namespace completo
                        'action'     => 'dashboard',
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

    'mark-as-printed' => [
        'type' => Literal::class,
        'options' => [
            'route'    => '/application/mark-as-printed',
            'defaults' => [
                'controller' => Controller\IndexController::class,
                'action'     => 'mark-as-printed',
            ],
        ],
        'middleware' => [
            AuthenticationMiddleware::class,
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