<?php
// Script para comprobar la estructura y datos de las tablas de Paris

// Inicializar autoloader y obtener la aplicación
require_once __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../config/container.php';

// Obtener el adaptador de la base de datos
$dbAdapter = $app->get('Laminas\Db\Adapter\AdapterInterface');

// Función para mostrar el resultado de una consulta
function showResults($title, $results) {
    echo "<h2>$title</h2>";
    
    if (empty($results)) {
        echo "<p>No se encontraron resultados</p>";
        return;
    }
    
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; margin-bottom: 20px;'>";
    
    // Encabezados de columna
    echo "<tr style='background-color: #f2f2f2;'>";
    foreach (array_keys((array)$results[0]) as $column) {
        echo "<th>$column</th>";
    }
    echo "</tr>";
    
    // Datos
    foreach ($results as $row) {
        echo "<tr>";
        foreach ((array)$row as $value) {
            if (is_null($value)) {
                echo "<td><em>NULL</em></td>";
            } else {
                echo "<td>" . htmlspecialchars($value) . "</td>";
            }
        }
        echo "</tr>";
    }
    
    echo "</table>";
}

// HTML básico
echo "<!DOCTYPE html>
<html>
<head>
    <title>Verificación de Tablas Paris</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1, h2 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background-color: #f2f2f2; text-align: left; }
        td, th { padding: 8px; border: 1px solid #ddd; }
        .code { font-family: monospace; background-color: #f8f8f8; padding: 10px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>Verificación de Tablas Paris</h1>";

try {
    // 1. Comprobar si las tablas existen
    echo "<h2>Tablas relacionadas con Paris</h2>";
    try {
        $tablesQuery = "SHOW TABLES LIKE 'paris%'";
        $stmt = $dbAdapter->query($tablesQuery);
        $results = $stmt->execute();
        
        $tables = [];
        echo "<ul>";
        while ($row = $results->current()) {
            $table = reset($row); // Obtener el primer valor del array asociativo
            $tables[] = $table;
            echo "<li>$table</li>";
            $results->next();
        }
        echo "</ul>";
        
        if (empty($tables)) {
            echo "<p>No se encontraron tablas relacionadas con Paris</p>";
        }
    } catch (\Exception $e) {
        echo "<p style='color: red;'>Error al buscar tablas: " . $e->getMessage() . "</p>";
    }
    
    // 2. Verificar estructura de paris_orders (si existe)
    if (in_array('paris_orders', $tables)) {
        try {
            echo "<h2>Estructura de paris_orders</h2>";
            $describeQuery = "DESCRIBE paris_orders";
            $stmt = $dbAdapter->query($describeQuery);
            $columns = $stmt->execute();
            
            $columnsData = [];
            while ($col = $columns->current()) {
                $columnsData[] = $col;
                $columns->next();
            }
            
            showResults("Columnas de paris_orders", $columnsData);
        } catch (\Exception $e) {
            echo "<p style='color: red;'>Error al describir paris_orders: " . $e->getMessage() . "</p>";
        }
        
        // 3. Mostrar algunos datos de ejemplo
        try {
            $sampleQuery = "SELECT * FROM paris_orders LIMIT 3";
            $stmt = $dbAdapter->query($sampleQuery);
            $results = $stmt->execute();
            
            $rows = [];
            while ($row = $results->current()) {
                $rows[] = $row;
                $results->next();
            }
            
            showResults("Muestra de datos de paris_orders", $rows);
        } catch (\Exception $e) {
            echo "<p style='color: red;'>Error al consultar datos de paris_orders: " . $e->getMessage() . "</p>";
        }
    }
    
    // 4. Verificar estructura de paris_subOrders (si existe)
    if (in_array('paris_subOrders', $tables)) {
        try {
            echo "<h2>Estructura de paris_subOrders</h2>";
            $describeQuery = "DESCRIBE paris_subOrders";
            $stmt = $dbAdapter->query($describeQuery);
            $columns = $stmt->execute();
            
            $columnsData = [];
            while ($col = $columns->current()) {
                $columnsData[] = $col;
                $columns->next();
            }
            
            showResults("Columnas de paris_subOrders", $columnsData);
        } catch (\Exception $e) {
            echo "<p style='color: red;'>Error al describir paris_subOrders: " . $e->getMessage() . "</p>";
        }
    }
    
    // 5. Verificar estructura de paris_items (si existe)
    if (in_array('paris_items', $tables)) {
        try {
            echo "<h2>Estructura de paris_items</h2>";
            $describeQuery = "DESCRIBE paris_items";
            $stmt = $dbAdapter->query($describeQuery);
            $columns = $stmt->execute();
            
            $columnsData = [];
            while ($col = $columns->current()) {
                $columnsData[] = $col;
                $columns->next();
            }
            
            showResults("Columnas de paris_items", $columnsData);
        } catch (\Exception $e) {
            echo "<p style='color: red;'>Error al describir paris_items: " . $e->getMessage() . "</p>";
        }
    }
    
    // 6. Ejemplo de la consulta para las órdenes de Paris
    echo "<h2>Consulta SQL recomendada</h2>";
    echo "<pre class='code'>
SELECT 
    pof.subOrderNumber as id,
    pof.subOrderNumber,
    pof.origin,
    pof.originInvoiceType,
    pof.createdAt as fecha_compra,
    pof.customer_name as cliente,
    pof.customer_documentNumber as documento,
    pof.phone as telefono,
    pof.address as direccion,
    pof.commune as direccion_envio,
    pso.statusId,
    pst.translate as estado,
    pso.carrier as transportista,
    pso.fulfillment,
    pso.cost,
    bd.taxAmount as impuesto,
    bd.totalAmount as total,
    bd.number as numero_boleta,
    bd.urlPdfOriginal as url_pdf_boleta,
    pp.numero AS numero_liquidacion,
    pp.monto AS monto_liquidacion,
    COALESCE(pof.orden_impresa, 0) AS printed,
    COALESCE(pof.orden_procesada, 0) AS procesado
FROM paris_orders pof
LEFT JOIN paris_subOrders pso
    ON pof.subOrderNumber = pso.subOrderNumber
LEFT JOIN paris_statuses pst
    ON pso.statusId = pst.id
LEFT JOIN (
    SELECT 
        document_id,
        number,
        REGEXP_SUBSTR(number, '[0-9]{10}') AS subOrderNumber_clean
    FROM bsale_references
    WHERE number REGEXP '[0-9]{10}'
) ref
    ON ref.subOrderNumber_clean = pof.subOrderNumber
LEFT JOIN bsale_documents bd
    ON bd.id = ref.document_id
LEFT JOIN paris_pagos pp
    ON DATE(pp.fecha) >= DATE(pof.createdAt)
WHERE pof.subOrderNumber = '3010061470'
LIMIT 1;
</pre>";

} catch (\Exception $e) {
    echo "<h2 style='color: red;'>Error general</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}

echo "</body></html>";