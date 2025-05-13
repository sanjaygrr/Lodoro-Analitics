<?php
/**
 * Script para diagnosticar problemas de enrutamiento
 */

// Inicializar autoloader y obtener la aplicación
require_once __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../config/container.php';

// Obtener el router
$router = $app->get('router');

// Mostrar todas las rutas definidas
$routes = $router->getRoutes();

echo '<h1>Rutas definidas</h1>';
echo '<pre>';

foreach ($routes as $name => $route) {
    echo "<h3>Route: $name</h3>";
    echo "Pattern: " . $route->getOptions()['route'] . "\n";
    
    // Ver parámetros por defecto
    echo "Defaults: ";
    print_r($route->getOptions()['defaults'] ?? []);
    
    echo "Constraints: ";
    print_r($route->getOptions()['constraints'] ?? []);
    
    // Mostrar rutas hijas
    if (method_exists($route, 'getRoutes')) {
        $childRoutes = $route->getRoutes();
        if (!empty($childRoutes)) {
            echo "Child Routes:\n";
            foreach ($childRoutes as $childName => $childRoute) {
                echo "  - $childName: " . $childRoute->getOptions()['route'] . "\n";
            }
        }
    }
    
    echo "\n----------------------\n\n";
}

// Revisar la resolución de algunas rutas de prueba
$testRoutes = [
    '/paris-order/3010061470',
    '/paris-order',
    '/orders/orders-detail/Orders_PARIS',
    '/order-detail/123/Orders_PARIS'
];

echo '<h1>Prueba de resolución de rutas</h1>';

foreach ($testRoutes as $path) {
    $match = $router->match(new \Laminas\Http\Request(
        \Laminas\Uri\Http::fromString('http://localhost' . $path)
    ));
    
    echo "<h3>Testing: $path</h3>";
    if ($match) {
        echo "¡Coincidencia encontrada!<br>";
        echo "Route matched: " . $match->getMatchedRouteName() . "<br>";
        echo "Controller: " . ($match->getParam('controller') ?? 'No controller') . "<br>";
        echo "Action: " . ($match->getParam('action') ?? 'No action') . "<br>";
        echo "Parameters: <pre>";
        print_r($match->getParams());
        echo "</pre>";
    } else {
        echo "<span style='color:red'>¡No se encontró coincidencia!</span><br>";
    }
    
    echo "\n----------------------\n\n";
}

echo '</pre>';