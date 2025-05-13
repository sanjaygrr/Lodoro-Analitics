<?php
// Script para ver datos específicos de una orden de Paris

// Inicializar autoloader y obtener la aplicación
require_once __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../config/container.php';

// Obtener el ID de la orden
$orderId = isset($_GET['id']) ? $_GET['id'] : "3010061470"; // ID por defecto

// Obtener servicios necesarios
$dbAdapter = $app->get('Laminas\Db\Adapter\AdapterInterface');

echo "<!DOCTYPE html>
<html>
<head>
    <title>Datos de Orden Paris - $orderId</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        h1, h2 { color: #333; }
        pre { background-color: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
        .box { border: 1px solid #ddd; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .success { color: green; }
        .error { color: red; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Datos de Orden Paris - $orderId</h1>";

try {
    // 1. Comprobar si la orden existe
    $checkQuery = "SELECT * FROM paris_orders WHERE subOrderNumber = ?";
    $checkStmt = $dbAdapter->query($checkQuery);
    $orderData = $checkStmt->execute([$orderId]);
    $order = $orderData->current();

    if (!$order) {
        echo "<p class='error'>Orden no encontrada en paris_orders</p>";
    } else {
        echo "<div class='box'>";
        echo "<h2>Información Básica de la Orden</h2>";
        echo "<table>";
        foreach ($order as $key => $value) {
            echo "<tr><th>$key</th><td>" . htmlspecialchars($value) . "</td></tr>";
        }
        echo "</table>";
        echo "</div>";
        
        // 2. Consultar suborden
        echo "<div class='box'>";
        echo "<h2>Información de SubOrden</h2>";
        try {
            $subOrderQuery = "SELECT * FROM paris_subOrders WHERE subOrderNumber = ?";
            $subOrderStmt = $dbAdapter->query($subOrderQuery);
            $subOrderData = $subOrderStmt->execute([$orderId]);
            $subOrder = $subOrderData->current();
            
            if ($subOrder) {
                echo "<table>";
                foreach ($subOrder as $key => $value) {
                    echo "<tr><th>$key</th><td>" . htmlspecialchars($value) . "</td></tr>";
                }
                echo "</table>";
            } else {
                echo "<p class='error'>No se encontró información de suborden</p>";
            }
        } catch (\Exception $e) {
            echo "<p class='error'>Error al consultar suborden: " . $e->getMessage() . "</p>";
        }
        echo "</div>";
        
        // 3. Consultar productos
        echo "<div class='box'>";
        echo "<h2>Productos</h2>";
        try {
            $productsQuery = "SELECT * FROM paris_items WHERE subOrderNumber = ?";
            $productsStmt = $dbAdapter->query($productsQuery);
            $productsData = $productsStmt->execute([$orderId]);
            
            $products = [];
            while ($row = $productsData->current()) {
                $products[] = $row;
                $productsData->next();
            }
            
            if (!empty($products)) {
                echo "<table>";
                echo "<tr>";
                foreach (array_keys((array)$products[0]) as $header) {
                    echo "<th>$header</th>";
                }
                echo "</tr>";
                
                foreach ($products as $product) {
                    echo "<tr>";
                    foreach ($product as $value) {
                        echo "<td>" . htmlspecialchars($value) . "</td>";
                    }
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "<p class='error'>No se encontraron productos para esta orden</p>";
                
                // Intentar alternativa
                try {
                    $altProductsQuery = "
                        SELECT * FROM bsale_references br 
                        JOIN bsale_document_details dd ON br.document_id = dd.document_id
                        WHERE br.number LIKE ?";
                    $altProductsStmt = $dbAdapter->query($altProductsQuery);
                    $altProductsData = $altProductsStmt->execute(['%' . $orderId . '%']);
                    
                    $altProducts = [];
                    while ($row = $altProductsData->current()) {
                        $altProducts[] = $row;
                        $altProductsData->next();
                    }
                    
                    if (!empty($altProducts)) {
                        echo "<h3>Productos Alternativos (bsale_document_details)</h3>";
                        echo "<table>";
                        echo "<tr>";
                        foreach (array_keys((array)$altProducts[0]) as $header) {
                            echo "<th>$header</th>";
                        }
                        echo "</tr>";
                        
                        foreach ($altProducts as $product) {
                            echo "<tr>";
                            foreach ($product as $value) {
                                echo "<td>" . htmlspecialchars($value) . "</td>";
                            }
                            echo "</tr>";
                        }
                        echo "</table>";
                    } else {
                        echo "<p class='error'>No se encontraron productos alternativos</p>";
                    }
                } catch (\Exception $e) {
                    echo "<p class='error'>Error al buscar productos alternativos: " . $e->getMessage() . "</p>";
                }
            }
        } catch (\Exception $e) {
            echo "<p class='error'>Error al consultar productos: " . $e->getMessage() . "</p>";
        }
        echo "</div>";
        
        // 4. Verificar si hay documento en bsale
        echo "<div class='box'>";
        echo "<h2>Información de Boleta</h2>";
        try {
            $boletaQuery = "
                SELECT bd.*
                FROM bsale_references br
                JOIN bsale_documents bd ON br.document_id = bd.id
                WHERE br.number LIKE ?";
            $boletaStmt = $dbAdapter->query($boletaQuery);
            $boletaData = $boletaStmt->execute(['%' . $orderId . '%']);
            
            $boleta = $boletaData->current();
            
            if ($boleta) {
                echo "<table>";
                foreach ($boleta as $key => $value) {
                    echo "<tr><th>$key</th><td>" . htmlspecialchars($value) . "</td></tr>";
                }
                echo "</table>";
            } else {
                echo "<p class='error'>No se encontró información de boleta</p>";
            }
        } catch (\Exception $e) {
            echo "<p class='error'>Error al consultar boleta: " . $e->getMessage() . "</p>";
        }
        echo "</div>";
    }
    
} catch (\Exception $e) {
    echo "<p class='error'>Error general: " . $e->getMessage() . "</p>";
}

echo "<p><a href='/check-paris.php'>Ver tablas disponibles</a></p>";
echo "</body></html>";