<?php
/**
 * OrdersController para la gestión de pedidos
 */

namespace Application\Controller;

use Dompdf\Dompdf;
use Dompdf\Options;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Picqer\Barcode\BarcodeGeneratorPNG;
use setasign\Fpdi\Tcpdf\Fpdi;

class OrdersController extends BaseController
{
    /** @var \Application\Service\DatabaseService */
    private $databaseService;

    /**
     * Constructor
     *
     * @param \Laminas\Db\Adapter\AdapterInterface $dbAdapter
     * @param \Laminas\Authentication\AuthenticationService $authService
     * @param \Application\Service\DatabaseService $databaseService
     */
    public function __construct(
        \Laminas\Db\Adapter\AdapterInterface $dbAdapter,
        \Laminas\Authentication\AuthenticationService $authService,
        \Application\Service\DatabaseService $databaseService
    ) {
        parent::__construct($dbAdapter, $authService);
        $this->databaseService = $databaseService;
    }

    /**
     * Acción para mostrar órdenes de un marketplace específico
     *
     * @return ViewModel|\Laminas\Http\Response
     */
    public function indexAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }

        // Obtener tabla desde el parámetro de ruta
        $table = $this->params()->fromRoute('table', null);

        if (empty($table)) {
            return $this->notFoundAction();
        }

        // Configuración de paginación
        $page = (int) $this->params()->fromQuery('page', 1);
        $limit = (int) $this->params()->fromQuery('limit', 25);

        // Filtros disponibles
        $filters = [];
        // Filtro de búsqueda
        $search = $this->params()->fromQuery('search', '');
        if (!empty($search)) {
            $filters['search'] = $search;
        }

        // Filtro de estado
        $status = $this->params()->fromQuery('status', '');
        if (!empty($status)) {
            $filters['status'] = $status;
        }

        // Filtro de impresión
        $printed = $this->params()->fromQuery('printed', '');
        if ($printed !== '') {
            $filters['printed'] = $printed;
        }

        // Identificar el marketplace y usar consulta directa si es necesario
        $marketplaceId = strtoupper($table);
        
        switch ($marketplaceId) {
            case 'ORDERS_PARIS':
                return $this->handleParisOrders($page, $limit, $filters);
                
            case 'ORDERS_FALABELLA':
                // return $this->handleFalabellaOrders($page, $limit, $filters);
                
            case 'ORDERS_RIPLEY':
                // return $this->handleRipleyOrders($page, $limit, $filters);
                
            case 'ORDERS_WALLMART':
                // return $this->handleWallmartOrders($page, $limit, $filters);
                
            case 'ORDERS_MERCADO_LIBRE':
                // return $this->handleMercadoLibreOrders($page, $limit, $filters);
                
            case 'ORDERS_WOOCOMMERCE':
                // return $this->handleWooCommerceOrders($page, $limit, $filters);
        }

        // Para marketplaces no migrados aún, usar el método original
        $data = $this->getOrdersWithPagination($table, $page, $limit, $filters);

        // Calcular estadísticas para los KPIs
        $sinImprimir = 0;
        $impresosNoProcesados = 0;
        $procesados = 0;

        try {
            // Contar órdenes sin imprimir
            $sinImprimirResult = $this->databaseService->fetchOne(
                "SELECT COUNT(*) as total FROM `$table` WHERE printed = 0 OR printed IS NULL"
            );
            $sinImprimir = $sinImprimirResult ? (int)$sinImprimirResult['total'] : 0;

            // Contar órdenes impresas pero no procesadas
            $impresosNoProcesadosResult = $this->databaseService->fetchOne(
                "SELECT COUNT(*) as total FROM `$table` WHERE printed = 1 AND (procesado = 0 OR procesado IS NULL)"
            );
            $impresosNoProcesados = $impresosNoProcesadosResult ? (int)$impresosNoProcesadosResult['total'] : 0;

            // Contar órdenes procesadas
            $procesadosResult = $this->databaseService->fetchOne(
                "SELECT COUNT(*) as total FROM `$table` WHERE procesado = 1"
            );
            $procesados = $procesadosResult ? (int)$procesadosResult['total'] : 0;
        } catch (\Exception $e) {
            // En caso de error, mantener los valores predeterminados
        }

        // Preparar datos para la vista
        return new ViewModel([
            'table' => $table,
            'orders' => $data['orders'],
            'totalItems' => $data['totalItems'],
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($data['totalItems'] / $limit),
            'filters' => $filters,
            'search' => $search,
            'statusFilter' => $status,
            'printedFilter' => $printed,
            'total' => $data['totalItems'],
            'sinImprimir' => $sinImprimir,
            'impresosNoProcesados' => $impresosNoProcesados,
            'procesados' => $procesados,
        ]);
    }



/**
 * Maneja las órdenes de París usando consulta directa OPTIMIZADA
 *
 * @param int $page
 * @param int $limit
 * @param array $filters
 * @return ViewModel
 */
private function handleParisOrders($page, $limit, $filters)
{
    try {
        // Inicializar parámetros de paginación
        $offset = ($page - 1) * $limit;
        
        // Filtros de búsqueda
        $search = $filters['search'] ?? '';
        $whereClause = '';
        $searchParams = [];
        
        if (!empty($search)) {
            // Buscar en múltiples campos
            $whereClause = " WHERE (o.subOrderNumber LIKE ? OR o.customer_name LIKE ? OR o.customer_documentNumber LIKE ?)";
            $searchTerm = "%$search%";
            $searchParams = [$searchTerm, $searchTerm, $searchTerm];
        }
        
        // Consulta OPTIMIZADA - separada en 2 pasos
        // Paso 1: Obtener solo los IDs con paginación
        $idsQuery = "
            SELECT DISTINCT o.subOrderNumber
            FROM paris_orders o 
            $whereClause
            ORDER BY o.createdAt DESC
            LIMIT $offset, $limit
        ";
        
        $orderIds = $this->databaseService->fetchAll($idsQuery, $searchParams);
        
        if (empty($orderIds)) {
            return new ViewModel([
                'table' => 'Orders_PARIS',
                'orders' => [],
                'totalItems' => 0,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => 0,
                'filters' => $filters,
                'search' => $search,
                'statusFilter' => $filters['status'] ?? '',
                'printedFilter' => $filters['printed'] ?? '',
                'total' => 0,
                'sinImprimir' => 0,
                'impresosNoProcesados' => 0,
                'procesados' => 0,
                'isDirectQuery' => true
            ]);
        }
        
        // Construir lista de IDs para la consulta IN
        $ids = [];
        $placeholders = [];
        foreach ($orderIds as $row) {
            $ids[] = $row['subOrderNumber'];
            $placeholders[] = '?';
        }
        
        // Paso 2: Obtener datos completos usando la consulta correcta
        $baseQuery = "
    SELECT  
        o.subOrderNumber AS suborden,
        o.subOrderNumber AS id,
        o.customer_name AS cliente,
        o.customer_documentNumber AS rut,
        o.billing_phone AS telefono,
        GROUP_CONCAT(TRIM(SUBSTRING_INDEX(ddet.variant_code, ',', -1)) SEPARATOR ', ') AS skus_bsale,
        GROUP_CONCAT(ddet.variant_description SEPARATOR ' | ') AS productos_bsale,
        o.createdAt AS fecha_creacion,
        o.createdAt AS fecha_compra,
        so.effectiveArrivalDate AS fecha_entrega,
        so.fulfillment,
        so.labelUrl,
        COALESCE(o.orden_impresa, 0) AS printed,
        COALESCE(o.orden_procesada, 0) AS procesado,
        doc.id AS id_boleta,
        doc.number AS numero_boleta,
        doc.urlPdf AS link_boleta,
        doc.urlPdf AS url_pdf_boleta,
        doc.totalAmount AS total,
        so.statusId,
        pst.translate AS estado,
        so.carrier AS transportista
    FROM paris_orders o
    LEFT JOIN paris_subOrders so  
        ON o.subOrderNumber = so.subOrderNumber
    LEFT JOIN paris_statuses pst 
        ON so.statusId = pst.id
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
    WHERE o.subOrderNumber IN (" . implode(',', $placeholders) . ")
    GROUP BY o.subOrderNumber
    ORDER BY o.createdAt DESC
";

        
        $orders = $this->databaseService->fetchAll($baseQuery, $ids);
        
        // Consulta de conteo simplificada
        $countQuery = "
            SELECT COUNT(DISTINCT o.subOrderNumber) as total 
            FROM paris_orders o 
            $whereClause
        ";
        
        $totalRegistros = $this->databaseService->fetchOne($countQuery, $searchParams);
        $totalItems = $totalRegistros ? (int)$totalRegistros['total'] : 0;
        
        // Procesar los datos para compatibilidad con la vista
        foreach ($orders as &$order) {
            // Asegurar que todos los campos requeridos estén presentes
            $order['suborder_number'] = $order['suborden'];
            $order['customer_name'] = $order['cliente'];
            
            // Crear campo productos compatible con el formato esperado
            if (!empty($order['productos_bsale'])) {
                $productos = [];
                $productNames = explode(' | ', $order['productos_bsale']);
                $skus = !empty($order['skus_bsale']) ? explode(', ', $order['skus_bsale']) : [];
                
                foreach ($productNames as $index => $productName) {
                    $productos[] = [
                        'sku' => isset($skus[$index]) ? $skus[$index] : '',
                        'nombre' => $productName,
                        'precio' => 0,
                        'cantidad' => 1,
                        'procesado' => (bool)$order['procesado']
                    ];
                }
                $order['productos'] = json_encode($productos);
            } else {
                // Fallback si no hay productos
                $order['productos'] = json_encode([
                    [
                        'sku' => 'PRODUCTOS_PARIS',
                        'nombre' => 'Productos de París',
                        'precio' => $order['total'] ?? 0,
                        'cantidad' => 1,
                        'procesado' => false
                    ]
                ]);
            }
        }
        
        // Calcular estadísticas KPI
        $sinImprimir = 0;
        $impresosNoProcesados = 0;
        $procesados = 0;
        
        foreach ($orders as $order) {
            if (!$order['printed']) {
                $sinImprimir++;
            } else if (!$order['procesado']) {
                $impresosNoProcesados++;
            } else {
                $procesados++;
            }
        }
        
        return new ViewModel([
            'table' => 'Orders_PARIS',
            'orders' => $orders,
            'totalItems' => $totalItems,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($totalItems / $limit),
            'filters' => $filters,
            'search' => $search,
            'statusFilter' => $filters['status'] ?? '',
            'printedFilter' => $filters['printed'] ?? '',
            'total' => $totalItems,
            'sinImprimir' => $sinImprimir,
            'impresosNoProcesados' => $impresosNoProcesados,
            'procesados' => $procesados,
            'isDirectQuery' => true
        ]);
        
    } catch (\Exception $e) {
        error_log("Error en handleParisOrders: " . $e->getMessage());
        
        // En caso de error, volver al método original
        $data = $this->getOrdersWithPagination('Orders_PARIS', $page, $limit, $filters);
        return new ViewModel([
            'table' => 'Orders_PARIS',
            'orders' => $data['orders'],
            'totalItems' => $data['totalItems'],
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($data['totalItems'] / $limit),
            'filters' => $filters,
            'search' => $search,
            'statusFilter' => $filters['status'] ?? '',
            'printedFilter' => $filters['printed'] ?? '',
            'total' => $data['totalItems'],
            'sinImprimir' => 0,
            'impresosNoProcesados' => 0,
            'procesados' => 0,
            'error' => $e->getMessage()
        ]);
    }
}





/**
     * Genera facturas/boletas para órdenes usando las URL de boletas almacenadas
     *
     * @param array $orderIds
     * @param mixed $orderTables
     * @return Response
     */
    private function generateInvoices($orderIds, $table = null)
    {
        // We don't need to import anything here since we're already importing Fpdi at the top of the file
        
        // Array para almacenar URLs de boletas
        $boletaUrls = [];
        
        // Obtener boletas para órdenes seleccionadas
        if ($table == 'paris_orders' || $table == 'Orders_PARIS') {
            // Para órdenes Paris, obtener directamente de bsale_documents
            $placeholders = array_fill(0, count($orderIds), '?');
            
            $sql = "SELECT doc.urlPdf, doc.urlPdfOriginal
                    FROM paris_orders o
                    JOIN (
                        SELECT document_id, number, REGEXP_SUBSTR(number, '[0-9]{10}') AS subOrderNumber_clean
                        FROM bsale_references
                        WHERE number REGEXP '[0-9]{10}'
                    ) ref ON ref.subOrderNumber_clean = o.subOrderNumber
                    JOIN bsale_documents doc ON doc.id = ref.document_id
                    WHERE o.subOrderNumber IN (" . implode(',', $placeholders) . ")";
            
            $results = $this->databaseService->fetchAll($sql, $orderIds);
            
            foreach ($results as $row) {
                if (!empty($row['urlPdf'])) {
                    $boletaUrls[] = $row['urlPdf'];
                } else if (!empty($row['urlPdfOriginal'])) {
                    $boletaUrls[] = $row['urlPdfOriginal'];
                }
            }
            
            // Si no encontramos nada, intentar búsqueda directa
            if (empty($boletaUrls)) {
                foreach ($orderIds as $orderId) {
                    $boletaData = $this->databaseService->fetchOne(
                        "SELECT bd.urlPdf, bd.urlPdfOriginal
                         FROM bsale_references br
                         JOIN bsale_documents bd ON br.document_id = bd.id
                         WHERE br.number LIKE ?
                         LIMIT 1",
                        ['%' . $orderId . '%']
                    );
                    
                    if ($boletaData) {
                        if (!empty($boletaData['urlPdf'])) {
                            $boletaUrls[] = $boletaData['urlPdf'];
                        } else if (!empty($boletaData['urlPdfOriginal'])) {
                            $boletaUrls[] = $boletaData['urlPdfOriginal'];
                        }
                    }
                }
            }
        } else {
            // Si table es un string pero no es array (caso común)
            if (!is_array($table)) {
                $orderTables = [$table];
            } else {
                $orderTables = $table;
            }
            
            // Si orderTables está vacío, asumir una tabla común
            if (empty($orderTables)) {
                $tableName = $this->params()->fromPost('table', '');
                $orderTables = array_fill(0, count($orderIds), $tableName);
            }
            
            // Obtener todas las URLs de boletas
            for ($i = 0; $i < count($orderIds); $i++) {
                $id = $orderIds[$i];
                $currentTable = isset($orderTables[$i]) ? $orderTables[$i] : '';
                
                if (!empty($id) && !empty($currentTable)) {
                    try {
                        // Para otras tablas, usar el método original
                        $query = "SELECT id, suborder_number, url_pdf_boleta, invoice_url, boleta_url, url_boleta, pdf_url, url_pdf FROM `$currentTable` WHERE id = ?";
                        
                        $orderData = $this->databaseService->fetchOne($query, [$id]);
                        
                        // Determinar qué campo contiene la URL según disponibilidad
                        $boletaUrl = null;
                        if ($orderData) {
                            // Priorizar campos en este orden
                            $urlFields = [
                                'url_pdf_boleta',
                                'invoice_url',
                                'boleta_url',
                                'url_boleta',
                                'pdf_url',
                                'url_pdf'
                            ];
                            
                            foreach ($urlFields as $field) {
                                if (isset($orderData[$field]) && !empty($orderData[$field])) {
                                    $boletaUrl = $orderData[$field];
                                    break;
                                }
                            }
                            
                            if ($boletaUrl && filter_var($boletaUrl, FILTER_VALIDATE_URL)) {
                                $boletaUrls[] = $boletaUrl;
                                
                                // Marcar como impresa
                                $this->databaseService->execute(
                                    "UPDATE `$currentTable` SET printed = 1 WHERE id = ?",
                                    [$id]
                                );
                            }
                        }
                    } catch (\Exception $e) {
                        // Ignorar órdenes con error
                        continue;
                    }
                }
            }
        }
        
        // Si no hay URLs de boletas, mostrar mensaje
        if (empty($boletaUrls)) {
            // Intentar generar una factura básica como último recurso
            try {
                return $this->generateBasicInvoices($orderIds, $table);
            } catch (\Exception $e) {
                $html = '
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                        .error { color: #d9534f; font-size: 24px; margin-bottom: 20px; }
                    </style>
                </head>
                <body>
                    <div class="error">No se encontraron boletas</div>
                    <p>No se encontraron URLs de boletas para las órdenes seleccionadas.</p>
                </body>
                </html>';
                
                $options = new Options();
                $options->set('isHtml5ParserEnabled', true);
                
                $dompdf = new Dompdf($options);
                $dompdf->loadHtml($html, 'UTF-8');
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();
                
                $pdfContent = $dompdf->output();
            }
        } else {
            // Crear un PDF con todas las boletas usando FPDI sin formato predefinido
            // No especificamos tamaño de página aquí porque se ajustará al tamaño de cada documento
            $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Configurar opciones adicionales para mejor manejo de PDF importados
            $pdf->setAutoPageBreak(false); // Evitar saltos de página automáticos
            $pdf->setCreator('Lodoro Analytics');
            $pdf->setTitle('Boletas Paris');
            
            // Por cada URL, descargar el PDF y agregarlo
            foreach ($boletaUrls as $url) {
                // Asegurar que la URL tenga https://
                if (!preg_match('/^https?:\/\//i', $url)) {
                    $url = 'https://' . $url;
                }
                
                // Descargar PDF con ampliado de opciones para mejorar la compatibilidad
                $context = stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                    'http' => [
                        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
                        'timeout' => 30, // Incrementar el timeout para URLs lentas
                        'follow_location' => 1, // Seguir redirecciones
                        'ignore_errors' => true
                    ]
                ]);
                
                error_log("Descargando URL: " . $url);
                $content = @file_get_contents($url, false, $context);
                
                if (empty($content)) {
                    error_log("Error: No se pudo descargar el contenido de la URL: " . $url);
                }
                
                if ($content) {
                    // Guardar temporalmente
                    $tempFile = tempnam(sys_get_temp_dir(), 'boleta_');
                    file_put_contents($tempFile, $content);
                    
                    try {
                        // Agregar al PDF principal manteniendo el tamaño original
                        $pageCount = $pdf->setSourceFile($tempFile);
                        
                        for ($i = 1; $i <= $pageCount; $i++) {
                            $tpl = $pdf->importPage($i);
                            
                            // Obtener las dimensiones del template original
                            $size = $pdf->getTemplateSize($tpl);
                            $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                            
                            // Agregar página con el tamaño exacto del documento original
                            $pdf->AddPage($orientation, array($size['width'], $size['height']));
                            
                            // Usar el template ajustado a la página completa
                            $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
                        }
                        
                        // Limpiar
                        unlink($tempFile);
                    } catch (\Exception $e) {
                        // Registrar error pero continuar con siguiente URL
                        error_log("Error al importar etiqueta PDF: " . $e->getMessage());
                    }
                }
            }
            
            // Generar PDF
            $pdfContent = $pdf->Output('', 'S');
        }
        
        // Crear respuesta HTTP con el PDF
        $response = new Response();
        $response->setContent($pdfContent);
        
        // Configurar cabeceras
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/pdf');
        $headers->addHeaderLine('Content-Disposition', 'inline; filename="Boletas_' . date('Y-m-d_His') . '.pdf"');
        $headers->addHeaderLine('Content-Length', strlen($pdfContent));
        
        return $response;
    }

    /**
     * Método para exportar a CSV
     */
    private function exportToCsv($orderIds, $tables = null)
    {
        // Implementación del método para exportar a CSV
        $csvData = "Order ID,Table,Customer,Products,Total\n";

        foreach ($orderIds as $index => $orderId) {
            $table = isset($tables[$index]) ? $tables[$index] : 'Orders_GENERAL';

            try {
                // Para Orders_PARIS, usar consulta directa
                if (strtoupper($table) === 'ORDERS_PARIS') {
                    $orderData = $this->databaseService->fetchOne(
                        "SELECT 
                            pof.subOrderNumber,
                            pof.customer_name as cliente,
                            pi.name as producto,
                            bd.totalAmount as total
                        FROM bsale_references brd
                        INNER JOIN paris_orders pof 
                            ON brd.number COLLATE utf8mb4_unicode_ci = pof.subOrderNumber COLLATE utf8mb4_unicode_ci
                        INNER JOIN paris_items pi 
                            ON pof.subOrderNumber COLLATE utf8mb4_unicode_ci = pi.subOrderNumber COLLATE utf8mb4_unicode_ci
                        INNER JOIN bsale_documents bd 
                            ON brd.document_id = bd.id
                        WHERE pof.subOrderNumber = ?",
                        [$orderId]
                    );
                    
                    if ($orderData) {
                        $customerName = $orderData['cliente'] ?? 'N/A';
                        $products = $orderData['producto'] ?? 'N/A';
                        $total = $orderData['total'] ?? '0.00';
                    } else {
                        $customerName = 'N/A';
                        $products = 'N/A';
                        $total = '0.00';
                    }
                } else {
                    // Buscar la orden en la tabla correspondiente
                    $sql = "SELECT * FROM `$table` WHERE id = ? OR orderId = ?";
                    $statement = $this->dbAdapter->createStatement($sql);
                    $result = $statement->execute([$orderId, $orderId]);

                    if ($result->count() > 0) {
                        $order = $result->current();

                        // Extraer datos relevantes
                        $customerName = $order['customer_name'] ?? ($order['cliente'] ?? 'N/A');
                        $products = $order['productos'] ?? 'N/A';
                        $total = $order['total'] ?? '0.00';
                    } else {
                        $customerName = 'N/A';
                        $products = 'N/A';
                        $total = '0.00';
                    }
                }

                // Escapar campos para CSV
                $customerName = str_replace('"', '""', $customerName);
                $products = str_replace('"', '""', $products);

                // Añadir línea al CSV
                $csvData .= "\"$orderId\",\"$table\",\"$customerName\",\"$products\",\"$total\"\n";
            } catch (\Exception $e) {
                error_log("Error al exportar orden $orderId a CSV: " . $e->getMessage());
            }
        }

        // Configurar respuesta HTTP
        $response = new Response();
        $response->setContent($csvData);

        // Configurar cabeceras
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'text/csv; charset=UTF-8');
        $headers->addHeaderLine('Content-Disposition', 'attachment; filename="Orders_' . date('Y-m-d_His') . '.csv"');
        $headers->addHeaderLine('Content-Length', strlen($csvData));
        $headers->addHeaderLine('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $headers->addHeaderLine('Pragma', 'public');
        $headers->addHeaderLine('Expires', '0');

        return $response;
    }

    /**
     * Método para generar packing list (lista de empaque)
     * @param array $orderIds IDs de las órdenes
     * @param mixed $tables Tabla o tablas de órdenes
     * @return Response
     */
    private function generatePackingList($orderIds, $tables = null)
    {
        // Inicializar el generador de códigos de barras
        $generator = new BarcodeGeneratorPNG();

        // Normalizar $tables para asegurarnos de que sea un array
        if (is_string($tables)) {
            $tables = [$tables];
        } else if (is_array($tables)) {
            $tables = $tables;
        } else {
            // Si es null o cualquier otro valor, usar todas las tablas
            $tables = [
                'Orders_WALLMART',
                'Orders_RIPLEY',
                'Orders_FALABELLA',
                'Orders_MERCADO_LIBRE',
                'Orders_PARIS',
                'Orders_WOOCOMMERCE'
            ];
        }

        $html = '';
        $pageIndex = 0; // Contador de páginas para numeración

        foreach ($tables as $currentTable) {
            $tableMarketplace = str_replace('Orders_', '', $currentTable);
            
            // Para Orders_PARIS, usar consulta directa
            if (strtoupper($currentTable) === 'ORDERS_PARIS') {
                error_log("generatePackingList: Procesando órdenes de Paris, IDs: " . json_encode($orderIds));
                
                // Normalizar los IDs: convertir IDs de base de datos a subOrderNumbers si es necesario
                $subOrderNumbers = [];
                foreach ($orderIds as $id) {
                    try {
                        $checkOrder = $this->databaseService->fetchOne(
                            "SELECT subOrderNumber FROM paris_orders WHERE id = ? OR subOrderNumber = ? LIMIT 1",
                            [$id, $id]
                        );
                        
                        if ($checkOrder && !empty($checkOrder['subOrderNumber'])) {
                            $subOrderNumbers[] = $checkOrder['subOrderNumber'];
                        } else {
                            $subOrderNumbers[] = $id; // Mantener el ID original si no se pudo convertir
                        }
                    } catch (\Exception $e) {
                        error_log("Error al obtener subOrderNumber para ID $id: " . $e->getMessage());
                        $subOrderNumbers[] = $id;
                    }
                }
                
                // Construir placeholders para la consulta SQL
                $subOrderPlaceholders = implode(',', array_fill(0, count($subOrderNumbers), '?'));
                
                // Usar la misma consulta de paris-order.php, pero ajustada para múltiples órdenes
                error_log("generatePackingList: Usando consulta basada en paris-order.php para subOrderNumbers: " . json_encode($subOrderNumbers));
                
                $sql = "
                    SELECT
                      pof.id,
                      pof.subOrderNumber,
                      pof.subOrderNumber as order_number,
                      pof.customer_name as cliente,
                      pof.customer_documentNumber as rut,
                      pof.billing_phone as telefono,
                      TRIM(SUBSTRING_INDEX(ddet.variant_code, ',', -1)) as sku,
                      ddet.variant_description as producto,
                      ddet.quantity as cantidad,
                      'PARIS' as marketplace
                    FROM
                      paris_orders pof
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
                    LEFT JOIN bsale_documents doc
                      ON doc.id = ref.document_id
                    LEFT JOIN bsale_document_details ddet
                      ON ddet.document_id = doc.id
                    WHERE pof.subOrderNumber IN ($subOrderPlaceholders)
                ";
                
                try {
                    $orders = $this->databaseService->fetchAll($sql, $subOrderNumbers);
                    error_log("generatePackingList: Encontradas " . count($orders) . " órdenes de Paris con productos");
                    
                    // Si no hay resultados o productos, intentar con paris_items
                    if (empty($orders)) {
                        error_log("generatePackingList: No se encontraron productos con la consulta principal, intentando con paris_items");
                        
                        $sqlAlt = "
                            SELECT 
                                pof.id,
                                pof.subOrderNumber,
                                pof.subOrderNumber as order_number,
                                pof.customer_name as cliente,
                                pi.sku,
                                pi.name as producto,
                                pi.quantity as cantidad,
                                'PARIS' as marketplace
                            FROM paris_orders pof
                            LEFT JOIN paris_items pi ON pof.subOrderNumber = pi.subOrderNumber
                            WHERE pof.subOrderNumber IN ($subOrderPlaceholders)
                        ";
                        
                        $orders = $this->databaseService->fetchAll($sqlAlt, $subOrderNumbers);
                        error_log("generatePackingList: Encontradas " . count($orders) . " órdenes de Paris con paris_items");
                    }
                    
                    $tableOrders = [];
                    foreach ($orders as $row) {
                        $tableOrders[] = $row;
                    }
                } catch (\Exception $e) {
                    error_log("Error al obtener órdenes de París: " . $e->getMessage());
                    continue;
                }
            } else {
                // Obtener los datos de las órdenes en esta tabla
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $sql = "SELECT * FROM `$currentTable` WHERE id IN ($placeholders)";
                
                try {
                    $statement = $this->dbAdapter->createStatement($sql);
                    $result = $statement->execute($orderIds);
                    
                    $tableOrders = [];
                    foreach ($result as $row) {
                        $tableOrders[] = $row;
                    }
                } catch (\Exception $e) {
                    error_log("Error al obtener órdenes de $currentTable: " . $e->getMessage());
                    continue;
                }
            }
            
            if (empty($tableOrders)) {
                continue; // No hay órdenes en esta tabla, pasar a la siguiente
            }
            
            // Recolectar todos los productos de las órdenes para este marketplace
            $allItems = [];
            $orderInfo = [];
            
            foreach ($tableOrders as $order) {
                $orderId = $order['id'];
                $suborderNumber = $order['suborder_number'] ?? $orderId;
                $customerName = $order['customer_name'] ?? $order['cliente'] ?? 'N/A';
                
                // Guardar información de la orden
                $orderInfo[$suborderNumber] = [
                    'id' => $orderId,
                    'customer_name' => $customerName,
                    'reference' => $suborderNumber
                ];
                
                // Para Orders_PARIS, los datos ya están cargados
                if (strtoupper($currentTable) === 'ORDERS_PARIS') {
                    $allItems[] = [
                        'sku' => $order['sku'] ?? 'Sin SKU',
                        'name' => $order['producto'] ?? 'Sin nombre',
                        'quantity' => $order['cantidad'] ?? 1,
                        'subOrderNumber' => $suborderNumber
                    ];
                } else {
                    // Obtener productos de la orden
                    $orderItems = $this->getOrderItemsForPacking($orderId, $suborderNumber, $currentTable);
                    
                    foreach ($orderItems as $item) {
                        $allItems[] = [
                            'sku' => $item['sku'],
                            'name' => $item['name'],
                            'quantity' => $item['quantity'],
                            'subOrderNumber' => $suborderNumber
                        ];
                    }
                }
                
                // Marcar como impresa
                try {
                    if (strtoupper($currentTable) === 'ORDERS_PARIS') {
                        // Para París, no hay tabla física que actualizar
                    } else {
                        $this->databaseService->execute(
                            "UPDATE `$currentTable` SET printed = 1 WHERE id = ?",
                            [$orderId]
                        );
                    }
                } catch (\Exception $e) {
                    // Ignorar errores al actualizar el estado
                }
            }
            
            if (empty($allItems)) {
                continue; // No hay productos, pasar a la siguiente tabla
            }
            
            // Agrupar productos por SKU
            $agrupados = [];
            foreach ($allItems as $item) {
                $sku = $item['sku'];
                if (!isset($agrupados[$sku])) {
                    $agrupados[$sku] = [
                        'sku' => $sku,
                        'nombre' => $item['name'],
                        'cantidad' => 0,
                        'pedidos' => []
                    ];
                }
                $agrupados[$sku]['cantidad'] += $item['quantity'];
                $agrupados[$sku]['pedidos'][] = $item['subOrderNumber'];
            }
            
            // Generar el HTML del Packing List para este marketplace
            $html .= '<div style="page-break-after: always;">';
            $html .= '
            <html>
            <head>
                <style>
                    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
                    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
                    th, td { border: 1px solid #000; padding: 6px; text-align: left; }
                    th { background-color: #eee; }
                    .title { font-size: 20px; font-weight: bold; margin-bottom: 10px; }
                    .subtitle { margin: 10px 0; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class="title">Packing List - ' . htmlspecialchars($tableMarketplace) . ' | LODORO</div>
                <div>PACKING LIST GENERADO EL: ' . date("Y-m-d H:i:s") . ' |</div>
                <br>
                
                <table>
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>NOMBRE</th>
                            <th>CANTIDAD</th>
                            <th>PEDIDOS</th>
                        </tr>
                    </thead>
                    <tbody>';
                    
            foreach ($agrupados as $item) {
                $html .= '
                        <tr>
                            <td>' . htmlspecialchars($item['sku']) . '</td>
                            <td>' . htmlspecialchars($item['nombre']) . '</td>
                            <td>' . $item['cantidad'] . '</td>
                            <td>' . implode(', ', array_unique($item['pedidos'])) . '</td>
                        </tr>';
            }
            
            $totalProductos = array_sum(array_column($agrupados, 'cantidad'));
            
            $html .= '
                    </tbody>
                </table>
                <div><strong>TOTAL PRODUCTOS:</strong> ' . $totalProductos . '</div>
                <br><br>
                <div class="subtitle">Pedidos en este Packing List</div>
                <table>
                    <thead>
                        <tr>
                            <th>N° PEDIDO</th>
                            <th>CLIENTE</th>
                            <th>REFERENCIA SELLER</th>
                        </tr>
                    </thead>
                    <tbody>';
                    
            foreach ($orderInfo as $subOrderNumber => $order) {
                $html .= '
                        <tr>
                            <td>' . htmlspecialchars($subOrderNumber) . '</td>
                            <td>' . htmlspecialchars($order['customer_name']) . '</td>
                            <td>' . htmlspecialchars($order['reference']) . '</td>
                        </tr>';
            }
            
            $html .= '
                    </tbody>
                </table>
                <div><strong>TOTAL PEDIDOS:</strong> ' . count($orderInfo) . '</div>
            </body>
            </html>';
            $html .= '</div>';
        }
        
        if (empty($html)) {
            // Generar HTML alternativo con mensaje de error
            $html = '<html><body><h1>Packing List</h1><p>No se encontraron datos para las órdenes seleccionadas.</p></body></html>';
        }
        
        // Crear PDF con DOMPDF
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        $pdfContent = $dompdf->output();
        
        // Crear respuesta HTTP
        $response = new Response();
        $response->setContent($pdfContent);
        
        // Configurar cabeceras
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/pdf');
        $headers->addHeaderLine('Content-Disposition', 'inline; filename="PackingList_' . date('Y-m-d') . '.pdf"');
        $headers->addHeaderLine('Content-Length', strlen($pdfContent));
        $headers->addHeaderLine('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $headers->addHeaderLine('Pragma', 'public');
        $headers->addHeaderLine('Expires', '0');
        
        return $response;
    }

    /**
     * Obtiene los productos de una orden para el packing list
     * @param mixed $orderId ID de la orden
     * @param string $suborderNumber Número de suborden
     * @param string $tableName Nombre de la tabla
     * @return array Lista de productos
     */
    private function getOrderItemsForPacking($orderId, $suborderNumber, $tableName)
    {
        $items = [];
        
        // 1. INTENTO: Buscar en la tabla específica de items
        $itemsTable = $tableName . "_Items";
        try {
            // Buscar por orden_id o por suborder_number según disponibilidad
            $productsSql = "SELECT * FROM `$itemsTable` WHERE order_id = ? OR orderId = ? OR subOrderNumber = ?";
            $productsStatement = $this->dbAdapter->createStatement($productsSql);
            $productsResult = $productsStatement->execute([$orderId, $orderId, $suborderNumber]);
            
            foreach ($productsResult as $product) {
                $items[] = [
                    'sku' => $product['sku'] ?? 'Sin SKU',
                    'name' => $product['name'] ?? ($product['nombre'] ?? 'Sin nombre'),
                    'quantity' => $product['quantity'] ?? ($product['cantidad'] ?? 1)
                ];
            }
        } catch (\Exception $e) {
            error_log("Error al buscar en tabla de items: " . $e->getMessage());
        }
        
        // 2. INTENTO: Buscar en la tabla MKP_ correspondiente si aún no tenemos productos
        if (empty($items) && !empty($suborderNumber)) {
            $mkpTable = 'MKP_' . str_replace('Orders_', '', $tableName);
            try {
                $mkpSql = "SELECT sku, nombre_producto, codigo_sku FROM `$mkpTable` WHERE numero_suborden = ?";
                $mkpStatement = $this->dbAdapter->createStatement($mkpSql);
                $mkpResult = $mkpStatement->execute([$suborderNumber]);
                
                foreach ($mkpResult as $mkpItem) {
                    // Priorizar campos con SKU
                    $sku = !empty($mkpItem['sku']) ? $mkpItem['sku'] : 
                          (!empty($mkpItem['codigo_sku']) ? $mkpItem['codigo_sku'] : 'Sin SKU');
                    
                    $items[] = [
                        'sku' => $sku,
                        'name' => $mkpItem['nombre_producto'] ?? 'Sin nombre',
                        'quantity' => 1
                    ];
                }
            } catch (\Exception $e) {
                error_log("Error al buscar en tabla MKP: " . $e->getMessage());
            }
        }
        
        // 3. INTENTO: Si aún no hay productos, usar información básica de la orden
        if (empty($items)) {
            try {
                $orderSql = "SELECT sku, productos FROM `$tableName` WHERE id = ?";
                $orderStatement = $this->dbAdapter->createStatement($orderSql);
                $orderResult = $orderStatement->execute([$orderId]);
                
                if ($orderResult->count() > 0) {
                    $order = $orderResult->current();
                    
                    // Buscar si hay campo 'sku' en la orden primero
                    $orderSku = $order['sku'] ?? '';
                    $skus = !empty($orderSku) ? explode(',', $orderSku) : [];
                    
                    $productStrings = explode(',', $order['productos'] ?? '');
                    foreach ($productStrings as $i => $productString) {
                        if (!empty(trim($productString))) {
                            $items[] = [
                                'sku' => !empty($skus[$i]) ? trim($skus[$i]) : 'SKU-' . str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT),
                                'name' => trim($productString),
                                'quantity' => 1
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log("Error al obtener productos de la orden: " . $e->getMessage());
            }
        }
        
        // 4. INTENTO: Si aún así no hay productos, crear uno genérico
        if (empty($items)) {
            $items[] = [
                'sku' => 'SKU-' . substr(md5($suborderNumber ?: $orderId), 0, 8),
                'name' => 'Producto de orden #' . ($suborderNumber ?: $orderId),
                'quantity' => 1
            ];
        }
        
        return $items;
    }

    /**
     * Implementación alternativa para generar lista de picking para órdenes
     * @param array $orderIds IDs de las órdenes
     * @param mixed $tables Tabla o tablas de órdenes
     * @return Response
     */
    private function generatePickingList(array $orderIds, $tables = null)
    {
        // Inicializar el generador de códigos de barras
        $generator = new BarcodeGeneratorPNG();

        if (is_string($tables)) {
            $tables = [$tables];
        }

        if (empty($tables)) {
            $tables = [
                'Orders_WALLMART',
                'Orders_RIPLEY',
                'Orders_FALABELLA',
                'Orders_MERCADO_LIBRE',
                'Orders_PARIS',
                'Orders_WOOCOMMERCE'
            ];
        }

        $html = '';

        foreach ($tables as $currentTable) {
            $tableMarketplace = str_replace('Orders_', '', $currentTable);
            $mkpTable = 'MKP_' . $tableMarketplace;

            // Para Orders_PARIS, usar consulta directa
            if (strtoupper($currentTable) === 'ORDERS_PARIS') {
                error_log("generatePickingList: Procesando órdenes de Paris, IDs: " . json_encode($orderIds));
                
                // Normalizar los IDs: convertir IDs de base de datos a subOrderNumbers si es necesario
                $subOrderNumbers = [];
                foreach ($orderIds as $id) {
                    try {
                        $checkOrder = $this->databaseService->fetchOne(
                            "SELECT subOrderNumber FROM paris_orders WHERE id = ? OR subOrderNumber = ? LIMIT 1",
                            [$id, $id]
                        );
                        
                        if ($checkOrder && !empty($checkOrder['subOrderNumber'])) {
                            $subOrderNumbers[] = $checkOrder['subOrderNumber'];
                        } else {
                            $subOrderNumbers[] = $id; // Mantener el ID original si no se pudo convertir
                        }
                    } catch (\Exception $e) {
                        error_log("Error al obtener subOrderNumber para ID $id: " . $e->getMessage());
                        $subOrderNumbers[] = $id;
                    }
                }
                
                // Construir placeholders para la consulta SQL
                $subOrderPlaceholders = implode(',', array_fill(0, count($subOrderNumbers), '?'));
                
                // Usar la misma consulta de paris-order-handler.php, pero ajustada para múltiples órdenes
                error_log("generatePickingList: Usando consulta basada en paris-order-handler.php para subOrderNumbers: " . json_encode($subOrderNumbers));
                
                $sql = "
                    SELECT
                      pof.id,
                      pof.subOrderNumber,
                      pof.subOrderNumber as order_number,
                      pof.customer_name as cliente,
                      pof.customer_documentNumber as rut,
                      pof.billing_phone as telefono,
                      pof.createdAt as fecha_creacion,
                      ddet.variant_code as sku,
                      ddet.variant_description as producto,
                      ddet.quantity as cantidad,
                      pso.effectiveArrivalDate as fecha_entrega,
                      pso.fulfillment,
                      pst.translate as estado,
                      'PARIS' as marketplace
                    FROM
                      paris_orders pof
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
                    LEFT JOIN bsale_documents doc
                      ON doc.id = ref.document_id
                    LEFT JOIN bsale_document_details ddet
                      ON ddet.document_id = doc.id
                    WHERE pof.subOrderNumber IN ($subOrderPlaceholders)
                ";
                
                try {
                    $orders = $this->databaseService->fetchAll($sql, $subOrderNumbers);
                    error_log("generatePickingList: Encontradas " . count($orders) . " órdenes de Paris con productos");
                    
                    // Si no hay resultados o productos, intentar con paris_items
                    if (empty($orders)) {
                        error_log("generatePickingList: No se encontraron productos con la consulta principal, intentando con paris_items");
                        
                        $sqlAlt = "
                            SELECT 
                                pof.id,
                                pof.subOrderNumber,
                                pof.subOrderNumber as order_number,
                                pof.customer_name as cliente,
                                pof.createdAt as fecha_creacion,
                                '' as direccion,
                                pi.sku,
                                pi.name as producto,
                                pi.quantity as cantidad,
                                'PARIS' as marketplace
                            FROM paris_orders pof
                            LEFT JOIN paris_items pi ON pof.subOrderNumber = pi.subOrderNumber
                            WHERE pof.subOrderNumber IN ($subOrderPlaceholders)
                        ";
                        
                        $orders = $this->databaseService->fetchAll($sqlAlt, $subOrderNumbers);
                        error_log("generatePickingList: Encontradas " . count($orders) . " órdenes de Paris con paris_items");
                    }
                } catch (\Exception $e) {
                    error_log("Error al obtener órdenes de París: " . $e->getMessage());
                    continue;
                }
            } else {
                // Obtener los datos de las órdenes en esta tabla
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                // USAR id en lugar de suborder_number
                $sql = "SELECT * FROM `$currentTable` WHERE id IN ($placeholders)";
                $statement = $this->dbAdapter->createStatement($sql);
                $result = $statement->execute($orderIds);
                
                $orders = [];
                foreach ($result as $row) {
                    $orders[] = $row;
                }
            }
            
            foreach ($orders as $index => $order) {
                $items = [];
                $orderId = $order['id'];
                $suborderNumber = $order['suborder_number'] ?? '';
                
                // Para Orders_PARIS, los datos ya están cargados
                if (strtoupper($currentTable) === 'ORDERS_PARIS') {
                    $items[] = [
                        'sku' => $order['sku'] ?? 'Sin SKU',
                        'name' => $order['producto'] ?? 'Sin nombre',
                        'quantity' => $order['cantidad'] ?? 1
                    ];
                } else {
                    // 1. INTENTO: Obtener productos usando el método dedicado
                    try {
                        // Usar el método dedicado para obtener productos de una orden
                        $orderProducts = $this->getOrderProducts($orderId, $currentTable);
                        
                        if (!empty($orderProducts)) {
                            foreach ($orderProducts as $product) {
                                $items[] = [
                                    'sku' => $product['sku'] ?? 'Sin SKU',
                                    'name' => $product['nombre'] ?? 'Sin nombre',
                                    'quantity' => $product['cantidad'] ?? 1
                                ];
                            }
                        }
                    } catch (\Exception $e) {
                        error_log("Error al obtener productos con getOrderProducts: " . $e->getMessage());
                        // Continuar con otros enfoques si este falla
                    }
                    
                    // 2. INTENTO: Buscar en la tabla MKP_ correspondiente si aún no tenemos productos
                    if (empty($items) && !empty($suborderNumber)) {
                        try {
                            $mkpSql = "SELECT sku, nombre_producto, codigo_sku FROM `$mkpTable` WHERE numero_suborden = ?";
                            $mkpStatement = $this->dbAdapter->createStatement($mkpSql);
                            $mkpResult = $mkpStatement->execute([$suborderNumber]);
                            
                            foreach ($mkpResult as $mkpItem) {
                                // Priorizar campos con SKU
                                $sku = !empty($mkpItem['sku']) ? $mkpItem['sku'] : 
                                      (!empty($mkpItem['codigo_sku']) ? $mkpItem['codigo_sku'] : 'Sin SKU');
                                
                                $items[] = [
                                    'sku' => $sku,
                                    'name' => $mkpItem['nombre_producto'] ?? 'Sin nombre',
                                    'quantity' => 1
                                ];
                            }
                        } catch (\Exception $e) {
                            error_log("Error al buscar en tabla MKP: " . $e->getMessage());
                        }
                    }
                    
                    // 3. INTENTO: Buscar en la tabla específica de items si aún no tenemos productos
                    if (empty($items)) {
                        $itemsTable = $currentTable . "_Items";
                        try {
                            // Buscar por orden_id o por suborder_number según disponibilidad
                            $productsSql = "SELECT * FROM `$itemsTable` WHERE order_id = ? OR orderId = ? OR subOrderNumber = ?";
                            $productsStatement = $this->dbAdapter->createStatement($productsSql);
                            $productsResult = $productsStatement->execute([$orderId, $orderId, $suborderNumber]);
                            
                            foreach ($productsResult as $product) {
                                $items[] = [
                                    'sku' => $product['sku'] ?? 'Sin SKU',
                                    'name' => $product['name'] ?? ($product['nombre'] ?? 'Sin nombre'),
                                    'quantity' => $product['quantity'] ?? ($product['cantidad'] ?? 1)
                                ];
                            }
                        } catch (\Exception $e) {
                            error_log("Error al buscar en tabla de items: " . $e->getMessage());
                        }
                    }
                    
                    // 4. INTENTO: Si aún no hay productos, usar información básica de la orden
                    if (empty($items)) {
                        // Buscar si hay campo 'sku' en la orden primero
                        $orderSku = $order['sku'] ?? '';
                        $skus = !empty($orderSku) ? explode(',', $orderSku) : [];
                        
                        $productStrings = explode(',', $order['productos'] ?? '');
                        foreach ($productStrings as $i => $productString) {
                            if (!empty(trim($productString))) {
                                $items[] = [
                                    'sku' => !empty($skus[$i]) ? trim($skus[$i]) : 'SKU-' . str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT),
                                    'name' => trim($productString),
                                    'quantity' => 1
                                ];
                            }
                        }
                        
                        // Si aún así no hay productos, crear uno genérico
                        if (empty($items)) {
                            $items[] = [
                                'sku' => 'SKU-' . substr(md5($suborderNumber ?: $orderId), 0, 8),
                                'name' => 'Producto de orden #' . ($suborderNumber ?: $orderId),
                                'quantity' => 1
                            ];
                        }
                    }
                }
                
                // Generar código de barras para la orden - usar id o suborder_number
                $barcodeValue = $order['suborder_number'] ?? $order['id'];
                $barcode = base64_encode($generator->getBarcode($barcodeValue, $generator::TYPE_CODE_128));
                
                // Usar customer_name y cliente según disponibilidad
                $customerName = $order['customer_name'] ?? $order['cliente'] ?? 'N/A';
                
                $html .= '<div style="page-break-after: always;">';
                $html .= '
                <html>
                <head>
                    <style>
                        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                        td, th { border: 1px solid #000; padding: 5px; }
                        .barcode { margin: 15px 0; }
                        .header { font-size: 18px; font-weight: bold; margin-bottom: 5px; text-align: left; }
                    </style>
                </head>
                <body>
                    <div class="header">Packing List - '.$tableMarketplace.' | LODORO</div>
                    <div style="text-align: left;">GENERADO: '.date('d-m-Y H:i:s').'</div>

                    <div class="barcode">
                        <img src="data:image/png;base64,'.$barcode.'" style="height: 60px; width: 43%; max-width: 400px;"><br>
                        '.$barcodeValue.'
                    </div>

                    <table>
                        <tr>
                            <td><b>Cliente:</b><br>'.htmlspecialchars((string)$customerName).'</td>
                            <td><b>Fecha Pedido:</b><br>'.htmlspecialchars((string)($order['fecha_creacion'] ?? 'N/A')).'</td>
                        </tr>
                        <tr>
                            <td><b>Dirección:</b><br>'.htmlspecialchars((string)($order['direccion'] ?? 'N/A')).'</td>
                            <td><b>N° Orden:</b><br>'.htmlspecialchars((string)$order['id']).'</td>
                        </tr>
                        <tr>
                            <td><b>Entregas Del Día:</b><br>'.date('d-m-Y').'</td>
                            <td><b>Suborden:</b><br>'.htmlspecialchars((string)($order['suborder_number'] ?? 'N/A')).'</td>
                        </tr>
                    </table>

                    <table>
                        <thead>
                            <tr>
                                <th>CLIENTE</th>
                                <th>SUBORDEN</th>
                                <th>PRODUCTO</th>
                                <th>SKU</th>
                                <th>CANTIDAD</th>
                                <th>MARKETPLACE</th>
                            </tr>
                        </thead>
                        <tbody>';

                foreach ($items as $item) {
                    $itemName = htmlspecialchars((string)($item['name'] ?? 'Sin nombre'));
                    $itemSku = htmlspecialchars((string)($item['sku'] ?? 'N/A'));
                    $itemQuantity = htmlspecialchars((string)($item['quantity'] ?? 1));
                    
                    $html .= '
                        <tr>
                            <td>'.htmlspecialchars((string)$customerName).'</td>
                            <td>'.htmlspecialchars((string)($order['suborder_number'] ?? 'N/A')).'</td>
                            <td>'.$itemName.'</td>
                            <td>'.$itemSku.'</td>
                            <td>'.$itemQuantity.'</td>
                            <td>'.htmlspecialchars((string)$tableMarketplace).'</td>
                        </tr>';
                }
                
                $totalProductos = count($items);
                
                $html .= '
                        <tr>
                            <td colspan="4"><b>TOTAL PRODUCTOS</b></td>
                            <td><b>'.$totalProductos.'</b></td>
                            <td><b>'.htmlspecialchars((string)$tableMarketplace).'</b></td>
                        </tr>
                        </tbody>
                    </table>

                    <div style="text-align: center; margin-top: 20px;">Página '.($index + 1).' de '.count($orderIds).'</div>
                </body>
                </html>
                ';
                $html .= '</div>';
            }
        }
        
        if (empty($html)) {
            // Generar HTML alternativo con mensaje de error
            $html = '<html><body><h1>Picking List</h1><p>No se encontraron datos para las órdenes seleccionadas.</p></body></html>';
        }
        
        // Crear PDF con DOMPDF
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        $pdfContent = $dompdf->output();
        
        // Crear respuesta HTTP
        $response = new Response();
        $response->setContent($pdfContent);
        
        // Configurar cabeceras
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/pdf');
        $headers->addHeaderLine('Content-Disposition', 'inline; filename="PickingList_' . date('Y-m-d') . '.pdf"');
        $headers->addHeaderLine('Content-Length', strlen($pdfContent));
        $headers->addHeaderLine('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $headers->addHeaderLine('Pragma', 'public');
        $headers->addHeaderLine('Expires', '0');
        
        return $response;
    }

    /**
     * Exporta órdenes a formato Excel
     *
     * @param array $orderIds
     * @param array $orderTables
     * @return \Laminas\Http\Response
     */
    private function exportToExcel($orderIds, $orderTables)
    {
        // Como generar un Excel real requiere una librería como PhpSpreadsheet,
        // aquí simplemente redirigimos a la función CSV que es más simple
        return $this->exportToCsv($orderIds, $orderTables);
    }

    /**
     * Acción para procesar todos los productos de una orden
     *
     * @return JsonModel
     */
    public function processAllProductsAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return new JsonModel([
                'success' => false,
                'message' => 'Usuario no autenticado'
            ]);
        }
        
        // Verificar si es una petición Ajax
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return new JsonModel([
                'success' => false,
                'message' => 'Método no permitido'
            ]);
        }
        
        // Obtener datos del JSON enviado
        $content = $this->getRequest()->getContent();
        $data = json_decode($content, true);

        if (!$data) {
            // Intentar obtener de POST tradicional
            $data = [
                'id' => $this->params()->fromPost('id'),
                'table' => $this->params()->fromPost('table')
            ];
        }

        // Extraer parámetros
        $id = $data['id'] ?? null;
        $table = $data['table'] ?? null;
        
        if (!$id || !$table) {
            return new JsonModel([
                'success' => false,
                'message' => 'Parámetros incompletos'
            ]);
        }
        
        try {
            // Para Orders_PARIS, no hay tabla física donde actualizar
            if (strtoupper($table) === 'ORDERS_PARIS') {
                return new JsonModel([
                    'success' => true,
                    'message' => 'Productos marcados como procesados (simulado para París)'
                ]);
            }
            
            // Obtener datos actuales
            $order = $this->databaseService->fetchOne(
                "SELECT productos FROM `$table` WHERE id = ?",
                [$id]
            );
            
            if (!$order) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Orden no encontrada'
                ]);
            }
            
            // Procesar productos
            $productos = $order['productos'];
            if (!is_array($productos)) {
                $productos = json_decode($productos, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($productos)) {
                    return new JsonModel([
                        'success' => false,
                        'message' => 'Formato de productos no válido'
                    ]);
                }
            }
            
            // Marcar todos los productos como procesados
            foreach ($productos as &$producto) {
                $producto['procesado'] = true;
            }
            
            // Actualizar la orden
            $rowsUpdated = $this->databaseService->execute(
                "UPDATE `$table` SET productos = ?, procesado = 1 WHERE id = ?",
                [json_encode($productos), $id]
            );
            
            if ($rowsUpdated > 0) {
                return new JsonModel([
                    'success' => true,
                    'message' => 'Todos los productos marcados como procesados'
                ]);
            } else {
                return new JsonModel([
                    'success' => false,
                    'message' => 'No se pudo actualizar la orden'
                ]);
            }
        } catch (\Exception $e) {
            return new JsonModel([
                'success' => false,
                'message' => 'Error al actualizar: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Actualiza el estado procesado de una orden a 1
     * 
     * @return \Laminas\Http\Response
     */
    public function updateOrderProcessedStatusAction()
    {
        // Configurar respuesta HTTP
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');

        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            $response->setContent(json_encode([
    'success' => false,
            'message' => 'Usuario no autenticado'
        ]));
        return $response;
    }

    // Obtener datos de la solicitud
    $data = json_decode($this->getRequest()->getContent(), true);
    $orderId = $data['orderId'] ?? '';
    $table = $data['table'] ?? '';

    // Verificar que los parámetros requeridos estén presentes
    if (empty($orderId) || empty($table)) {
        $response->setContent(json_encode([
            'success' => false,
            'message' => 'Faltan parámetros requeridos (orderId o table)'
        ]));
        return $response;
    }

    try {
        // Para Orders_PARIS, no hay tabla física donde actualizar
        if (strtoupper($table) === 'ORDERS_PARIS') {
            // Registrar en historial si existe la tabla
            try {
                $username = $this->authService->getIdentity();
                $this->databaseService->execute(
                    "INSERT INTO `historial` (tabla, orden_id, accion, usuario, fecha_accion) VALUES (?, ?, ?, ?, NOW())",
                    ['Orders_PARIS', $orderId, 'Marcada como procesada (simulado)', $username]
                );
            } catch (\Exception $e) {
                // Ignorar errores de historial pero registrarlos
                error_log("Error al guardar historial: " . $e->getMessage());
            }

            $response->setContent(json_encode([
                'success' => true,
                'message' => 'Orden marcada como procesada (simulado para París)',
                'affectedRows' => 1
            ]));
            return $response;
        }

        // Registrar para depuración
        error_log("Intentando marcar como procesada la orden ID: $orderId en tabla: $table");

        // Método seguro usando parámetros preparados
        $sql = "UPDATE `$table` SET procesado = 1 WHERE id = ?";
        $result = $this->databaseService->execute($sql, [$orderId]);
        
        // Verificar si la actualización afectó alguna fila
        if ($result > 0) {
            // Registrar en historial
            try {
                $username = $this->authService->getIdentity();
                $this->databaseService->execute(
                    "INSERT INTO `historial` (tabla, orden_id, accion, usuario, fecha_accion) VALUES (?, ?, ?, ?, NOW())",
                    [$table, $orderId, 'Marcada como procesada', $username]
                );
            } catch (\Exception $e) {
                // Ignorar errores de historial pero registrarlos
                error_log("Error al guardar historial: " . $e->getMessage());
            }

            $response->setContent(json_encode([
                'success' => true,
                'message' => 'Orden marcada como procesada correctamente',
                'affectedRows' => $result
            ]));
        } else {
            // No se actualizó ninguna fila - posiblemente el ID no existe
            $response->setContent(json_encode([
                'success' => false,
                'message' => 'No se encontró la orden o ya estaba procesada',
                'affectedRows' => 0
            ]));
        }
    } catch (\Exception $e) {
        // Registrar el error
        error_log("Error al marcar orden como procesada: " . $e->getMessage());
        
        $response->setContent(json_encode([
            'success' => false,
            'message' => 'Error al actualizar: ' . $e->getMessage()
        ]));
    }

    return $response;
}

    public function markProductProcessedAction()
    {
        // Configurar respuesta HTTP
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        
        // Obtener datos de la solicitud
        $content = $this->getRequest()->getContent();
        $data = json_decode($content, true);
        
        if (!$data) {
            $response->setContent(json_encode([
                'success' => false,
                'message' => 'Datos inválidos'
            ]));
            return $response;
        }
        
        // Extraer parámetros
        $orderId = $data['orderId'] ?? null;
        $table = $data['table'] ?? null;
        $sku = $data['sku'] ?? null;
        
        if (!$orderId || !$table || !$sku) {
            $response->setContent(json_encode([
                'success' => false,
                'message' => 'Parámetros incompletos'
            ]));
            return $response;
        }
        
        try {
            // Para Orders_PARIS, simular el procesamiento
            if (strtoupper($table) === 'ORDERS_PARIS') {
                $response->setContent(json_encode([
                    'success' => true,
                    'message' => 'Producto marcado como procesado (simulado para París)',
                    'allProcessed' => false
                ]));
                return $response;
            }
            
            // Obtener productos de la orden
            $sql = "SELECT id, productos FROM `$table` WHERE id = ?";
            $result = $this->databaseService->fetchOne($sql, [$orderId]);
            
            if (!$result) {
                $response->setContent(json_encode([
                    'success' => false,
                    'message' => 'Orden no encontrada'
                ]));
                return $response;
            }
            
            $orderRealId = $result['id'];
            $productos = json_decode($result['productos'] ?? '[]', true);
            
            // Si productos no es un array, inicializarlo como array vacío
            if (!is_array($productos)) {
                $productos = [];
            }
            
            // Marcar el producto con el SKU como procesado
            $updated = false;
            foreach ($productos as $key => $producto) {
                if (isset($producto['sku']) && $producto['sku'] === $sku) {
                    $productos[$key]['procesado'] = 1;
                    $updated = true;
                    break;
                }
            }
            
            // Si no se actualizó ningún producto, posiblemente el SKU no existe en la orden
            if (!$updated) {
                $response->setContent(json_encode([
                    'success' => false,
                    'message' => 'Producto no encontrado en la orden'
                ]));
                return $response;
            }
            
            // Actualizar productos en la orden
            $sql = "UPDATE `$table` SET productos = ? WHERE id = ?";
            $this->databaseService->execute($sql, [json_encode($productos), $orderRealId]);
            
            // Verificar si todos los productos están procesados
            $allProcessed = true;
            foreach ($productos as $producto) {
                if (!isset($producto['procesado']) || $producto['procesado'] != 1) {
                    $allProcessed = false;
                    break;
                }
            }
            
            // Si todos los productos están procesados, marcar la orden como procesada
            if ($allProcessed) {
                $sql = "UPDATE `$table` SET procesado = 1 WHERE id = ?";
                $this->databaseService->execute($sql, [$orderRealId]);
            }
            
            // Responder con éxito
            $response->setContent(json_encode([
                'success' => true,
                'message' => 'Producto marcado como procesado',
                'allProcessed' => $allProcessed
            ]));
            return $response;
        } catch (\Exception $e) {
            // Registrar error y responder
            error_log('Error al marcar producto como procesado: ' . $e->getMessage());
            $response->setContent(json_encode([
                'success' => false,
                'message' => 'Error al actualizar: ' . $e->getMessage()
            ]));
            return $response;
        }
    }

    public function markAsPrintedAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return new JsonModel([
                'success' => false,
                'message' => 'Usuario no autenticado'
            ]);
        }

        // Obtener datos del JSON enviado
        $content = $this->getRequest()->getContent();
        $data = json_decode($content, true);

        if (!$data) {
            // Intentar obtener de POST tradicional
            $data = [
                'id' => $this->params()->fromPost('id'),
                'table' => $this->params()->fromPost('table')
            ];
        }

        // Extraer parámetros
        $orderId = $data['id'] ?? null;
        $table = $data['table'] ?? null;

        if (!$orderId || !$table) {
            return new JsonModel([
                'success' => false,
                'message' => 'Parámetros incompletos'
            ]);
        }

        try {
            // Para Orders_PARIS, marcar como impresa
            if (strtoupper($table) === 'ORDERS_PARIS') {
                // Actualizar la tabla paris_orders
                $rowsUpdated = $this->databaseService->execute(
                    "UPDATE paris_orders SET orden_impresa = 1 WHERE subOrderNumber = ?",
                    [$orderId]
                );
                
                // Registrar en historial si existe la tabla
                try {
                    $username = $this->authService->getIdentity();
                    $this->databaseService->execute(
                        "INSERT INTO `historial` (tabla, orden_id, accion, usuario, fecha_accion) VALUES (?, ?, ?, ?, NOW())",
                        ['Orders_PARIS', $orderId, 'Marcada como impresa', $username]
                    );
                } catch (\Exception $e) {
                    // Si hay error al guardar historial, continuar de todos modos
                }

                return new JsonModel([
                    'success' => true,
                    'message' => 'Orden marcada como impresa correctamente'
                ]);
            }
            
            // Actualizar la orden como impresa
            $rowsUpdated = $this->databaseService->execute(
                "UPDATE `$table` SET printed = 1 WHERE id = ?",
                [$orderId]
            );

            if ($rowsUpdated > 0) {
                // Registrar en historial si existe la tabla
                try {
                    $username = $this->authService->getIdentity();
                    $this->databaseService->execute(
                        "INSERT INTO `historial` (tabla, orden_id, accion, usuario, fecha_accion) VALUES (?, ?, ?, ?, NOW())",
                        [$table, $orderId, 'Marcada como impresa', $username]
                    );
                } catch (\Exception $e) {
                    // Si hay error al guardar historial, continuar de todos modos
                }

                return new JsonModel([
                    'success' => true,
                    'message' => 'Orden marcada como impresa correctamente'
                ]);
            } else {
                return new JsonModel([
                    'success' => false,
                    'message' => 'No se pudo actualizar la orden'
                ]);
            }
        } catch (\Exception $e) {
            return new JsonModel([
                'success' => false,
                'message' => 'Error al actualizar: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Acción para marcar una orden como procesada
     *
     * @return JsonModel
     */
    public function markAsProcessedAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return new JsonModel([
                'success' => false,
                'message' => 'Usuario no autenticado'
            ]);
        }

        // Obtener datos del JSON enviado
        $content = $this->getRequest()->getContent();
        $data = json_decode($content, true);

        if (!$data) {
            // Intentar obtener de POST tradicional
            $data = [
                'id' => $this->params()->fromPost('id'),
                'table' => $this->params()->fromPost('table')
            ];
        }

        // Extraer parámetros
        $orderId = $data['id'] ?? null;
        $table = $data['table'] ?? null;

        if (!$orderId || !$table) {
            return new JsonModel([
                'success' => false,
                'message' => 'Parámetros incompletos'
            ]);
        }

        try {
            // Para Orders_PARIS, marcar como procesada
            if (strtoupper($table) === 'ORDERS_PARIS') {
                // Actualizar la tabla paris_orders
                $rowsUpdated = $this->databaseService->execute(
                    "UPDATE paris_orders SET orden_procesada = 1 WHERE subOrderNumber = ?",
                    [$orderId]
                );
                
                // Registrar en historial si existe la tabla
                try {
                    $username = $this->authService->getIdentity();
                    $this->databaseService->execute(
                        "INSERT INTO `historial` (tabla, orden_id, accion, usuario, fecha_accion) VALUES (?, ?, ?, ?, NOW())",
                        ['Orders_PARIS', $orderId, 'Marcada como procesada', $username]
                    );
                } catch (\Exception $e) {
                    // Si hay error al guardar historial, continuar de todos modos
                }

                return new JsonModel([
                    'success' => true,
                    'message' => 'Orden marcada como procesada correctamente'
                ]);
            }
            
            // Actualizar la orden como procesada en otros marketplaces
            $rowsUpdated = $this->databaseService->execute(
                "UPDATE `$table` SET procesado = 1 WHERE id = ?",
                [$orderId]
            );

            if ($rowsUpdated > 0) {
                // Registrar en historial si existe la tabla
                try {
                    $username = $this->authService->getIdentity();
                    $this->databaseService->execute(
                        "INSERT INTO `historial` (tabla, orden_id, accion, usuario, fecha_accion) VALUES (?, ?, ?, ?, NOW())",
                        [$table, $orderId, 'Marcada como procesada', $username]
                    );
                } catch (\Exception $e) {
                    // Si hay error al guardar historial, continuar de todos modos
                }

                return new JsonModel([
                    'success' => true,
                    'message' => 'Orden marcada como procesada correctamente'
                ]);
            } else {
                return new JsonModel([
                    'success' => false,
                    'message' => 'No se pudo actualizar la orden'
                ]);
            }
        } catch (\Exception $e) {
            return new JsonModel([
                'success' => false,
                'message' => 'Error al actualizar: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Acción para actualizar el estado general de una orden
     *
     * @return JsonModel
     */
    public function updateOrderStatusAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return new JsonModel([
                'success' => false,
                'message' => 'Usuario no autenticado'
            ]);
        }

        // Verificar si es una petición Ajax
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return new JsonModel([
                'success' => false,
                'message' => 'Método no permitido'
            ]);
        }

        // Obtener datos del JSON enviado
        $content = $this->getRequest()->getContent();
        $data = json_decode($content, true);

        if (!$data) {
            return new JsonModel([
                'success' => false,
                'message' => 'Datos inválidos'
            ]);
        }

        // Extraer parámetros
        $orderId = $data['orderId'] ?? null;
        $table = $data['table'] ?? null;
        $newStatus = $data['newStatus'] ?? null;

        if (!$orderId || !$table || !$newStatus) {
            return new JsonModel([
                'success' => false,
                'message' => 'Parámetros incompletos'
            ]);
        }

        try {
            // Para Orders_PARIS, simular la actualización de estado
            if (strtoupper($table) === 'ORDERS_PARIS') {
                // Registrar en historial
                try {
                    $username = $this->authService->getIdentity();
                    $this->databaseService->execute(
                        "INSERT INTO `historial` (tabla, orden_id, accion, usuario, fecha_accion) VALUES (?, ?, ?, ?, NOW())",
                        ['Orders_PARIS', $orderId, "Estado actualizado a '$newStatus' (simulado)", $username]
                    );
                } catch (\Exception $e) {
                    // Si hay error al guardar historial, continuar de todos modos
                }

                return new JsonModel([
                    'success' => true,
                    'message' => 'Estado actualizado correctamente (simulado para París)'
                ]);
            }
            
            // Actualizar el estado de la orden
            $rowsUpdated = $this->databaseService->execute(
                "UPDATE `$table` SET estado = ? WHERE id = ?",
                [$newStatus, $orderId]
            );

            if ($rowsUpdated > 0) {
                // Registrar en historial
                try {
                    $username = $this->authService->getIdentity();
                    $this->databaseService->execute(
                        "INSERT INTO `historial` (tabla, orden_id, accion, usuario, fecha_accion) VALUES (?, ?, ?, ?, NOW())",
                        [$table, $orderId, "Estado actualizado a '$newStatus'", $username]
                    );
                } catch (\Exception $e) {
                    // Si hay error al guardar historial, continuar de todos modos
                }

                return new JsonModel([
                    'success' => true,
                    'message' => 'Estado actualizado correctamente'
                ]);
            } else {
                return new JsonModel([
                    'success' => false,
                    'message' => 'No se pudo actualizar la orden'
                ]);
            }
        } catch (\Exception $e) {
            return new JsonModel([
                'success' => false,
                'message' => 'Error al actualizar: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Acción para actualizar el transportista y número de seguimiento de una orden
     *
     * @return JsonModel
     */
    public function updateOrderCarrierAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return new JsonModel([
                'success' => false,
                'message' => 'Usuario no autenticado'
            ]);
        }

        // Verificar si es una petición Ajax
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return new JsonModel([
                'success' => false,
                'message' => 'Método no permitido'
            ]);
        }

        // Obtener datos del JSON enviado
        $content = $this->getRequest()->getContent();
        $data = json_decode($content, true);

        if (!$data) {
            return new JsonModel([
                'success' => false,
                'message' => 'Datos inválidos'
            ]);
        }

        // Extraer parámetros
        $orderId = $data['orderId'] ?? null;
        $table = $data['table'] ?? null;
        $newCarrier = $data['newCarrier'] ?? null;
        $trackingNumber = $data['trackingNumber'] ?? '';
        $updateStatus = $data['updateStatus'] ?? false;

        if (!$orderId || !$table || !$newCarrier) {
            return new JsonModel([
                'success' => false,
                'message' => 'Parámetros incompletos'
            ]);
        }

        try {
            // Para Orders_PARIS, simular la actualización de transportista
            if (strtoupper($table) === 'ORDERS_PARIS') {
                // Registrar en historial
                try {
                    $username = $this->authService->getIdentity();
                    $accion = "Transportista actualizado a '$newCarrier' (simulado)";
                    if (!empty($trackingNumber)) {
                        $accion .= ", seguimiento: $trackingNumber";
                    }

                    $this->databaseService->execute(
                        "INSERT INTO `historial` (tabla, orden_id, accion, usuario, fecha_accion) VALUES (?, ?, ?, ?, NOW())",
                        ['Orders_PARIS', $orderId, $accion, $username]
                    );
                } catch (\Exception $e) {
                    // Si hay error al guardar historial, continuar de todos modos
                }

                return new JsonModel([
                    'success' => true,
                    'message' => 'Información de entrega actualizada correctamente (simulado para París)'
                ]);
            }
            
            // Construir consulta SQL dinámica según los parámetros
            $sql = "UPDATE `$table` SET transportista = ?, num_seguimiento = ?, fecha_entrega = NOW()";
            $params = [$newCarrier, $trackingNumber];

            // Si se debe actualizar el estado también
            if ($updateStatus) {
                $sql .= ", estado = 'Entregado'";
            }

            $sql .= " WHERE id = ?";
            $params[] = $orderId;

            // Ejecutar la actualización
            $rowsUpdated = $this->databaseService->execute($sql, $params);

            if ($rowsUpdated > 0) {
                // Registrar en historial
                try {
                    $username = $this->authService->getIdentity();
                    $accion = "Transportista actualizado a '$newCarrier'";
                    if (!empty($trackingNumber)) {
                        $accion .= ", seguimiento: $trackingNumber";
                    }

                    $this->databaseService->execute(
                        "INSERT INTO `historial` (tabla, orden_id, accion, usuario, fecha_accion) VALUES (?, ?, ?, ?, NOW())",
                        [$table, $orderId, $accion, $username]
                    );
                } catch (\Exception $e) {
                    // Si hay error al guardar historial, continuar de todos modos
                }

                return new JsonModel([
                    'success' => true,
                    'message' => 'Información de entrega actualizada correctamente'
                ]);
            } else {
                return new JsonModel([
                    'success' => false,
                    'message' => 'No se pudo actualizar la orden'
                ]);
            }
        } catch (\Exception $e) {
            return new JsonModel([
                'success' => false,
                'message' => 'Error al actualizar: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Obtiene los nombres reales de productos desde la base de datos
     *
     * @param array $skuList Lista de SKUs a buscar
     * @param array $productMap Mapa de SKU -> nombre_producto previamente encontrados
     * @return array Mapa de SKU -> información del producto
     */
    private function getProductInfo(array $skuList, array $productMap = [])
    {
        if (empty($skuList)) {
            return [];
        }

        // Eliminar duplicados y valores vacíos
        $skuList = array_filter(array_unique($skuList), function($sku) {
            return !empty(trim($sku));
        });

        if (empty($skuList)) {
            return [];
        }

        $productInfo = [];

        // Inicializar productInfo con valores por defecto y con los nombres disponibles en el productMap
        // Esto garantiza que todas las SKUs tengan al menos un registro básico
        foreach ($skuList as $sku) {
            // Si ya tenemos un nombre para este SKU en productMap, lo usamos
            if (isset($productMap[$sku])) {
                $productInfo[$sku] = [
                    'nombre' => $productMap[$sku],  // Usar nombre ya encontrado en la tabla
                    'descripcion' => '',
                    'ubicacion' => '',
                    'codigo_barras' => '',
                    'referencia' => ''
                ];
            } else {
                $productInfo[$sku] = [
                    'nombre' => 'Producto ' . $sku,  // Valor por defecto que se sobrescribirá si se encuentra
                    'descripcion' => '',
                    'ubicacion' => '',
                    'codigo_barras' => '',
                    'referencia' => ''
                ];
            }
        }

        try {
            // Intentar primero buscar en tabla 'productos'
            $productsTable = 'productos';
            // Evitar el error de undefined array key usando parámetros con nombres
            $params = [];
            $placeholders = [];

            $i = 0;
            foreach ($skuList as $sku) {
                $paramName = 'sku' . $i;
                $placeholders[] = ':' . $paramName;
                $params[$paramName] = $sku;
                $i++;
            }

            $placeholdersStr = implode(',', $placeholders);
            $productData = $this->databaseService->fetchAll(
                "SELECT * FROM `$productsTable` WHERE sku IN ($placeholdersStr)",
                $params
            );

            // Si encontramos productos, sobrescribir la información
            if (!empty($productData)) {
                foreach ($productData as $product) {
                    $sku = $product['sku'] ?? '';
                    if (!empty($sku) && isset($productInfo[$sku])) {
                        $productInfo[$sku] = [
                            'nombre' => $product['nombre'] ?? ($product['descripcion'] ?? 'Producto ' . $sku),
                            'descripcion' => $product['descripcion'] ?? '',
                            'ubicacion' => $product['ubicacion'] ?? '',
                            'codigo_barras' => $product['codigo_barras'] ?? '',
                            'referencia' => $product['referencia'] ?? ''
                        ];
                    }
                }
                // Aquí NO retornamos inmediatamente, intentamos también en catalog
            }

            // Intentar buscar en tabla 'catalog'
            $catalogTable = 'catalog';
            try {
                $productData = $this->databaseService->fetchAll(
                    "SELECT * FROM `$catalogTable` WHERE sku IN ($placeholdersStr)",
                    $params
                );

                if (!empty($productData)) {
                    foreach ($productData as $product) {
                        $sku = $product['sku'] ?? '';
                        if (!empty($sku)) {
                            // Si el nombre del producto ya no es genérico, no lo sobrescribimos
                            $currentName = $productInfo[$sku]['nombre'] ?? '';
                            $isGeneric = empty($currentName) || strpos($currentName, 'Producto ') === 0;

                            // Solo sobrescribimos si el nombre actual es genérico o el nuevo tiene más información
                            $newName = $product['nombre'] ?? ($product['name'] ?? ($product['description'] ?? ''));
                            if ($isGeneric || (!empty($newName) && $newName !== $currentName)) {
                                $productInfo[$sku] = [
                                    'nombre' => $newName,
                                    'descripcion' => $product['descripcion'] ?? ($product['description'] ?? ''),
                                    'ubicacion' => $product['ubicacion'] ?? ($product['location'] ?? ''),
                                    'codigo_barras' => $product['codigo_barras'] ?? ($product['barcode'] ?? ''),
                                    'referencia' => $product['referencia'] ?? ($product['reference'] ?? '')
                                ];
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Si hay error al consultar catalog, continuamos con lo que ya tenemos
            }

            // Intentar buscar en otras fuentes si es necesario (por ejemplo, Products_PARIS)
            // Comprobar si hay productos que aún tienen nombre genérico
            $genericSkus = [];
            foreach ($productInfo as $sku => $info) {
                if (strpos($info['nombre'], 'Producto ') === 0) {
                    $genericSkus[] = $sku;
                }
            }

            if (!empty($genericSkus)) {
                try {
                    // Buscar en orders de marketplace específico
                    $otherTables = ['Products_PARIS', 'Products_RIPLEY', 'Products_FALABELLA', 'Products_MERCADO_LIBRE'];

                    foreach ($otherTables as $otherTable) {
                        // Crear nuevos parámetros con nombres para evitar problemas de índice
                        $otherParams = [];
                        $otherPlaceholders = [];

                        $i = 0;
                        foreach ($genericSkus as $sku) {
                            $paramName = 'gsku' . $i;
                            $otherPlaceholders[] = ':' . $paramName;
                            $otherParams[$paramName] = $sku;
                            $i++;
                        }

                        $otherPlaceholdersStr = implode(',', $otherPlaceholders);

                        try {
                            $otherData = $this->databaseService->fetchAll(
                                "SELECT * FROM `$otherTable` WHERE sku IN ($otherPlaceholdersStr)",
                                $otherParams
                            );

                            if (!empty($otherData)) {
                                foreach ($otherData as $product) {
                                    $sku = $product['sku'] ?? '';
                                    if (!empty($sku) && isset($productInfo[$sku])) {
                                        // Solo sobrescribimos si el nombre sigue siendo genérico
                                        if (strpos($productInfo[$sku]['nombre'], 'Producto ') === 0) {
                                            $productInfo[$sku]['nombre'] = $product['nombre'] ??
                                                                         ($product['name'] ??
                                                                         ($product['descripcion'] ??
                                                                         ($product['description'] ?? 'Producto ' . $sku)));

                                            if (!empty($product['descripcion'])) {
                                                $productInfo[$sku]['descripcion'] = $product['descripcion'];
                                            } else if (!empty($product['description'])) {
                                                $productInfo[$sku]['descripcion'] = $product['description'];
                                            }
                                        }
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            // Si hay error al consultar esta tabla, continuamos con la siguiente
                            continue;
                        }
                    }
                } catch (\Exception $e) {
                    // Si hay error en esta sección, continuamos con lo que ya tenemos
                }
            }
        } catch (\Exception $e) {
            // Error al consultar, pero retornamos lo que ya tenemos inicializado
        }

        return $productInfo;
    }

    /**
     * Acción para mostrar órdenes de un marketplace específico (Vista detallada)
     *
     * @return ViewModel|\Laminas\Http\Response
     */
    public function ordersDetailAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }

        // Obtener tabla desde el parámetro de ruta
        $table = $this->params()->fromRoute('table', null);

        if (empty($table)) {
            return $this->notFoundAction();
        }

        // Configuración de paginación
        $page = (int) $this->params()->fromQuery('page', 1);
        $limit = (int) $this->params()->fromQuery('limit', 25);

        // Filtros disponibles
        $filters = [];
        // Filtro de búsqueda
        $search = $this->params()->fromQuery('search', '');
        if (!empty($search)) {
            $filters['search'] = $search;
        }

        // Filtro de estado
        $status = $this->params()->fromQuery('status', '');
        if (!empty($status)) {
            $filters['status'] = $status;
        }

        // Filtro de impresión
        $printed = $this->params()->fromQuery('printed', '');
        if ($printed !== '') {
            $filters['printed'] = $printed;
        }

        // Usar la misma lógica de indexAction para manejar diferentes marketplaces
        $marketplaceId = strtoupper($table);
        
        switch ($marketplaceId) {
            case 'ORDERS_PARIS':
                return $this->handleParisOrders($page, $limit, $filters);
                
            case 'ORDERS_FALABELLA':
                // return $this->handleFalabellaOrders($page, $limit, $filters);
                
            case 'ORDERS_RIPLEY':
                // return $this->handleRipleyOrders($page, $limit, $filters);
                
            case 'ORDERS_WALLMART':
                // return $this->handleWallmartOrders($page, $limit, $filters);
                
            case 'ORDERS_MERCADO_LIBRE':
                // return $this->handleMercadoLibreOrders($page, $limit, $filters);
                
            case 'ORDERS_WOOCOMMERCE':
                // return $this->handleWooCommerceOrders($page, $limit, $filters);
        }

        // Para marketplaces no migrados aún, usar el método original
        $data = $this->getOrdersWithPagination($table, $page, $limit, $filters);

        // Calcular estadísticas para los KPIs
        $sinImprimir = 0;
        $impresosNoProcesados = 0;
        $procesados = 0;

        try {
            // Contar órdenes sin imprimir
            $sinImprimirResult = $this->databaseService->fetchOne(
                "SELECT COUNT(*) as total FROM `$table` WHERE printed = 0 OR printed IS NULL"
            );
            $sinImprimir = $sinImprimirResult ? (int)$sinImprimirResult['total'] : 0;

            // Contar órdenes impresas pero no procesadas
            $impresosNoProcesadosResult = $this->databaseService->fetchOne(
                "SELECT COUNT(*) as total FROM `$table` WHERE printed = 1 AND (procesado = 0 OR procesado IS NULL)"
            );
            $impresosNoProcesados = $impresosNoProcesadosResult ? (int)$impresosNoProcesadosResult['total'] : 0;

            // Contar órdenes procesadas
            $procesadosResult = $this->databaseService->fetchOne(
                "SELECT COUNT(*) as total FROM `$table` WHERE procesado = 1"
            );
            $procesados = $procesadosResult ? (int)$procesadosResult['total'] : 0;
        } catch (\Exception $e) {
            // En caso de error, mantener los valores predeterminados
        }

        // Recopilar todos los SKUs de las órdenes y extraer nombres de productos cuando estén disponibles
        $allSkus = [];
        $productMap = []; // Mapa de SKU -> nombre_producto para referencias rápidas

        foreach ($data['orders'] as &$order) {
            // Si hay un nombre_producto disponible en la orden, guardarlo
            if (isset($order['nombre_producto']) && !empty($order['nombre_producto'])) {
                if (isset($order['codigo_sku']) && !empty($order['codigo_sku'])) {
                    $productMap[$order['codigo_sku']] = $order['nombre_producto'];
                }
            }

            // Extraer SKUs del campo sku de cada orden
            if (isset($order['sku']) && !empty($order['sku'])) {
                if (is_string($order['sku'])) {
                    // Intentar decodificar como JSON
                    $skuArray = json_decode($order['sku'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($skuArray)) {
                        // Es un JSON válido, extraer SKUs
                        foreach ($skuArray as $skuItem) {
                            if (is_string($skuItem)) {
                                $allSkus[] = trim($skuItem);
                            } else if (is_array($skuItem) && isset($skuItem['sku'])) {
                                $allSkus[] = trim($skuItem['sku']);
                            }
                        }
                    } else {
                        // Asumir lista separada por comas
                        $skuList = explode(',', $order['sku']);
                        foreach ($skuList as $sku) {
                            $allSkus[] = trim($sku);
                        }
                    }
                }
            }

            // Extraer SKUs del campo productos si existe
            if (isset($order['productos'])) {
                $productos = $order['productos'];
                if (is_string($productos)) {
                    $productos = json_decode($productos, true);
                }

                if (is_array($productos)) {
                    foreach ($productos as $producto) {
                        if (isset($producto['sku']) && !empty($producto['sku'])) {
                            $allSkus[] = trim($producto['sku']);
                        }
                    }
                }
            }
        }

        // Obtener información de productos para todos los SKUs encontrados
        $productInfo = $this->getProductInfo($allSkus, $productMap);

        // Preparar datos para la vista
        return new ViewModel([
            'table' => $table,
            'orders' => $data['orders'],
            'totalItems' => $data['totalItems'],
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($data['totalItems'] / $limit),
            'filters' => $filters,
            'search' => $search,
            'statusFilter' => $status,
            'printedFilter' => $printed,
            'total' => $data['totalItems'],
            'sinImprimir' => $sinImprimir,
            'impresosNoProcesados' => $impresosNoProcesados,
            'procesados' => $procesados,
            'productInfo' => $productInfo // Pasar información de nombres de productos a la vista
        ]);
    }

    /**
     * Acción para ver el detalle de una orden específica
     *
     * @return ViewModel|\Laminas\Http\Response
     */
    /**
     * Acción para listar órdenes de Paris
     */
    public function parisOrdersAction()
    {
        // Log para depuración
        error_log("============ INICIO parisOrdersAction ============");
        error_log("REQUEST_URI: " . $_SERVER['REQUEST_URI']);
        
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            error_log("Autenticación fallida, redirigiendo");
            return $redirect;
        }

        // Si se proporciona un ID en la URL (sin usar la ruta correcta), intentar manejarlo directamente
        $request = $this->getRequest();
        $requestUri = $request->getRequestUri();
        
        // Verificar si hay un ID en la URL después de paris-order/
        if (preg_match('#/paris-order/(\d+)$#', $requestUri, $matches)) {
            $id = $matches[1];
            error_log("Se detectó ID en la URL: $id");
            
            // Redireccionar a la ruta correcta de detalle de orden Paris
            return $this->redirect()->toRoute('paris-order/detail', ['id' => $id]);
        }

        // Redirigir a la vista de órdenes con la tabla de Paris
        error_log("Redirigiendo a la lista de órdenes de Paris");
        error_log("============ FIN parisOrdersAction ============");
        return $this->redirect()->toRoute('orders', [
            'action' => 'orders-detail',
            'table' => 'Orders_PARIS'
        ]);
    }

    /**
     * Acción específica para mostrar detalles de órdenes de Paris
     */
    public function parisOrderDetailAction()
    {
        // Añadir logs extensivos para depuración
        error_log("============ INICIO parisOrderDetailAction ============");
        error_log("REQUEST_URI: " . $_SERVER['REQUEST_URI']);
        error_log("Params: " . json_encode($this->params()->fromRoute()));
        
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            error_log("Autenticación fallida, redirigiendo");
            return $redirect;
        }

        // Obtener ID de pedido de Paris
        $subOrderNumber = $this->params()->fromRoute('id', null);
        error_log("ID de pedido recibido: $subOrderNumber");

        if (!$subOrderNumber) {
            error_log("ERROR: ID de pedido no proporcionado");
            return $this->notFoundAction();
        }

        try {
            // Log para depuración
            error_log("Procesando orden de Paris con ID: $subOrderNumber");
            $result = $this->handleParisOrderDetail($subOrderNumber);
            error_log("Orden de Paris procesada correctamente");
            
            // Configurar vista para usar orden-detail.phtml
            $result->setTemplate('application/orders/order-detail');
            return $result;
        } catch (\Exception $e) {
            // Log detallado para depuración
            error_log("ERROR en parisOrderDetailAction: " . $e->getMessage());
            error_log("Trace: " . $e->getTraceAsString());
            
            // Volver a la vista con el error
            $viewModel = new ViewModel([
                'order' => [
                    'id' => $subOrderNumber,
                    'suborder_number' => $subOrderNumber,
                    'estado' => 'Error',
                    'cliente' => 'No disponible',
                    'fecha_compra' => date('Y-m-d H:i:s'),
                    'printed' => 0,
                    'procesado' => 0
                ],
                'clientInfo' => [],
                'table' => 'Orders_PARIS',
                'marketplace' => 'PARIS',
                'products' => [],
                'envio' => 0,
                'impuesto' => 0,
                'subtotal' => 0,
                'total' => 0,
                'deliveryInfo' => [],
                'isDirectQuery' => true,
                'error' => $e->getMessage()
            ]);
            $viewModel->setTemplate('application/orders/order-detail');
            return $viewModel;
        } finally {
            error_log("============ FIN parisOrderDetailAction ============");
        }
    }
    
    /**
     * Acción para mostrar el detalle de una orden específica
     */
    public function orderDetailAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }

        // Obtener parámetros de ruta
        $id = $this->params()->fromRoute('id', null);
        $table = $this->params()->fromRoute('table', null);

        if (!$id || !$table) {
            return $this->notFoundAction();
        }

        // Para Orders_PARIS, obtener información completa usando consulta directa
        if (strtoupper($table) === 'ORDERS_PARIS') {
            // Debug log
            error_log("OrdersController: Procesando orden de Paris con ID: $id y tabla: $table");
            
            try {
                return $this->handleParisOrderDetail($id);
            } catch (\Exception $e) {
                // Log detallado para depuración
                error_log("Error en OrdersController->handleParisOrderDetail: " . $e->getMessage());
                
                // Volver a la vista con el error
                return new ViewModel([
                    'order' => [
                        'id' => $id,
                        'suborder_number' => $id,
                        'estado' => 'Error',
                        'cliente' => 'No disponible',
                        'fecha_compra' => date('Y-m-d H:i:s'),
                        'printed' => 0,
                        'procesado' => 0
                    ],
                    'clientInfo' => [],
                    'table' => $table,
                    'marketplace' => 'PARIS',
                    'products' => [],
                    'envio' => 0,
                    'impuesto' => 0,
                    'subtotal' => 0,
                    'total' => 0,
                    'deliveryInfo' => [],
                    'isDirectQuery' => true,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Obtener detalles de la orden
        $order = $this->databaseService->fetchOne(
            "SELECT * FROM `$table` WHERE id = ?",
            [$id]
        );

        if (!$order) {
            return $this->notFoundAction();
        }

        // Extraer el marketplace del nombre de la tabla
        $marketplace = str_replace('Orders_', '', $table);

        // Si es PARIS, buscar nombre del producto en MKP_PARIS
        if ($marketplace === 'PARIS' && isset($order['suborder_number']) && !empty($order['suborder_number'])) {
            try {
                $mkpOrder = $this->databaseService->fetchOne(
                    "SELECT nombre_producto, codigo_sku FROM MKP_PARIS WHERE numero_suborden = ?",
                    [$order['suborder_number']]
                );

                if ($mkpOrder && isset($mkpOrder['nombre_producto']) && !empty($mkpOrder['nombre_producto'])) {
                    // Añadir los datos del nombre del producto a la orden
                    $order['nombre_producto'] = $mkpOrder['nombre_producto'];
                    $order['codigo_sku'] = $mkpOrder['codigo_sku'] ?? '';
                }
            } catch (\Exception $e) {
                // Si hay un error, continuamos sin el nombre del producto
            }
        }

        // Preparar información del cliente
        $clientInfo = [
            'nombre' => $order['cliente'] ?? $order['customer_name'] ?? $order['nombre_cliente'] ?? 'Cliente',
            'rut' => $order['rut_cliente'] ?? '',
            'telefono' => $order['telefono'] ?? '',
            'direccion' => $order['direccion'] ?? $order['direccion_envio'] ?? '',
            'comuna' => $order['comuna'] ?? '',
            'region' => $order['region'] ?? '',
            'email' => $order['email'] ?? $order['correo'] ?? '',
        ];

        // Preparar productos
        $products = [];

        // Extraer productos del campo 'productos' si existe
        if (isset($order['productos'])) {
            if (is_string($order['productos'])) {
                // Intentar decodificar como JSON
                $productosArray = json_decode($order['productos'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($productosArray)) {
                    // Normalize each product to ensure all required keys exist
                    foreach ($productosArray as $producto) {
                        // Default single price - divide total by number of items if available
                        $precioUnitario = 0;
                        $cantidad = $producto['cantidad'] ?? $producto['quantity'] ?? 1;

                        if (!empty($order['total']) && count($productosArray) > 0) {
                            $precioUnitario = floatval($order['total']) / count($productosArray);
                        }

                        // Add each product with normalized structure
                        $products[] = [
                            'id' => $producto['id'] ?? count($products) + 1,
                            'sku' => $producto['sku'] ?? '',
                            'nombre' => $producto['nombre'] ?? $producto['name'] ?? '',
                            'cantidad' => $cantidad,
                            'precio' => $producto['precio'] ?? $precioUnitario,
                            'precio_unitario' => $producto['precio_unitario'] ?? $producto['precio'] ?? $precioUnitario,
                            'subtotal' => $producto['subtotal'] ?? ($precioUnitario * $cantidad),
                            'procesado' => $producto['procesado'] ?? false
                        ];
                    }
                } else {
                    // Intentar como lista separada por comas
                    $productosNombres = explode(',', $order['productos']);
                    $productosSkus = [];

                    // Si hay un campo SKU, intentar dividirlo también
                    if (isset($order['sku'])) {
                        $productosSkus = explode(',', $order['sku']);
                    }

                    foreach ($productosNombres as $index => $nombreProducto) {
                        $sku = '';
                        if (isset($productosSkus[$index])) {
                            $sku = trim($productosSkus[$index]);
                        }

                        if (!empty(trim($nombreProducto))) {
                            // Calcular precio unitario si hay total pero no productos individuales
                            $precioUnitario = 0;
                            if (!empty($order['total']) && count($productosNombres) > 0) {
                                $precioUnitario = floatval($order['total']) / count($productosNombres);
                            }

                            $products[] = [
                                'id' => $index + 1,
                                'sku' => $sku,
                                'nombre' => trim($nombreProducto),
                                'cantidad' => 1,
                                'precio' => $precioUnitario,
                                'precio_unitario' => $precioUnitario,
                                'subtotal' => $precioUnitario,
                                'procesado' => false
                            ];
                        }
                    }
                }
            } else if (is_array($order['productos'])) {
                // For directly stored array products
                foreach ($order['productos'] as $producto) {
                    // Default single price - divide total by number of items if available
                    $precioUnitario = 0;
                    $cantidad = $producto['cantidad'] ?? $producto['quantity'] ?? 1;

                    if (!empty($order['total']) && count($order['productos']) > 0) {
                        $precioUnitario = floatval($order['total']) / count($order['productos']);
                    }

                    // Add each product with normalized structure
                    $products[] = [
                        'id' => $producto['id'] ?? count($products) + 1,
                        'sku' => $producto['sku'] ?? '',
                        'nombre' => $producto['nombre'] ?? $producto['name'] ?? '',
                        'cantidad' => $cantidad,
                        'precio' => $producto['precio'] ?? $precioUnitario,
                        'precio_unitario' => $producto['precio_unitario'] ?? $producto['precio'] ?? $precioUnitario,
                        'subtotal' => $producto['subtotal'] ?? ($precioUnitario * $cantidad),
                        'procesado' => $producto['procesado'] ?? false
                    ];
                }
            }
        }

        // Valores iniciales para variables que pueden estar ausentes
        $envio = $order['costo_envio'] ?? $order['envio'] ?? 0;
        $impuesto = $order['impuesto'] ?? $order['iva'] ?? 0;
        $subtotal = $order['subtotal'] ?? 0;
        $total = $order['total'] ?? 0;

        // Si no hay un subtotal pero hay total, calcular el subtotal
        if (empty($subtotal) && !empty($total)) {
            $subtotal = $total - $envio - $impuesto;
        }

        // Información de entrega
        $deliveryInfo = [
            'transportista' => $order['transportista'] ?? $order['carrier'] ?? '',
            'tracking' => $order['num_seguimiento'] ?? $order['tracking_number'] ?? '',
            'fecha_entrega' => $order['fecha_entrega'] ?? $order['delivery_date'] ?? '',
        ];

        return new ViewModel([
            'order' => $order,
            'clientInfo' => $clientInfo,
            'table' => $table,
            'marketplace' => $marketplace,
            'products' => $products,
            'envio' => $envio,
            'impuesto' => $impuesto,
            'subtotal' => $subtotal,
            'total' => $total,
            'deliveryInfo' => $deliveryInfo
        ]);
    }

    /**
     * Maneja el detalle de una orden de París usando consultas directas
     */
    private function handleParisOrderDetail($subOrderNumber)
    {
        try {
            // Log para debugging
            error_log("handleParisOrderDetail: Recibido subOrderNumber: $subOrderNumber");
            
            // Obtener información completa de la orden de París
            $orderData = $this->databaseService->fetchOne(
                "SELECT 
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
                LIMIT 1",
                [$subOrderNumber]
            );

            if (!$orderData) {
                return $this->notFoundAction();
            }

            // Obtener productos de la orden
            $products = $this->databaseService->fetchAll(
                "SELECT 
                    pi.sku,
                    pi.name as nombre,
                    pi.priceAfterDiscounts as precio,
                    pi.quantity as cantidad,
                    (pi.priceAfterDiscounts * pi.quantity) as subtotal
                FROM paris_items pi 
                WHERE pi.subOrderNumber = ?",
                [$subOrderNumber]
            );
            
            // Si no hay productos, intentar consulta alternativa
            if (empty($products)) {
                $altProducts = $this->databaseService->fetchAll(
                    "SELECT 
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
                    WHERE brd.number LIKE ?",
                    ['%' . $subOrderNumber . '%']
                );
                
                if (!empty($altProducts)) {
                    $products = $altProducts;
                    
                    // Calcular precio unitario con el total si está disponible
                    if (!empty($products) && !empty($orderData['total'])) {
                        $totalQuantity = 0;
                        foreach ($products as &$product) {
                            $totalQuantity += intval($product['cantidad']);
                        }
                        
                        if ($totalQuantity > 0) {
                            $unitPrice = floatval($orderData['total']) / $totalQuantity;
                            foreach ($products as &$product) {
                                $product['precio'] = $unitPrice;
                                $product['subtotal'] = $unitPrice * intval($product['cantidad']);
                            }
                        }
                    }
                }
            }
            
            // Si todavía no hay productos, crear uno genérico
            if (empty($products)) {
                $products[] = [
                    'sku' => 'PRODUCTOS_PARIS',
                    'nombre' => 'Productos de París',
                    'cantidad' => 1,
                    'precio' => $orderData['total'] ?? 0,
                    'subtotal' => $orderData['total'] ?? 0
                ];
            }

            // Formatear productos para la vista
            $formattedProducts = [];
            foreach ($products as $index => $product) {
                $formattedProducts[] = [
                    'id' => $index + 1,
                    'sku' => $product['sku'] ?? '',
                    'nombre' => $product['nombre'] ?? '',
                    'cantidad' => $product['cantidad'] ?? 1,
                    'precio' => $product['precio'] ?? 0,
                    'precio_unitario' => $product['precio'] ?? 0,
                    'subtotal' => $product['subtotal'] ?? 0,
                    'procesado' => false
                ];
            }

            // Preparar información del cliente
            $clientInfo = [
                'nombre' => $orderData['cliente'] ?? 'Cliente',
                'rut' => $orderData['documento'] ?? '',
                'telefono' => $orderData['telefono'] ?? '',
                'direccion' => $orderData['direccion'] ?? $orderData['direccion_envio'] ?? '',
                'comuna' => '',
                'region' => '',
                'email' => '',
            ];

            // Información de entrega
            $deliveryInfo = [
                'transportista' => $orderData['transportista'] ?? '',
                'tracking' => '',
                'fecha_entrega' => '',
            ];

            // Calcular subtotal
            $total = floatval($orderData['total'] ?? 0);
            $impuesto = floatval($orderData['impuesto'] ?? 0);
            $envio = floatval($orderData['cost'] ?? 0);
            $subtotal = $total - $impuesto - $envio;
            
            // Asegurarse de que los campos de estado de impresión y procesamiento estén definidos
            $orderData['printed'] = isset($orderData['printed']) ? $orderData['printed'] : 0;
            $orderData['procesado'] = isset($orderData['procesado']) ? $orderData['procesado'] : 0;
            
            // Logs adicionales
            error_log("DATOS PARA LA VISTA PARIS:");
            error_log("- orderData: " . json_encode(array_keys($orderData)));
            error_log("- productos: " . count($formattedProducts));
            error_log("- total: $total, impuesto: $impuesto, envio: $envio, subtotal: $subtotal");

            return new ViewModel([
                'order' => $orderData,
                'clientInfo' => $clientInfo,
                'table' => 'Orders_PARIS',
                'marketplace' => 'PARIS',
                'products' => $formattedProducts,
                'envio' => $envio,
                'impuesto' => $impuesto,
                'subtotal' => $subtotal,
                'total' => $total,
                'deliveryInfo' => $deliveryInfo,
                'isDirectQuery' => true
            ]);

        } catch (\Exception $e) {
            error_log("Error al obtener detalle de orden París: " . $e->getMessage());
            return $this->notFoundAction();
        }
    }

    /**
     * Acción para procesar lotes de órdenes (acciones masivas)
     */
    public function bulkOrdersAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }

        // Aceptar tanto GET como POST para facilitar la depuración y el acceso directo
        // Eliminar esta verificación por ahora para permitir acceso más fácil
        /*if (!$this->getRequest()->isPost()) {
            return $this->jsonResponse(['success' => false, 'message' => 'Se requiere método POST']);
        }*/

        // También aceptar parámetros de GET para permitir URLs directas
        $orderIds = $this->params()->fromPost('orderIds', $this->params()->fromQuery('orderIds', []));
        $orderTables = $this->params()->fromPost('orderTables', $this->params()->fromQuery('orderTables', []));
        $table = $this->params()->fromPost('table', $this->params()->fromQuery('table', null));
        $action = $this->params()->fromPost('action', $this->params()->fromQuery('action', null));
        
        // Asegurarse de que orderIds sea un array
        if (!is_array($orderIds) && !empty($orderIds)) {
            $orderIds = [$orderIds];
        }

        // Determinar una tabla común para usar en los métodos que requieren una sola tabla
        $commonTable = null;
        if (!empty($orderTables) && count($orderTables) > 0) {
            // Obtener la tabla más común o usar la primera
            $tableCounts = array_count_values($orderTables);
            arsort($tableCounts);
            $commonTable = key($tableCounts);
        } else if ($table) {
            $commonTable = $table;
        } else {
            $commonTable = 'Orders_GENERAL'; // Valor por defecto
        }
        
        if (empty($orderIds) || !$action) {
            return $this->jsonResponse(['success' => false, 'message' => 'Faltan parámetros requeridos']);
        }
        
        // Procesar según la acción solicitada
        switch ($action) {
            case 'print-labels':
                // Generar etiquetas - método espera un string como tabla
                try {
                    $labelsPdf = $this->generateLabels($orderIds, $commonTable);
                    return $labelsPdf;
                } catch (\Exception $e) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => 'Error al generar etiquetas: ' . $e->getMessage()
                    ]);
                }

            case 'generate-manifest':
            case 'print-manifest':
                // Generar manifiesto
                try {
                    $manifestPdf = $this->generateManifest($orderIds, $commonTable);
                    return $manifestPdf;
                } catch (\Exception $e) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => 'Error al generar manifiesto: ' . $e->getMessage()
                    ]);
                }

            case 'generate-packing':
            case 'print-packing':
                // Generar lista de empaque
                try {
                    $packingPdf = $this->generatePackingList($orderIds, $commonTable);
                    return $packingPdf;
                } catch (\Exception $e) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => 'Error al generar lista de empaque: ' . $e->getMessage()
                    ]);
                }

            case 'generate-picking':
            case 'print-picking':
                // Generar lista de picking
                try {
                    $pickingPdf = $this->generatePickingList($orderIds, $commonTable);
                    return $pickingPdf;
                } catch (\Exception $e) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => 'Error al generar lista de picking: ' . $e->getMessage()
                    ]);
                }

            case 'print-invoice':
                // Imprimir boleta/factura
                try {
                    $invoicePdf = $this->generateInvoices($orderIds, $commonTable);
                    return $invoicePdf;
                } catch (\Exception $e) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => 'Error al generar boleta/factura: ' . $e->getMessage()
                    ]);
                }
                
            case 'update-status':
                $newStatus = $this->params()->fromPost('newStatus', null);
                if (!$newStatus) {
                    return $this->jsonResponse(['success' => false, 'message' => 'Falta especificar el nuevo estado']);
                }
                
                $validStates = ['Nueva', 'En Proceso', 'Enviada', 'Entregada', 'Pendiente de Pago', 'Cancelada', 'Devuelta'];
                if (!in_array($newStatus, $validStates)) {
                    return $this->jsonResponse(['success' => false, 'message' => 'Estado inválido']);
                }
                
                // Actualizar estado para múltiples órdenes
                try {
                    $updated = 0;
                    
                    // Para Orders_PARIS, solo simular la actualización
                    if (strtoupper($commonTable) === 'ORDERS_PARIS') {
                        $updated = count($orderIds);
                    } else {
                        // Si es una tabla específica
                        if ($table && $table !== 'all') {
                            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                            $sql = "UPDATE `$table` SET estado = ?, updated_at = NOW() WHERE id IN ($placeholders)";
                            $params = array_merge([$newStatus], $orderIds);
                            
                            $statement = $this->dbAdapter->createStatement($sql);
                            $result = $statement->execute($params);
                            $updated = $result->getAffectedRows();
                        } else {
                            // Si son todas las tablas, actualizar en cada una
                            $tables = [
                                'Orders_WALLMART',
                                'Orders_RIPLEY',
                                'Orders_FALABELLA',
                                'Orders_MERCADO_LIBRE',
                                'Orders_PARIS',
                                'Orders_WOOCOMMERCE'
                            ];
                            
                            foreach ($tables as $tableToUpdate) {
                                if (strtoupper($tableToUpdate) === 'ORDERS_PARIS') {
                                    // Para París, solo simular
                                    continue;
                                }
                                
                                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                                $sql = "UPDATE `$tableToUpdate` SET estado = ?, updated_at = NOW() WHERE id IN ($placeholders)";
                                $params = array_merge([$newStatus], $orderIds);
                                
                                $statement = $this->dbAdapter->createStatement($sql);
                                $result = $statement->execute($params);
                                $updated += $result->getAffectedRows();
                            }
                        }
                    }
                    
                    return $this->jsonResponse([
                        'success' => true,
                        'message' => 'Estado actualizado para ' . $updated . ' órdenes',
                        'updatedCount' => $updated
                    ]);
                } catch (\Exception $e) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => 'Error al actualizar estados: ' . $e->getMessage()
                    ]);
                }
                
            case 'export-csv':
                // Exportar a CSV
                try {
                    return $this->exportToCsv($orderIds, $orderTables);
                } catch (\Exception $e) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => 'Error al exportar a CSV: ' . $e->getMessage()
                    ]);
                }
                
            case 'export-excel':
                try {
                    return $this->exportToExcel($orderIds, $orderTables);
                } catch (\Exception $e) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => 'Error al exportar a Excel: ' . $e->getMessage()
                    ]);
                }
                
            default:
                return $this->jsonResponse(['success' => false, 'message' => 'Acción no reconocida']);
        }
    }

    /**
     * Generar etiquetas de envío para órdenes
     * @param array $orderIds IDs de las órdenes
     * @param string $table Tabla de órdenes (marketplace)
     * @return Response
     */
    private function generateLabels(array $orderIds, $table = null)
    {
        // We don't need to import anything here since we're already importing Fpdi at the top of the file
        
        // Array para almacenar URLs de etiquetas
        $labelUrls = [];
        
        // Obtener etiquetas para órdenes seleccionadas
        if ($table == 'paris_orders' || $table == 'Orders_PARIS') {
            // Para órdenes Paris, obtener directamente de paris_subOrders
            $placeholders = array_fill(0, count($orderIds), '?');
            
            $sql = "SELECT so.labelUrl 
                    FROM paris_orders o
                    JOIN paris_subOrders so ON o.subOrderNumber = so.subOrderNumber
                    WHERE o.subOrderNumber IN (" . implode(',', $placeholders) . ")";
            
            $results = $this->databaseService->fetchAll($sql, $orderIds);
            
            foreach ($results as $row) {
                if (!empty($row['labelUrl'])) {
                    $labelUrls[] = $row['labelUrl'];
                }
            }
        } else {
            // Para otras tablas, determinar la tabla a usar
            $tables = [];
            if ($table && $table !== 'all') {
                $tables[] = $table;
            } else {
                $tables = [
                    'Orders_WALLMART',
                    'Orders_RIPLEY',
                    'Orders_FALABELLA',
                    'Orders_MERCADO_LIBRE',
                    'Orders_WOOCOMMERCE'
                ];
            }
            
            // Recolectar URLs de etiquetas
            foreach ($tables as $currentTable) {
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $sql = "SELECT id, label_url FROM `$currentTable` WHERE id IN ($placeholders)";
                $statement = $this->dbAdapter->createStatement($sql);
                $result = $statement->execute($orderIds);
                
                foreach ($result as $row) {
                    if (!empty($row['label_url']) && filter_var($row['label_url'], FILTER_VALIDATE_URL)) {
                        $labelUrls[] = $row['label_url'];
                        
                        // Marcar como impresa
                        try {
                            $this->databaseService->execute(
                                "UPDATE `$currentTable` SET printed = 1 WHERE id = ?",
                                [$row['id']]
                            );
                        } catch (\Exception $e) {
                            // Ignorar errores al actualizar
                            error_log("Error al marcar etiqueta como impresa: " . $e->getMessage());
                        }
                    }
                }
            }
        }
        
        // Si no hay URLs de etiquetas, mostrar mensaje
        if (empty($labelUrls)) {
            $html = '
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                    .error { color: #d9534f; font-size: 24px; margin-bottom: 20px; }
                </style>
            </head>
            <body>
                <div class="error">No se encontraron etiquetas</div>
                <p>No se encontraron URLs de etiquetas para las órdenes seleccionadas.</p>
            </body>
            </html>';
            
            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            
            $pdfContent = $dompdf->output();
        } else {
            // Crear un PDF con todas las etiquetas usando FPDI sin formato predefinido
            // No especificamos tamaño de página aquí porque se ajustará al tamaño de cada documento
            $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Configurar opciones adicionales para mejor manejo de PDF importados
            $pdf->setAutoPageBreak(false); // Evitar saltos de página automáticos
            $pdf->setCreator('Lodoro Analytics');
            $pdf->setTitle('Etiquetas Paris');
            
            // Por cada URL, descargar el PDF y agregarlo
            foreach ($labelUrls as $url) {
                // Asegurar que la URL tenga https://
                if (!preg_match('/^https?:\/\//i', $url)) {
                    $url = 'https://' . $url;
                }
                
                // Descargar PDF con ampliado de opciones para mejorar la compatibilidad
                $context = stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                    'http' => [
                        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
                        'timeout' => 30, // Incrementar el timeout para URLs lentas
                        'follow_location' => 1, // Seguir redirecciones
                        'ignore_errors' => true
                    ]
                ]);
                
                error_log("Descargando URL: " . $url);
                $content = @file_get_contents($url, false, $context);
                
                if (empty($content)) {
                    error_log("Error: No se pudo descargar el contenido de la URL: " . $url);
                }
                
                if ($content) {
                    // Guardar temporalmente
                    $tempFile = tempnam(sys_get_temp_dir(), 'label_');
                    file_put_contents($tempFile, $content);
                    
                    try {
                        // Agregar al PDF principal manteniendo el tamaño original
                        $pageCount = $pdf->setSourceFile($tempFile);
                        
                        for ($i = 1; $i <= $pageCount; $i++) {
                            $tpl = $pdf->importPage($i);
                            
                            // Obtener las dimensiones del template original
                            $size = $pdf->getTemplateSize($tpl);
                            $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                            
                            // Agregar página con el tamaño exacto del documento original
                            $pdf->AddPage($orientation, array($size['width'], $size['height']));
                            
                            // Usar el template ajustado a la página completa
                            $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
                        }
                        
                        // Limpiar
                        unlink($tempFile);
                    } catch (\Exception $e) {
                        // Registrar error pero continuar con siguiente URL
                        error_log("Error al importar etiqueta PDF: " . $e->getMessage());
                    }
                }
            }
            
            // Generar PDF
            $pdfContent = $pdf->Output('', 'S');
        }
        
        // Crear respuesta HTTP con el PDF
        $response = new Response();
        $response->setContent($pdfContent);
        
        // Configurar cabeceras
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/pdf');
        $headers->addHeaderLine('Content-Disposition', 'inline; filename="Etiquetas_' . date('Y-m-d_His') . '.pdf"');
        $headers->addHeaderLine('Content-Length', strlen($pdfContent));
        
        return $response;
    }

    /**
     * Generar manifiesto para órdenes
     * @param array $orderIds IDs de las órdenes
     * @param string $table Tabla de órdenes
     * @return Response
     */
    private function generateManifest($orderIds, $table)
    {
        // Obtener información de las órdenes
        $orders = [];
        
        // Para Orders_PARIS, usar consultas directas
        if (strtoupper($table) === 'ORDERS_PARIS') {
            $sql = "
                SELECT 
                    pof.subOrderNumber as id,
                    pof.customer_name as cliente,
                    pof.billing_phone as telefono,
                    pof.billing_address as direccion,
                    bd.totalAmount as total,
                    pso.carrier as transportista
                FROM bsale_references brd
                INNER JOIN paris_orders pof 
                    ON brd.number COLLATE utf8mb4_unicode_ci = pof.subOrderNumber COLLATE utf8mb4_unicode_ci
                INNER JOIN paris_subOrders pso 
                    ON pof.subOrderNumber COLLATE utf8mb4_unicode_ci = pso.subOrderNumber COLLATE utf8mb4_unicode_ci
                INNER JOIN bsale_documents bd 
                    ON brd.document_id = bd.id
                WHERE pof.subOrderNumber IN (" . implode(',', array_fill(0, count($orderIds), '?')) . ")
            ";
            
            try {
                $orders = $this->databaseService->fetchAll($sql, $orderIds);
            } catch (\Exception $e) {
                error_log("Error al obtener órdenes de París para manifiesto: " . $e->getMessage());
                $orders = [];
            }
        } else {
            // Para otras tablas, usar consulta estándar
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $sql = "SELECT * FROM `$table` WHERE id IN ($placeholders)";
            try {
                $statement = $this->dbAdapter->createStatement($sql);
                $result = $statement->execute($orderIds);
                
                foreach ($result as $row) {
                    $orders[] = $row;
                }
            } catch (\Exception $e) {
                error_log("Error al obtener órdenes para manifiesto: " . $e->getMessage());
                $orders = [];
            }
        }
        
        if (empty($orders)) {
            throw new \Exception("No se encontraron órdenes para generar el manifiesto.");
        }
        
        // Generar HTML del manifiesto
        $html = '
        <html>
        <head>
            <style>
                body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
                th, td { border: 1px solid #000; padding: 6px; text-align: left; }
                th { background-color: #eee; }
                .title { font-size: 20px; font-weight: bold; margin-bottom: 10px; }
                .subtitle { margin: 10px 0; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class="title">Manifiesto de Órdenes - ' . str_replace('Orders_', '', $table) . ' | LODORO</div>
            <div>MANIFIESTO GENERADO EL: ' . date("Y-m-d H:i:s") . '</div>
            <br>
            
            <table>
                <thead>
                    <tr>
                        <th>N° ORDEN</th>
                        <th>CLIENTE</th>
                        <th>TELÉFONO</th>
                        <th>DIRECCIÓN</th>
                        <th>TRANSPORTISTA</th>
                        <th>TOTAL</th>
                    </tr>
                </thead>
                <tbody>';
        
        $totalGeneral = 0;
        foreach ($orders as $order) {
            $orderId = $order['id'];
            $cliente = $order['cliente'] ?? $order['customer_name'] ?? 'N/A';
            $telefono = $order['telefono'] ?? $order['phone'] ?? 'N/A';
            $direccion = $order['direccion'] ?? $order['address'] ?? 'N/A';
            $transportista = $order['transportista'] ?? $order['carrier'] ?? 'N/A';
            $total = floatval($order['total'] ?? 0);
            $totalGeneral += $total;
            
            $html .= '
                <tr>
                    <td>' . htmlspecialchars($orderId) . '</td>
                    <td>' . htmlspecialchars($cliente) . '</td>
                    <td>' . htmlspecialchars($telefono) . '</td>
                    <td>' . htmlspecialchars($direccion) . '</td>
                    <td>' . htmlspecialchars($transportista) . '</td>
                    <td>$' . number_format($total, 2) . '</td>
                </tr>';
        }
        
        $html .= '
                <tr style="background-color: #f0f0f0; font-weight: bold;">
                    <td colspan="5">TOTAL GENERAL</td>
                    <td>$' . number_format($totalGeneral, 2) . '</td>
                </tr>
                </tbody>
            </table>
            <div><strong>TOTAL ÓRDENES:</strong> ' . count($orders) . '</div>
        </body>
        </html>';
        
        // Crear PDF con DOMPDF
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        $pdfContent = $dompdf->output();
        
        // Crear respuesta HTTP
        $response = new Response();
        $response->setContent($pdfContent);
        
        // Configurar cabeceras
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/pdf');
        $headers->addHeaderLine('Content-Disposition', 'inline; filename="Manifiesto_' . date('Y-m-d') . '.pdf"');
        $headers->addHeaderLine('Content-Length', strlen($pdfContent));
        $headers->addHeaderLine('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $headers->addHeaderLine('Pragma', 'public');
        $headers->addHeaderLine('Expires', '0');
        
        return $response;
    }

    /**
     * Generador de facturas básicas como fallback
     */
    private function generateBasicInvoices($orderIds, $tableName)
    {
        $html = '
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; font-size: 12px; }
                .invoice { margin-bottom: 40px; page-break-after: auto; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .header { text-align: center; margin-bottom: 20px; }
                .total { font-weight: bold; }
            </style>
        </head>
        <body>';
        
        foreach ($orderIds as $orderId) {
            // Para Orders_PARIS, obtener datos con consulta directa
            if (strtoupper($tableName) === 'ORDERS_PARIS') {
                try {
                    $orderData = $this->databaseService->fetchOne(
                        "SELECT 
                            pof.subOrderNumber,
                            pof.customer_name,
                            bd.totalAmount,
                            bd.taxAmount
                        FROM bsale_references brd
                        INNER JOIN paris_orders pof 
                            ON brd.number COLLATE utf8mb4_unicode_ci = pof.subOrderNumber COLLATE utf8mb4_unicode_ci
                        INNER JOIN bsale_documents bd 
                            ON brd.document_id = bd.id
                        WHERE pof.subOrderNumber = ?",
                        [$orderId]
                    );
                    
                    if ($orderData) {
                        $html .= '
                        <div class="invoice">
                            <div class="header">
                                <h2>FACTURA BÁSICA</h2>
                                <p>Orden: ' . htmlspecialchars($orderData['subOrderNumber']) . '</p>
                            </div>
                            <table>
                                <tr>
                                    <td><strong>Cliente:</strong></td>
                                    <td>' . htmlspecialchars($orderData['customer_name']) . '</td>
                                </tr>
                                <tr>
                                    <td><strong>Subtotal:</strong></td>
                                    <td>$' . number_format($orderData['totalAmount'] - $orderData['taxAmount'], 2) . '</td>
                                </tr>
                                <tr>
                                    <td><strong>Impuesto:</strong></td>
                                    <td>$' . number_format($orderData['taxAmount'], 2) . '</td>
                                </tr>
                                <tr class="total">
                                    <td><strong>Total:</strong></td>
                                    <td><strong>$' . number_format($orderData['totalAmount'], 2) . '</strong></td>
                                </tr>
                            </table>
                        </div>';
                    }
                } catch (\Exception $e) {
                    error_log("Error al obtener datos de orden París: " . $e->getMessage());
                }
            } else {
                // Para otras tablas, usar método estándar
                try {
                    $sql = "SELECT * FROM `$tableName` WHERE id = ?";
                    $orderData = $this->databaseService->fetchOne($sql, [$orderId]);
                    
                    if ($orderData) {
                        $html .= '
                        <div class="invoice">
                            <div class="header">
                                <h2>FACTURA BÁSICA</h2>
                                <p>Orden: ' . htmlspecialchars($orderData['id']) . '</p>
                            </div>
                            <table>
                                <tr>
                                    <td><strong>Cliente:</strong></td>
                                    <td>' . htmlspecialchars($orderData['cliente'] ?? $orderData['customer_name'] ?? 'N/A') . '</td>
                                </tr>
                                <tr>
                                    <td><strong>Productos:</strong></td>
                                    <td>' . htmlspecialchars($orderData['productos'] ?? 'N/A') . '</td>
                                </tr>
                                <tr class="total">
                                    <td><strong>Total:</strong></td>
                                    <td><strong>$' . number_format($orderData['total'] ?? 0, 2) . '</strong></td>
                                </tr>
                            </table>
                        </div>';
                    }
                } catch (\Exception $e) {
                    error_log("Error al obtener datos de orden: " . $e->getMessage());
                }
            }
        }
        
        $html .= '</body></html>';
        
        // Crear PDF con DOMPDF
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        $pdfContent = $dompdf->output();
        
        // Crear respuesta HTTP
        $response = new Response();
        $response->setContent($pdfContent);
        
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/pdf');
        $headers->addHeaderLine('Content-Disposition', 'inline; filename="Facturas_Basicas_' . date('Y-m-d_His') . '.pdf"');
        
        return $response;
    }

    /**
     * Método auxiliar para obtener productos de una orden
     */
    private function getOrderProducts($orderId, $tableName)
    {
        $products = [];
        
        // Para Orders_PARIS, usar consulta directa
        if (strtoupper($tableName) === 'ORDERS_PARIS') {
            try {
                $sql = "
                    SELECT 
                        pi.sku,
                        pi.name as nombre,
                        pi.quantity as cantidad
                    FROM paris_items pi 
                    WHERE pi.subOrderNumber = ?
                ";
                $products = $this->databaseService->fetchAll($sql, [$orderId]);
            } catch (\Exception $e) {
                error_log("Error al obtener productos de París: " . $e->getMessage());
            }
        } else {
            // Para otras tablas, intentar diferentes enfoques
            try {
                // 1. Intentar tabla de productos específica
                $itemsTable = $tableName . "_Items";
                $sql = "SELECT * FROM `$itemsTable` WHERE order_id = ? OR orderId = ? OR subOrderNumber = ?";
                $statement = $this->dbAdapter->createStatement($sql);
                $result = $statement->execute([$orderId, $orderId, $orderId]);
                
                foreach ($result as $product) {
                    $products[] = [
                        'sku' => $product['sku'] ?? 'Sin SKU',
                        'nombre' => $product['name'] ?? $product['nombre'] ?? 'Sin nombre',
                        'cantidad' => $product['quantity'] ?? $product['cantidad'] ?? 1
                    ];
                }
            } catch (\Exception $e) {
                // Si no existe tabla de items, obtener de la orden principal
                try {
                    $sql = "SELECT productos, sku FROM `$tableName` WHERE id = ?";
                    $orderData = $this->databaseService->fetchOne($sql, [$orderId]);
                    
                    if ($orderData) {
                        $productos = json_decode($orderData['productos'] ?? '[]', true);
                        if (is_array($productos)) {
                            $products = $productos;
                        } else {
                            // Si no es JSON, intentar como lista separada por comas
                            $productNames = explode(',', $orderData['productos'] ?? '');
                            $skus = explode(',', $orderData['sku'] ?? '');
                            
                            foreach ($productNames as $index => $name) {
                                if (!empty(trim($name))) {
                                    $products[] = [
                                        'sku' => isset($skus[$index]) ? trim($skus[$index]) : 'Sin SKU',
                                        'nombre' => trim($name),
                                        'cantidad' => 1
                                    ];
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log("Error al obtener productos de la orden: " . $e->getMessage());
                }
            }
        }
        
        return $products;
    }

    /**
     * Método auxiliar para respuestas JSON
     */
    protected function jsonResponse($data)
    {
        $response = new JsonModel($data);
        return $response;
    }

    /**
     * Método auxiliar que mantiene compatibilidad con tablas existentes
     */
    private function getOrdersWithPagination($table, $page, $limit, $filters)
    {
        try {
            // Primero, verificar qué columnas están disponibles en la tabla
            $columnsQuery = "SHOW COLUMNS FROM `$table`";
            $columns = $this->databaseService->fetchAll($columnsQuery);

            // Crear array con nombres de columnas disponibles
            $availableColumns = [];
            foreach ($columns as $column) {
                $availableColumns[] = $column['Field'];
            }

            // Construir la consulta base
            $sql = "SELECT * FROM `$table` WHERE 1=1";
            $countSql = "SELECT COUNT(*) as total FROM `$table` WHERE 1=1";

            $params = [];

            // Aplicar filtro de búsqueda - verificar cada columna primero
            if (!empty($filters['search'])) {
                $searchTerm = '%' . $filters['search'] . '%';
                $exactSearchTerm = $filters['search']; // Para búsqueda exacta de números de orden
                $searchConditions = [];

                // Comprobar si la búsqueda es un número (probablemente un ID o número de orden)
                $isNumeric = is_numeric($filters['search']);

                // Lista ampliada de columnas de búsqueda para asegurar que busque en todos los campos relevantes
                $textColumns = array_intersect($availableColumns, [
                    // Campos específicos de la orden
                    'id',
                    'suborder_number',
                    'orderId',
                    'order_id',
                    'referencia',
                    'order_number',
                    'numero_orden',
                    'invoice_number',

                    // Campos de cliente
                    'cliente',
                    'customer',
                    'customer_name',
                    'nombre_cliente',
                    'rut',
                    'rut_cliente',

                    // Campos de productos
                    'productos',
                    'products',
                    'product_name',
                    'nombre_producto',
                    'sku',
                    'codigo_sku',
                    'description',
                    'descripcion'
                ]);

                if (!empty($textColumns)) {
                    // Priorizar búsqueda exacta para números de orden e IDs
                    if ($isNumeric) {
                        // Primero agregar condiciones de búsqueda exacta para IDs y números de orden
                        $idColumns = array_intersect($availableColumns, [
                            'id',
                            'suborder_number',
                            'orderId',
                            'order_id',
                            'invoice_number'
                        ]);

                        foreach ($idColumns as $column) {
                            $searchConditions[] = "`$column` = ?";
                            $params[] = $exactSearchTerm;
                        }
                    }

                    // Luego agregar condiciones LIKE para todos los campos de texto
                    foreach ($textColumns as $column) {
                        // Usar CAST para asegurar que funcione con diferentes tipos de datos
                        $searchConditions[] = "CAST(`$column` AS CHAR) LIKE ?";
                        $params[] = $searchTerm;
                    }

                    // Buscar también dentro de campos JSON (productos)
                    if (in_array('productos', $availableColumns)) {
                        $searchConditions[] = "`productos` LIKE ?";
                        $params[] = $searchTerm;
                    }

                    // Buscar en SKU (campo de texto)
                    if (in_array('sku', $availableColumns)) {
                        $searchConditions[] = "`sku` LIKE ?";
                        $params[] = $searchTerm;
                    }

                    $sql .= " AND (" . implode(" OR ", $searchConditions) . ")";
                    $countSql .= " AND (" . implode(" OR ", $searchConditions) . ")";
                }
            }

            // Aplicar filtro de estado si la columna existe
            if (!empty($filters['status']) && in_array('estado', $availableColumns)) {
                $sql .= " AND estado = ?";
                $countSql .= " AND estado = ?";
                $params[] = $filters['status'];
            } else if (!empty($filters['status']) && in_array('status', $availableColumns)) {
                $sql .= " AND status = ?";
                $countSql .= " AND status = ?";
                $params[] = $filters['status'];
            }

            // Aplicar filtro de impresión si la columna existe
            if (isset($filters['printed']) && in_array('printed', $availableColumns)) {
                $sql .= " AND printed = ?";
                $countSql .= " AND printed = ?";
                $params[] = $filters['printed'];
            } else {
                // Por defecto, mostrar solo órdenes NO impresas si existe la columna
                if (in_array('printed', $availableColumns)) {
                    $sql .= " AND (printed = 0 OR printed IS NULL)";
                    $countSql .= " AND (printed = 0 OR printed IS NULL)";
                }
            }

            // Aplicar filtro de procesado si la columna existe
            if (isset($filters['procesado']) && in_array('procesado', $availableColumns)) {
                $sql .= " AND procesado = ?";
                $countSql .= " AND procesado = ?";
                $params[] = $filters['procesado'];
            } else {
                // Por defecto, mostrar solo órdenes NO procesadas si existe la columna
                if (in_array('procesado', $availableColumns)) {
                    $sql .= " AND (procesado = 0 OR procesado IS NULL)";
                    $countSql .= " AND (procesado = 0 OR procesado IS NULL)";
                }
            }

            // Aplicar filtro de transportista si la columna existe
            if (!empty($filters['transportista']) && in_array('transportista', $availableColumns)) {
                $sql .= " AND transportista = ?";
                $countSql .= " AND transportista = ?";
                $params[] = $filters['transportista'];
            } else if (!empty($filters['transportista']) && in_array('carrier', $availableColumns)) {
                $sql .= " AND carrier = ?";
                $countSql .= " AND carrier = ?";
                $params[] = $filters['transportista'];
            }

            // Obtener conteo total
            $total = $this->databaseService->fetchOne($countSql, $params);
            $totalItems = $total ? (int)$total['total'] : 0;

            // Determinar columna para ordenamiento
            $orderColumn = 'id'; // Default
            $dateColumns = array_intersect($availableColumns,
                ['fecha_compra', 'order_date', 'created_at', 'date_created', 'fecha']);

            if (!empty($dateColumns)) {
                $orderColumn = reset($dateColumns); // Usar la primera columna de fecha disponible
            }

            // Agregar paginación - usar orden cronológico (ASC)
            $sql .= " ORDER BY `$orderColumn` ASC LIMIT ? OFFSET ?";
            $params[] = (int)$limit;
            $params[] = (int)($page - 1) * $limit;

            // Ejecutar consulta principal
            $orders = $this->databaseService->fetchAll($sql, $params);

            // Si la tabla es de PARIS, buscar nombres de productos en MKP_PARIS
            if (strpos($table, 'PARIS') !== false) {
                try {
                    // Extraer los IDs de las órdenes
                    $orderIds = array_column($orders, 'suborder_number');
                    if (!empty($orderIds)) {
                        // Preparar parámetros para consulta
                        $orderIdParams = [];
                        $orderIdPlaceholders = [];

                        foreach ($orderIds as $index => $orderId) {
                            if (!empty($orderId)) {
                                $paramName = 'orderId' . $index;
                                $orderIdPlaceholders[] = ':' . $paramName;
                                $orderIdParams[$paramName] = $orderId;
                            }
                        }

                        if (!empty($orderIdPlaceholders)) {
                            // Consultar nombres de productos en MKP_PARIS
                            $placeholdersStr = implode(',', $orderIdPlaceholders);
                            $mkpParisData = $this->databaseService->fetchAll(
                                "SELECT numero_suborden, nombre_producto, codigo_sku
                                FROM `MKP_PARIS`
                                WHERE numero_suborden IN ($placeholdersStr)",
                                $orderIdParams
                            );

                            // Crear mapa de número de orden -> nombre de producto
                            $productNameMap = [];
                            foreach ($mkpParisData as $productData) {
                                $orderNumber = $productData['numero_suborden'];
                                $productNameMap[$orderNumber] = [
                                    'nombre_producto' => $productData['nombre_producto'],
                                    'codigo_sku' => $productData['codigo_sku']
                                ];
                            }

                            // Actualizar las órdenes con los nombres de productos
                            foreach ($orders as &$order) {
                                $orderNumber = $order['suborder_number'];
                                if (isset($productNameMap[$orderNumber])) {
                                    // Guardar el nombre del producto y el código SKU
                                    $order['nombre_producto'] = $productNameMap[$orderNumber]['nombre_producto'];
                                    $order['codigo_sku'] = $productNameMap[$orderNumber]['codigo_sku'];
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Si hay un error, continuamos sin los nombres de productos
                }
            }

            // Procesar productos en formato JSON si es necesario
            foreach ($orders as &$order) {
                // Intentar procesar 'productos' o 'products' como JSON
                $productColumns = array_intersect($availableColumns, ['productos', 'products']);

                foreach ($productColumns as $productColumn) {
                    if (isset($order[$productColumn]) && !is_array($order[$productColumn])) {
                        $productos = json_decode($order[$productColumn], true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $order[$productColumn] = $productos;
                        } else {
                            $order[$productColumn] = [];
                        }
                    }
                }
            }

            return [
                'orders' => $orders,
                'totalItems' => $totalItems
            ];

        } catch (\Exception $e) {
            // En caso de error, devolver conjunto vacío
            return [
                'orders' => [],
                'totalItems' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Acción para imprimir PDF de una orden
     *
     * @return ViewModel
     */
    public function printPdfAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }

        // Obtener parámetros
        $id = $this->params()->fromRoute('id');
        $table = $this->params()->fromRoute('table');

        if (!$id || !$table) {
            return $this->notFoundAction();
        }

        // Obtener detalles de la orden
        $order = $this->databaseService->fetchOne(
            "SELECT * FROM `$table` WHERE id = ?",
            [$id]
        );

        if (!$order) {
            return $this->notFoundAction();
        }

        // Preparar datos para la vista
        $viewModel = new ViewModel([
            'order' => $order,
            'table' => $table,
            'marketplace' => str_replace('Orders_', '', $table)
        ]);

        // Usar una plantilla específica para PDF
        $viewModel->setTemplate('application/orders/print-pdf');
        
        // Configurar para generar PDF
        $viewModel->setTerminal(true);

        return $viewModel;
    }
}