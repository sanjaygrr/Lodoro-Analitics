<?php
/**
 * Script para verificar las columnas disponibles en las tablas Paris
 */

// Inicializar autoloader y obtener la aplicación
require_once __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../config/container.php';

// Obtener servicios necesarios
$dbAdapter = $app->get('Laminas\Db\Adapter\AdapterInterface');

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html>
<html>
<head>
    <title>Verificación de Columnas</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1, h2 { color: #333; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .success { color: green; }
        .warning { color: orange; }
        .error { color: red; }
    </style>
</head>
<body>
    <h1>Verificación de Columnas en Tablas Paris</h1>';

try {
    $tables = [
        'paris_orders',
        'paris_subOrders',
        'paris_items',
        'paris_statuses',
        'bsale_references',
        'bsale_documents',
        'bsale_document_details'
    ];
    
    foreach ($tables as $table) {
        try {
            echo "<h2>Tabla: $table</h2>";
            
            // Verificar si la tabla existe
            $checkTableQuery = "SHOW TABLES LIKE '$table'";
            $checkTableStmt = $dbAdapter->query($checkTableQuery);
            $tableExists = $checkTableStmt->execute()->current();
            
            if (!$tableExists) {
                echo "<p class='error'>La tabla no existe</p>";
                continue;
            }
            
            // Mostrar columnas de la tabla
            $columnsQuery = "DESCRIBE $table";
            $columnsStmt = $dbAdapter->query($columnsQuery);
            $columns = $columnsStmt->execute();
            
            echo "<table>";
            echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Clave</th><th>Default</th><th>Extra</th></tr>";
            
            while ($column = $columns->current()) {
                echo "<tr>";
                echo "<td>" . $column['Field'] . "</td>";
                echo "<td>" . $column['Type'] . "</td>";
                echo "<td>" . $column['Null'] . "</td>";
                echo "<td>" . $column['Key'] . "</td>";
                echo "<td>" . $column['Default'] . "</td>";
                echo "<td>" . $column['Extra'] . "</td>";
                echo "</tr>";
                $columns->next();
            }
            
            echo "</table>";
            
            // Mostrar 5 registros de muestra
            $sampleQuery = "SELECT * FROM $table LIMIT 1";
            $sampleStmt = $dbAdapter->query($sampleQuery);
            $sample = $sampleStmt->execute();
            $record = $sample->current();
            
            if ($record) {
                echo "<h3>Muestra de Datos</h3>";
                echo "<table>";
                echo "<tr>";
                foreach (array_keys((array)$record) as $field) {
                    echo "<th>" . $field . "</th>";
                }
                echo "</tr>";
                
                echo "<tr>";
                foreach ($record as $value) {
                    echo "<td>" . (is_null($value) ? "NULL" : htmlspecialchars(substr((string)$value, 0, 50)) . (strlen((string)$value) > 50 ? "..." : "")) . "</td>";
                }
                echo "</tr>";
                echo "</table>";
            } else {
                echo "<p class='warning'>No hay datos en esta tabla</p>";
            }
            
        } catch (\Exception $e) {
            echo "<p class='error'>Error al consultar la tabla $table: " . $e->getMessage() . "</p>";
        }
    }
    
    // Verificar columnas específicas mencionadas en el controlador
    echo "<h2>Verificación de Columnas Específicas</h2>";
    $columnsToCheck = [
        'paris_orders' => ['billing_phone', 'billing_address', 'shipping_address'],
        'paris_items' => ['status', 'statusId']
    ];
    
    foreach ($columnsToCheck as $table => $columns) {
        echo "<h3>Verificando columnas en $table</h3>";
        echo "<ul>";
        
        foreach ($columns as $column) {
            try {
                $checkQuery = "SELECT COUNT($column) FROM $table LIMIT 1";
                $checkStmt = $dbAdapter->query($checkQuery);
                $checkStmt->execute();
                echo "<li class='success'>$column <strong>EXISTE</strong> en la tabla $table</li>";
            } catch (\Exception $e) {
                echo "<li class='error'>$column <strong>NO EXISTE</strong> en la tabla $table: " . $e->getMessage() . "</li>";
            }
        }
        
        echo "</ul>";
    }
    
} catch (\Exception $e) {
    echo "<p class='error'>Error general: " . $e->getMessage() . "</p>";
}

echo '</body></html>';