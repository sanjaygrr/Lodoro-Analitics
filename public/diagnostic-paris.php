<?php
/**
 * Script de diagnóstico para productos Paris
 */

// Inicializar autoloader y obtener la aplicación
require_once __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../config/container.php';

// Obtener servicios necesarios
$dbAdapter = $app->get('Laminas\Db\Adapter\AdapterInterface');

// ID de orden para prueba (usar GET o uno por defecto)
$orderId = isset($_GET['id']) ? $_GET['id'] : '3010025590';

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html>
<html>
<head>
    <title>Diagnóstico Productos Paris #' . htmlspecialchars($orderId) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1, h2 { color: #333; }
        pre { background-color: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
        .box { border: 1px solid #ddd; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .success { color: green; }
        .error { color: red; }
    </style>
</head>
<body>
    <h1>Diagnóstico Productos Paris - Orden #' . htmlspecialchars($orderId) . '</h1>';

try {
    echo '<div class="box">';
    echo '<h2>1. Verificando tablas</h2>';
    
    // Buscar tablas que podrían tener productos de Paris
    $tables = ['paris_items', 'paris_orders', 'paris_subOrders', 'bsale_document_details'];
    
    foreach ($tables as $table) {
        try {
            $query = "SHOW TABLES LIKE '$table'";
            $stmt = $dbAdapter->query($query);
            $result = $stmt->execute();
            $exists = $result->current() !== false;
            
            echo '<p>' . $table . ': ' . ($exists ? '<span class="success">Existe</span>' : '<span class="error">No existe</span>') . '</p>';
            
            if ($exists) {
                // Mostrar estructura de la tabla
                $structureQuery = "DESCRIBE $table";
                $structureStmt = $dbAdapter->query($structureQuery);
                $columns = $structureStmt->execute();
                
                echo '<h3>Estructura de ' . $table . ':</h3>';
                echo '<ul>';
                while ($column = $columns->current()) {
                    echo '<li>' . $column['Field'] . ' - ' . $column['Type'] . '</li>';
                    $columns->next();
                }
                echo '</ul>';
            }
        } catch (\Exception $e) {
            echo '<p class="error">Error al verificar tabla ' . $table . ': ' . $e->getMessage() . '</p>';
        }
    }
    echo '</div>';
    
    // Buscar productos en paris_items
    echo '<div class="box">';
    echo '<h2>2. Buscando productos en paris_items</h2>';
    
    try {
        $query = "SELECT * FROM paris_items WHERE subOrderNumber = ? LIMIT 10";
        $stmt = $dbAdapter->query($query);
        $results = $stmt->execute([$orderId]);
        
        $hasProducts = false;
        while ($row = $results->current()) {
            $hasProducts = true;
            echo '<h3>Producto encontrado:</h3>';
            echo '<pre>' . print_r($row, true) . '</pre>';
            $results->next();
        }
        
        if (!$hasProducts) {
            echo '<p class="error">No se encontraron productos para la orden ' . htmlspecialchars($orderId) . ' en paris_items</p>';
        }
    } catch (\Exception $e) {
        echo '<p class="error">Error al buscar en paris_items: ' . $e->getMessage() . '</p>';
    }
    echo '</div>';
    
    // Buscar productos en bsale_document_details
    echo '<div class="box">';
    echo '<h2>3. Buscando productos en bsale_document_details</h2>';
    
    try {
        $query = "
            SELECT ddet.* 
            FROM bsale_references bref
            JOIN bsale_documents bdoc ON bref.document_id = bdoc.id
            JOIN bsale_document_details ddet ON bdoc.id = ddet.document_id
            WHERE bref.number LIKE ?
            LIMIT 10";
        $stmt = $dbAdapter->query($query);
        $results = $stmt->execute(['%' . $orderId . '%']);
        
        $hasProducts = false;
        while ($row = $results->current()) {
            $hasProducts = true;
            echo '<h3>Producto encontrado:</h3>';
            echo '<pre>' . print_r($row, true) . '</pre>';
            $results->next();
        }
        
        if (!$hasProducts) {
            echo '<p class="error">No se encontraron productos para la orden ' . htmlspecialchars($orderId) . ' en bsale_document_details</p>';
        }
    } catch (\Exception $e) {
        echo '<p class="error">Error al buscar en bsale_document_details: ' . $e->getMessage() . '</p>';
    }
    echo '</div>';
    
    // Consultar información de la orden en paris_orders
    echo '<div class="box">';
    echo '<h2>4. Información de la orden en paris_orders</h2>';
    
    try {
        $query = "SELECT * FROM paris_orders WHERE subOrderNumber = ?";
        $stmt = $dbAdapter->query($query);
        $result = $stmt->execute([$orderId]);
        $orderData = $result->current();
        
        if ($orderData) {
            echo '<pre>' . print_r($orderData, true) . '</pre>';
        } else {
            echo '<p class="error">No se encontró la orden ' . htmlspecialchars($orderId) . ' en paris_orders</p>';
        }
    } catch (\Exception $e) {
        echo '<p class="error">Error al consultar paris_orders: ' . $e->getMessage() . '</p>';
    }
    echo '</div>';
    
    // Consultar información de la suborden en paris_subOrders
    echo '<div class="box">';
    echo '<h2>5. Información de la suborden en paris_subOrders</h2>';
    
    try {
        $query = "SELECT * FROM paris_subOrders WHERE subOrderNumber = ?";
        $stmt = $dbAdapter->query($query);
        $result = $stmt->execute([$orderId]);
        $subOrderData = $result->current();
        
        if ($subOrderData) {
            echo '<pre>' . print_r($subOrderData, true) . '</pre>';
        } else {
            echo '<p class="error">No se encontró la suborden ' . htmlspecialchars($orderId) . ' en paris_subOrders</p>';
        }
    } catch (\Exception $e) {
        echo '<p class="error">Error al consultar paris_subOrders: ' . $e->getMessage() . '</p>';
    }
    echo '</div>';
} catch (\Exception $e) {
    echo '<h2 class="error">Error general</h2>';
    echo '<p>' . $e->getMessage() . '</p>';
}

echo '<p><a href="/paris-order.php?id=' . htmlspecialchars($orderId) . '">Ver detalle de orden</a></p>';
echo '</body></html>';