<?php
/**
 * Manejador alternativo para órdenes de Paris
 * Este archivo sirve como fallback si la ruta normal de Laminas falla
 */

// Inicializar autoloader y obtener la aplicación
require_once __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../config/container.php';

// Iniciar sesión para poder usar $_SESSION
session_start();

// Obtener el ID de la orden
$orderId = isset($_GET['id']) ? $_GET['id'] : null;

if (!$orderId) {
    die('ID de orden requerido');
}

// Obtener servicios necesarios del contenedor
$dbAdapter = $app->get('Laminas\Db\Adapter\AdapterInterface');
$databaseService = $app->get('Application\Service\DatabaseService');

try {
    // Log para depuración
    error_log("Usando paris-order-handler.php como fallback para ID: $orderId");
    
    // Consulta directa para obtener información de la orden
    $orderQuery = "
        SELECT 
            pof.subOrderNumber as id,
            pof.subOrderNumber,
            pof.origin,
            pof.originInvoiceType,
            pof.createdAt as fecha_compra,
            pof.customer_name as cliente,
            pof.customer_documentNumber as documento,
            pof.billing_phone as telefono,
            pof.billing_address as direccion,
            pof.shipping_address as direccion_envio,
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
        WHERE pof.subOrderNumber = ?
        LIMIT 1";
    
    $stmt = $dbAdapter->query($orderQuery);
    $orderResult = $stmt->execute([$orderId]);
    $order = $orderResult->current();
    
    if (!$order) {
        throw new \Exception('Orden no encontrada: ' . $orderId);
    }
    
    // Consulta para obtener productos
    $productsQuery = "
        SELECT 
            pi.sku,
            pi.name as nombre,
            pi.priceAfterDiscounts as precio,
            pi.quantity as cantidad,
            (pi.priceAfterDiscounts * pi.quantity) as subtotal
        FROM paris_items pi 
        WHERE pi.subOrderNumber = ?";
    
    $stmtProducts = $dbAdapter->query($productsQuery);
    $productsResult = $stmtProducts->execute([$orderId]);
    
    $products = [];
    while ($row = $productsResult->current()) {
        $products[] = $row;
        $productsResult->next();
    }
    
    // Si no hay productos, intentar consulta alternativa
    if (empty($products)) {
        try {
            $altProductsQuery = "
                SELECT 
                    ddet.variant_code as sku,
                    ddet.variant_description as nombre,
                    ddet.quantity as cantidad,
                    0 as precio,
                    0 as subtotal
                FROM bsale_references brd
                INNER JOIN bsale_documents doc
                    ON brd.document_id = doc.id
                INNER JOIN bsale_document_details ddet
                    ON doc.id = ddet.document_id
                WHERE brd.number LIKE ?";
                
            $stmtAltProducts = $dbAdapter->query($altProductsQuery);
            $altProductsResult = $stmtAltProducts->execute(['%' . $orderId . '%']);
            
            while ($row = $altProductsResult->current()) {
                $products[] = $row;
                $altProductsResult->next();
            }
            
            // Calcular precio unitario con el total
            if (!empty($products) && !empty($order['total'])) {
                $totalQuantity = 0;
                foreach ($products as &$product) {
                    $totalQuantity += intval($product['cantidad']);
                }
                
                if ($totalQuantity > 0) {
                    $unitPrice = floatval($order['total']) / $totalQuantity;
                    foreach ($products as &$product) {
                        $product['precio'] = $unitPrice;
                        $product['subtotal'] = $unitPrice * intval($product['cantidad']);
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignorar errores, seguiremos con el array vacío de productos
            error_log("Error en productos alternativos: " . $e->getMessage());
        }
    }
    
    // Si todavía no hay productos, crear uno genérico
    if (empty($products)) {
        $products[] = [
            'sku' => 'PRODUCTOS_PARIS',
            'nombre' => 'Productos de París',
            'cantidad' => 1,
            'precio' => $order['total'] ?? 0,
            'subtotal' => $order['total'] ?? 0
        ];
    }
    
    // Calcular datos financieros
    $total = floatval($order['total'] ?? 0);
    $impuesto = floatval($order['impuesto'] ?? 0);
    $envio = floatval($order['cost'] ?? 0);
    $subtotal = $total - $impuesto - $envio;
    
    // Preparar cliente
    $clientInfo = [
        'nombre' => $order['cliente'] ?? 'Cliente',
        'rut' => $order['documento'] ?? '',
        'telefono' => $order['telefono'] ?? '',
        'direccion' => $order['direccion'] ?? $order['direccion_envio'] ?? '',
    ];
    
    // Logs para depuración
    error_log("Orden París encontrada: ID $orderId");
    error_log("Total productos: " . count($products));
    
    // Redirigir a la URL correcta de Laminas
    header("Location: /paris-order/detail/$orderId");
    exit;
    
} catch (\Exception $e) {
    error_log("Error en paris-order-handler.php: " . $e->getMessage());
    
    echo '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - Orden no encontrada</title>
    <link rel="stylesheet" href="/css/bootstrap.min.css">
</head>
<body class="p-4">
    <div class="container">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="alert alert-danger">
                    <h4>Error al procesar la orden</h4>
                    <p>' . $e->getMessage() . '</p>
                    <div class="mt-3">
                        <a href="/orders/orders-detail/Orders_PARIS" class="btn btn-primary">
                            <i class="bi bi-arrow-left"></i> Volver a la lista de órdenes
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>';
}