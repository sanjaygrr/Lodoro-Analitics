<?php

declare(strict_types=1);

namespace Application;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Laminas\Authentication\AuthenticationService;
use Laminas\Db\Adapter\AdapterInterface;
use Application\Middleware\AuthenticationMiddleware;
use Application\Service\DatabaseService;

return [
    // Controller plugins
    'controller_plugins' => [
        'aliases' => [
            'getServiceLocator' => 'ServiceLocator',
        ],
        'factories' => [
            'ServiceLocator' => function($container) {
                return new class($container) {
                    private $container;
                    
                    public function __construct($container) {
                        $this->container = $container;
                    }
                    
                    public function __invoke() {
                        return $this->container;
                    }
                };
            },
        ],
    ],
    
    // Router configuration
    'router' => [
        'routes' => [
            // Home route
            'home' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/',
                    'defaults' => [
                        'controller' => Controller\DashboardController::class,
                        'action'     => 'index',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            
            // Dashboard routes
            'dashboard' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/dashboard[/:action[/:marketplace]]',
                    'defaults' => [
                        'controller' => Controller\DashboardController::class,
                        'action'     => 'index',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            
            // Marketplace routes
            'marketplace' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/marketplace[/:action]',
                    'defaults' => [
                        'controller' => Controller\MarketplaceController::class,
                        'action'     => 'config',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            
            // Orders routes
            'orders' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/orders[/:action[/:table]]',
                    'defaults' => [
                        'controller' => Controller\OrdersController::class,
                        'action'     => 'index',
                    ],
                    'constraints' => [
                        'table' => '[a-zA-Z0-9_]+',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],

            // Bulk Orders actions route
            'bulk-orders' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/bulk-orders',
                    'defaults' => [
                        'controller' => Controller\OrdersController::class,
                        'action'     => 'bulkOrders',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            
            // API para marcar como impresa
            'mark-as-printed' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/orders/mark-as-printed',
                    'defaults' => [
                        'controller' => Controller\OrdersController::class,
                        'action'     => 'markAsPrinted',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            
            // API para marcar como procesada
            'mark-as-processed' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/orders/mark-as-processed',
                    'defaults' => [
                        'controller' => Controller\OrdersController::class,
                        'action'     => 'markAsProcessed',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            
            // Ruta específica para detalles de órdenes de Paris
            'paris-order' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/paris-order',
                    'defaults' => [
                        'controller' => Controller\OrdersController::class,
                        'action'     => 'parisOrders',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'detail' => [
                        'type' => Segment::class,
                        'options' => [
                            'route'    => '/:id',
                            'defaults' => [
                                'action'     => 'parisOrderDetail',
                            ],
                            'constraints' => [
                                'id' => '[a-zA-Z0-9]+',
                            ],
                        ],
                    ],
                ],
            ],
            
            // Order detail route
            'order-detail' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/order-detail/:id/:table',
                    'defaults' => [
                        'controller' => Controller\OrdersController::class,
                        'action'     => 'orderDetail',
                    ],
                    'constraints' => [
                        'id' => '[a-zA-Z0-9]+',
                        'table' => '[a-zA-Z0-9_]+',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            
            // ScanOrders route
            'scan-orders' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/scan-orders[/:action]',
                    'defaults' => [
                        'controller' => Controller\ScanOrdersController::class,
                        'action'     => 'index',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            
            // Upload liquidation route
            'upload-liquidation' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/application/upload-liquidation',
                    'defaults' => [
                        'controller' => Controller\UploadLiquidationController::class,
                        'action' => 'upload',
                    ],
                ],
            ],
            
            // Legacy orders-detail route for backwards compatibility
            'legacy-orders-detail' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/application/orders-detail/:table',
                    'defaults' => [
                        'controller' => Controller\OrdersController::class,
                        'action'     => 'orders-detail',
                    ],
                    'constraints' => [
                        'table' => '[a-zA-Z0-9_]+',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            
            // Legacy detail route for backwards compatibility
            'legacy-detail' => [
                'type' => Segment::class,
                'options' => [
                    'route'    => '/application/detail/:table',
                    'defaults' => [
                        'controller' => Controller\DashboardController::class,
                        'action'     => 'detail',
                    ],
                    'constraints' => [
                        'table' => '[a-zA-Z0-9_]+',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            
            // Other application routes
            'application' => [
                'type'    => Segment::class,
                'options' => [
                    'route'    => '/application[/:action[/:table]]',
                    'defaults' => [
                        'controller' => Controller\DashboardController::class,
                        'action'     => 'index',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            
            // Auth routes
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
            
            // API Actions
            'search-ean' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/application/search-ean',
                    'defaults' => [
                        'controller' => Controller\ScanOrdersController::class,
                        'action'     => 'search-ean',
                    ],
                ],
            ],
            'mark-product-processed' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/application/mark-product-processed',
                    'defaults' => [
                        'controller' => Controller\OrdersController::class,
                        'action'     => 'mark-product-processed',
                    ],
                ],
            ],
            'process-all-products' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/application/process-all-products',
                    'defaults' => [
                        'controller' => Controller\OrdersController::class,
                        'action'     => 'process-all-products',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            'update-order-processed-status' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/application/update-order-processed-status',
                    'defaults' => [
                        'controller' => Controller\OrdersController::class,
                        'action'     => 'updateOrderProcessedStatus',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            'update-order-status' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/application/update-order-status',
                    'defaults' => [
                        'controller' => Controller\OrdersController::class,
                        'action'     => 'updateOrderStatus',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            'update-order-carrier' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/application/update-order-carrier',
                    'defaults' => [
                        'controller' => Controller\OrdersController::class,
                        'action'     => 'updateOrderCarrier',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            'mark-as-printed' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/application/mark-as-printed',
                    'defaults' => [
                        'controller' => Controller\OrdersController::class,
                        'action'     => 'mark-as-printed',
                    ],
                ],
                'middleware' => [
                    AuthenticationMiddleware::class,
                ],
            ],
            'test-connection' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/application/test-connection',
                    'defaults' => [
                        'controller' => Controller\MarketplaceController::class,
                        'action'     => 'test-connection',
                    ],
                ],
            ],
            'generate-barcode' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/application/generate-barcode',
                    'defaults' => [
                        'controller' => Controller\ScanOrdersController::class,
                        'action'     => 'generate-barcode',
                    ],
                ],
            ],
        ],
    ],

    // Controllers configuration
    'controllers' => [
        'factories' => [
            Controller\IndexController::class => Controller\Factory\IndexControllerFactory::class,
            Controller\BaseController::class => function($container) {
                return new Controller\BaseController(
                    $container->get(AdapterInterface::class),
                    $container->get(AuthenticationService::class)
                );
            },
            Controller\DashboardController::class => Controller\Factory\DashboardControllerFactory::class,
            Controller\MarketplaceController::class => Controller\Factory\MarketplaceControllerFactory::class,
            Controller\OrdersController::class => Controller\Factory\OrdersControllerFactory::class,
            Controller\ScanOrdersController::class => Controller\Factory\ScanOrdersControllerFactory::class,
            Controller\UploadLiquidationController::class => Controller\Factory\UploadLiquidationControllerFactory::class,
            Controller\AuthController::class => Controller\Factory\AuthControllerFactory::class,
        ],
    ],
    
    // Services configuration
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
            Service\DatabaseService::class => function ($container) {
                return new Service\DatabaseService(
                    $container->get(AdapterInterface::class)
                );
            },
        ],
    ],
    
    // View manager configuration
    'view_manager' => [
        'display_not_found_reason' => true,
        'display_exceptions'       => true,
        'doctype'                  => 'HTML5',
        'not_found_template'       => 'error/404',
        'exception_template'       => 'error/index',
        'template_map' => [
            'layout/layout'           => __DIR__ . '/../view/layout/layout.phtml',
            'application/index/index' => __DIR__ . '/../view/application/index/index.phtml',
            'application/dashboard/index' => __DIR__ . '/../view/application/dashboard/index.phtml',
            'application/marketplace/config' => __DIR__ . '/../view/application/marketplace/config.phtml',
            'application/orders/index' => __DIR__ . '/../view/application/orders/index.phtml',
            'application/orders/order-detail' => __DIR__ . '/../view/application/orders/order-detail.phtml',
            'application/orders/orders-detail' => __DIR__ . '/../view/application/orders/orders-detail.phtml',
            'application/scan-orders/index' => __DIR__ . '/../view/application/scan-orders/index.phtml',
            'application/upload-liquidation/upload' => __DIR__ . '/../view/application/upload-liquidation/upload.phtml',
            'application/auth/login'  => __DIR__ . '/../view/application/auth/login.phtml',
            'error/404'               => __DIR__ . '/../view/error/404.phtml',
            'error/index'             => __DIR__ . '/../view/error/index.phtml',
            'layout/login'            => __DIR__ . '/../view/layout/login.phtml',
        ],
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
        'strategies' => [
            'ViewJsonStrategy', // Habilitar estrategia de vistas JSON
        ],
    ],
    
    // View helpers configuration
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