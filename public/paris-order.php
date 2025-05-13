<?php
/**
 * Script directo para manejar órdenes de Paris sin depender de Laminas router
 */

// Obtener el ID de la URL
$orderId = isset($_GET['id']) ? $_GET['id'] : null;

// Si no hay ID en GET, intentar extraerlo de la URL
if (!$orderId) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    
    // Extraer ID de patrones como /paris-order/123456
    if (preg_match('#/paris-order/(\d+)#', $uri, $matches)) {
        $orderId = $matches[1];
    }
}

error_log("paris-order.php: ID detectado = $orderId, URI: " . $_SERVER['REQUEST_URI']);

// Si no hay ID, mostrar error
if (!$orderId) {
    header('HTTP/1.1 400 Bad Request');
    echo '<div style="text-align: center; margin-top: 50px; font-family: Arial, sans-serif;">';
    echo '<h1>Error: ID de orden requerido</h1>';
    echo '<p>No se proporcionó un ID de orden válido.</p>';
    echo '<a href="/orders/orders-detail/Orders_PARIS" style="display: inline-block; margin-top: 20px; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;">Volver a órdenes</a>';
    echo '</div>';
    exit;
}

// Inicializar autoloader y obtener la aplicación
require_once __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../config/container.php';

// Obtener servicios necesarios
$dbAdapter = $app->get('Laminas\Db\Adapter\AdapterInterface');

try {
    error_log("Consultando detalles de orden Paris ID: $orderId");
    
    // Verificar primero que la orden existe
    $checkQuery = "SELECT subOrderNumber FROM paris_orders WHERE subOrderNumber = ?";
    $checkStmt = $dbAdapter->query($checkQuery);
    $checkResult = $checkStmt->execute([$orderId]);
    
    if (!$checkResult->current()) {
        throw new \Exception("No se encontró ninguna orden con ID $orderId en la tabla paris_orders");
    }
    
    error_log("Orden encontrada en paris_orders, obteniendo detalles completos");
    
    // Buscar información de boleta (usando la referencia principal)
    $boletaInfo = [];
    try {
        // Consulta para encontrar información de boleta
        $boletaQuery = "
            SELECT 
                bd.number AS numero_boleta,
                bd.totalAmount AS total,
                bd.netAmount AS neto,
                bd.taxAmount AS impuesto,
                bd.urlPdfOriginal
            FROM bsale_references br
            JOIN bsale_documents bd ON br.document_id = bd.id
            WHERE br.number LIKE ?
            LIMIT 1";
        $boletaStmt = $dbAdapter->query($boletaQuery);
        $boletaData = $boletaStmt->execute(['%' . $orderId . '%']);
        
        $boleta = $boletaData->current();
        if ($boleta) {
            $boletaInfo = $boleta;
            error_log("Boleta encontrada: " . $boleta['numero_boleta']);
        }
    } catch (\Exception $e) {
        error_log("Error al buscar boleta: " . $e->getMessage());
    }
    
    // Usamos la misma consulta que en OrdersController
    $orderQuery = "
        SELECT 
            pof.subOrderNumber as id,
            pof.subOrderNumber,
            pof.origin,
            pof.originInvoiceType,
            pof.createdAt as fecha_compra,
            pof.customer_name as cliente,
            pof.customer_documentNumber as documento,
            '' as telefono,
            '' as direccion,
            '' as direccion_envio,
            pso.statusId,
            COALESCE(pst.translate, 'Pendiente') as estado,
            pso.carrier as transportista,
            pso.fulfillment,
            pso.cost,
            " . (!empty($boletaInfo) ? $boletaInfo['impuesto'] : 0) . " as impuesto,
            " . (!empty($boletaInfo) ? $boletaInfo['total'] : 0) . " as total,
            '" . (!empty($boletaInfo) ? $boletaInfo['numero_boleta'] : '') . "' as numero_boleta,
            '" . (!empty($boletaInfo) ? $boletaInfo['urlPdfOriginal'] : '') . "' as url_pdf_boleta,
            '' AS numero_liquidacion,
            0 AS monto_liquidacion,
            COALESCE(pof.orden_impresa, 0) AS printed,
            COALESCE(pof.orden_procesada, 0) AS procesado
        FROM paris_orders pof
        LEFT JOIN paris_subOrders pso
            ON pof.subOrderNumber = pso.subOrderNumber
        LEFT JOIN paris_statuses pst
            ON pso.statusId = pst.id
        WHERE pof.subOrderNumber = ?
        LIMIT 1";
    
    $stmt = $dbAdapter->query($orderQuery);
    $orderResult = $stmt->execute([$orderId]);
    $order = $orderResult->current();
    
    if (!$order) {
        throw new \Exception('Orden no encontrada: ' . $orderId);
    }
    
    // CONSULTA FINAL PROPORCIONADA
    error_log("Aplicando la consulta SQL proporcionada para obtener datos de productos Paris");
    
    // Obtener información completa de la orden y productos en una sola consulta
    $orderInfo = null;
    try {
        $orderQuery = "
            SELECT
              o.subOrderNumber AS suborden,
              o.customer_name AS cliente,
              o.customer_documentNumber AS rut,
              o.billing_phone AS telefono,
              GROUP_CONCAT(TRIM(SUBSTRING_INDEX(ddet.variant_code, ',', -1)) SEPARATOR ', ') AS skus_bsale,
              GROUP_CONCAT(ddet.variant_description SEPARATOR ' | ') AS productos_bsale,
              GROUP_CONCAT(ddet.quantity SEPARATOR ', ') AS cantidades,
              o.createdAt AS fecha_creacion,
              so.effectiveArrivalDate AS fecha_entrega,
              so.fulfillment,
              so.labelUrl,
              o.orden_impresa,
              o.orden_procesada,
              st.translate AS estado,
              doc.id AS id_boleta,
              doc.number AS numero_boleta,
              doc.totalAmount AS total_boleta,
              doc.taxAmount AS impuesto_boleta,
              doc.urlPdf AS link_boleta,
              doc.urlPdfOriginal AS url_pdf_boleta
            FROM
              paris_orders o
            LEFT JOIN paris_subOrders so
              ON o.subOrderNumber = so.subOrderNumber
            LEFT JOIN paris_statuses st
              ON so.statusId = st.id
            LEFT JOIN (
              SELECT 
                document_id,
                number,
                REGEXP_SUBSTR(number, '[0-9]{10}') AS subOrderNumber_clean
              FROM bsale_references
              WHERE number REGEXP '[0-9]{10}'
            ) ref
              ON ref.subOrderNumber_clean = o.subOrderNumber
            LEFT JOIN bsale_documents doc
              ON doc.id = ref.document_id
            LEFT JOIN bsale_document_details ddet
              ON ddet.document_id = doc.id
            WHERE o.subOrderNumber = ?
            GROUP BY
              o.subOrderNumber";
        
        $orderStmt = $dbAdapter->query($orderQuery);
        $orderResult = $orderStmt->execute([$orderId]);
        $orderInfo = $orderResult->current();
        
        if ($orderInfo) {
            error_log("Información completa de la orden obtenida correctamente");
            
            // Obtener los productos individuales a partir de los datos agrupados
            $skus = explode(', ', $orderInfo['skus_bsale'] ?? '');
            $productos = explode(' | ', $orderInfo['productos_bsale'] ?? '');
            $cantidades = explode(', ', $orderInfo['cantidades'] ?? '');
            
            // Establecer valores de la orden
            $order['estado'] = $orderInfo['estado'] ?? 'Pendiente';
            $order['total'] = $orderInfo['total_boleta'] ?? $order['total'] ?? 0;
            $order['impuesto'] = $orderInfo['impuesto_boleta'] ?? $order['impuesto'] ?? 0;
            $order['numero_boleta'] = $orderInfo['numero_boleta'] ?? $order['numero_boleta'] ?? '';
            $order['url_pdf_boleta'] = $orderInfo['url_pdf_boleta'] ?? $order['url_pdf_boleta'] ?? '';
            
            // Crear productos individuales
            $products = [];
            $productCount = min(count($skus), count($productos), count($cantidades));
            
            if ($productCount > 0) {
                // Calcular precio unitario basado en el total de la boleta
                $totalAmount = floatval($orderInfo['total_boleta'] ?? 0);
                $totalQuantity = array_sum(array_map('intval', $cantidades));
                $unitPrice = ($totalQuantity > 0 && $totalAmount > 0) ? ($totalAmount / $totalQuantity) : 0;
                
                for ($i = 0; $i < $productCount; $i++) {
                    $quantity = intval($cantidades[$i] ?? 1);
                    $products[] = [
                        'sku' => $skus[$i] ?? '',
                        'nombre' => $productos[$i] ?? '',
                        'cantidad' => $quantity,
                        'precio_unitario' => $unitPrice,
                        'subtotal' => $unitPrice * $quantity,
                        'procesado' => $orderInfo['orden_procesada'] ?? 0,
                        'estado' => $orderInfo['estado'] ?? 'Pendiente'
                    ];
                }
                error_log("Creados " . count($products) . " productos a partir de la consulta agrupada");
            }
        }
    } catch (\Exception $e) {
        error_log("Error al consultar información completa: " . $e->getMessage());
    }
    
    // Si no hay productos, consultar directamente los detalles del documento
    if (empty($products)) {
        try {
            error_log("Intentando consulta de detalles de documentos...");
            
            $detailsQuery = "
                SELECT 
                    ddet.variant_code as sku,
                    ddet.variant_description as nombre,
                    ddet.quantity as cantidad,
                    ddet.net_unit_value as precio_unitario,
                    ddet.total_amount as subtotal,
                    o.orden_procesada as procesado,
                    COALESCE(st.translate, 'Pendiente') as estado
                FROM bsale_references ref
                JOIN bsale_documents doc ON ref.document_id = doc.id
                JOIN bsale_document_details ddet ON doc.id = ddet.document_id
                JOIN paris_orders o ON ref.number LIKE CONCAT('%', o.subOrderNumber, '%')
                LEFT JOIN paris_subOrders so ON o.subOrderNumber = so.subOrderNumber
                LEFT JOIN paris_statuses st ON so.statusId = st.id
                WHERE o.subOrderNumber = ?";
            
            $detailsStmt = $dbAdapter->query($detailsQuery);
            $detailsResult = $detailsStmt->execute([$orderId]);
            
            while ($row = $detailsResult->current()) {
                $products[] = $row;
                $detailsResult->next();
            }
            
            error_log("Encontrados " . count($products) . " productos con consulta directa");
        } catch (\Exception $e) {
            error_log("Error en consulta de detalles: " . $e->getMessage());
        }
    }
    
    // Como último recurso, crear un producto genérico
    if (empty($products)) {
        error_log("Último recurso: Creando producto genérico");
        
        // Crear un producto genérico con la información disponible
        $products[] = [
            'sku' => 'SKU-PARIS',
            'nombre' => 'Producto Paris',
            'cantidad' => 1,
            'precio_unitario' => $order['total'] ?? 0,
            'subtotal' => $order['total'] ?? 0,
            'procesado' => $order['procesado'] ?? 0,
            'estado' => $order['estado'] ?? 'Pendiente'
        ];
    }
    
    // Formatear los productos para que tengan la estructura esperada
    $formattedProducts = [];
    
    // Estado de la orden
    $orderStatus = $order['estado'] ?? 'Pendiente';
    $orderProcesado = $order['procesado'] ?? 0;
    error_log("Estado de la orden: $orderStatus, Procesado: $orderProcesado");
    
    // Formatear productos (simplificado para evitar cálculos innecesarios)
    foreach ($products as $index => $product) {
        $formattedProducts[] = [
            'id' => $index + 1,
            'sku' => $product['sku'] ?? '',
            'nombre' => $product['nombre'] ?? '',
            'cantidad' => $product['cantidad'] ?? 1,
            'precio_unitario' => $product['precio_unitario'] ?? 0,
            'subtotal' => $product['subtotal'] ?? ($product['precio_unitario'] * $product['cantidad']),
            'status' => $product['estado'] ?? $orderStatus,
            'procesado' => $product['procesado'] ?? $orderProcesado
        ];
        
        error_log("Producto final: " . json_encode($formattedProducts[$index]));
    }
    
    // Calcular valores financieros
    $total = 0;
    $impuesto = 0;
    $envio = 0;
    $subtotal = 0;
    
    // Calcular el total desde el orden si está disponible
    if (isset($order['total']) && !empty($order['total'])) {
        $total = floatval($order['total']);
        error_log("Usando total de la orden: $total");
    }
    
    // Si hay costo de envío en la orden, lo usamos
    if (isset($order['cost']) && !empty($order['cost'])) {
        $envio = floatval($order['cost']);
    }
    
    // Si hay impuesto en la orden, lo usamos
    if (isset($order['impuesto']) && !empty($order['impuesto'])) {
        $impuesto = floatval($order['impuesto']);
    }
    
    // Calculamos el subtotal (total - impuesto - envío)
    $subtotal = $total - $impuesto - $envio;
    
    error_log("Valores financieros: total=$total, impuesto=$impuesto, envio=$envio, subtotal=$subtotal");
    
    // Preparar información del cliente
    $clientInfo = [
        'nombre' => $order['cliente'] ?? 'Cliente',
        'rut' => $order['documento'] ?? '',
        'telefono' => $order['telefono'] ?? '',
        'direccion' => $order['direccion'] ?? $order['direccion_envio'] ?? '',
        'comuna' => '',
        'region' => '',
        'email' => '',
    ];
    
    // Información de entrega
    $deliveryInfo = [
        'transportista' => $order['transportista'] ?? 'Sin asignar',
        'tracking' => '',
        'fecha_entrega' => '',
    ];
    
    // Estructura para el marketplace
    $marketplace = 'PARIS';
    $marketplaceColors = [
        'WALLMART' => '#0071ce',
        'RIPLEY' => '#e60000',
        'FALABELLA' => '#0a4a90',
        'MERCADO_LIBRE' => '#ffe600',
        'PARIS' => '#e71785',
        'WOOCOMMERCE' => '#7f54b3'
    ];
    $marketplaceColor = $marketplaceColors[$marketplace] ?? '#4361ee';
    
    // Cargar la plantilla del detalle de la orden
    include __DIR__ . '/../module/Application/view/application/orders/direct-paris-order.phtml';

} catch (\Exception $e) {
    error_log("Error en paris-order.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Mostrar página de error amigable
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