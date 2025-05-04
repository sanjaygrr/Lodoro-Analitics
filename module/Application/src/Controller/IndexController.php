<?php
declare(strict_types=1);

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Http\Response;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;
use Laminas\Db\Sql\Predicate\Expression;
use Laminas\View\Model\JsonModel;
use Laminas\Authentication\AuthenticationService;
use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Tcpdf\Fpdi;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Picqer\Barcode\BarcodeGeneratorPNG;

class IndexController extends AbstractActionController
{
    /** @var AdapterInterface */
    private $dbAdapter;
    
    /** @var AuthenticationService */
    private $authService;

    public function __construct(AdapterInterface $dbAdapter, AuthenticationService $authService)
    {
        $this->dbAdapter = $dbAdapter;
        $this->authService = $authService;
    }
    
    /**
     * Método para verificar si el usuario está autenticado
     * Si no lo está, redirige al login
     */
    private function checkAuth()
    {
        if (!$this->authService->hasIdentity()) {
            return $this->redirect()->toRoute('login');
        }
        
        return null; // Continuar si está autenticado
    }

    /**
     * Acción por defecto: redirige al dashboard.
     */
    public function indexAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
        
        // Podemos agregar aquí cualquier lógica o datos que necesites pasar a la vista
        return new ViewModel([
            // Aquí puedes pasar datos a tu vista index.phtml si los necesitas
        ]);
    }

    /**
 * Acción que muestra un dashboard con resumen de todas las tablas.
 */
public function dashboardAction()
{
    // Verificar autenticación
    $redirect = $this->checkAuth();
    if ($redirect !== null) {
        return $redirect;
    }
    
    // Obtener lista de tablas
    $sql = "SELECT table_name, engine, table_rows, create_time, update_time 
            FROM information_schema.tables 
            WHERE table_schema = 'db5skbdigd2nxo'";
    $statement = $this->dbAdapter->createStatement($sql);
    $result = $statement->execute();

    $data = [];
    foreach ($result as $row) {
        $data[] = $row;
    }

    // Variables predeterminadas en caso de error
    $ventaBrutaMensual = 0;
    $impuestoBrutoMensual = 0;
    $totalTransaccionesMes = 0;
    $valorCancelado = 0;
    $transaccionesCanceladas = 0;
    $totalVentas = 0;
    $totalRegistros = 0;
    $ventasAnualesArray = [];
    $topProductosArray = [];

    try {
        // IMPORTANTE: Verificar primero si la tabla existe
        $checkTableSql = "SHOW TABLES LIKE 'MKP_PARIS'";
        $checkTableStmt = $this->dbAdapter->createStatement($checkTableSql);
        $tableExists = $checkTableStmt->execute()->count() > 0;
        
        if ($tableExists) {
            // Consulta SQL exactamente como la proporcionaste
            $sqlVentasPorMes = "SELECT 
                DATE_FORMAT(fecha_creacion, '%Y-%m') AS mes,
                DATE_FORMAT(fecha_creacion, '%b %Y') AS nombre_mes,
                SUM(precio_base) AS monto_base,
                SUM(monto_impuesto_boleta) AS monto_impuesto,
                SUM(monto_total_boleta) AS monto_total
            FROM 
                MKP_PARIS
            GROUP BY 
                mes, nombre_mes
            ORDER BY 
                mes DESC";
            
            $statementVentasMes = $this->dbAdapter->createStatement($sqlVentasPorMes);
            $resultVentasMes = $statementVentasMes->execute();
            
            // Procesar resultados para el gráfico de ventas anuales
            $ventasAnualesArray = [];
            foreach ($resultVentasMes as $row) {
                $ventasAnualesArray[] = [
                    'mes' => $row['nombre_mes'],
                    'ventas' => (float)$row['monto_total']
                ];
                
                // Si es el mes actual, guardar para KPI
                $mesActual = date('Y-m');
                if ($row['mes'] === $mesActual) {
                    $ventaBrutaMensual = (float)$row['monto_base'];
                    $impuestoBrutoMensual = (float)$row['monto_impuesto'];
                }
            }
            
            // Invertir el array para mostrarlo en orden cronológico
            $ventasAnualesArray = array_reverse($ventasAnualesArray);
            
            // Consulta para top 10 productos del mes actual
            $mesActual = date('Y-m');
            $sqlTopProductos = "SELECT 
                nombre_producto as nombre,
                SUM(monto_total_boleta) as ventas,
                COUNT(*) as cantidad
            FROM MKP_PARIS
            WHERE DATE_FORMAT(fecha_creacion, '%Y-%m') = '{$mesActual}'
            GROUP BY nombre_producto
            ORDER BY ventas DESC
            LIMIT 10";
            
            $statementTopProductos = $this->dbAdapter->createStatement($sqlTopProductos);
            $resultTopProductos = $statementTopProductos->execute();
            
            // Procesar resultados para el gráfico de top productos
            foreach ($resultTopProductos as $row) {
                $topProductosArray[] = [
                    'nombre' => $row['nombre'],
                    'ventas' => (float)$row['ventas'],
                    'cantidad' => (int)$row['cantidad']
                ];
            }
            
            // Consultas adicionales para KPIs
            // Ventas totales
            $sqlVentasTotales = "SELECT 
                SUM(monto_total_boleta) as total_ventas,
                COUNT(*) as total_registros
            FROM MKP_PARIS
            WHERE MONTH(fecha_creacion) = MONTH(CURDATE())
            AND YEAR(fecha_creacion) = YEAR(CURDATE())";
            $statementVentasTotales = $this->dbAdapter->createStatement($sqlVentasTotales);
            $resultVentasTotales = $statementVentasTotales->execute()->current();
            
            $totalVentas = isset($resultVentasTotales['total_ventas']) ? (float)$resultVentasTotales['total_ventas'] : 0;
            $totalRegistros = isset($resultVentasTotales['total_registros']) ? (int)$resultVentasTotales['total_registros'] : 0;
            
            // Total transacciones del mes actual
            $sqlTransaccionesMes = "SELECT 
                COUNT(*) as total_transacciones
            FROM MKP_PARIS
            WHERE DATE_FORMAT(fecha_creacion, '%Y-%m') = '{$mesActual}'";
            
            $statementTransaccionesMes = $this->dbAdapter->createStatement($sqlTransaccionesMes);
            $resultTransaccionesMes = $statementTransaccionesMes->execute()->current();
            
            $totalTransaccionesMes = isset($resultTransaccionesMes['total_transacciones']) ? 
                                    (int)$resultTransaccionesMes['total_transacciones'] : 0;
            
            // Ventas canceladas
            $sqlVentasCanceladas = "SELECT 
                SUM(monto_total_boleta) as valor_cancelado,
                COUNT(*) as transacciones_canceladas
            FROM MKP_PARIS
            WHERE estado = 'Cancelada'";
            
            $statementVentasCanceladas = $this->dbAdapter->createStatement($sqlVentasCanceladas);
            $resultVentasCanceladas = $statementVentasCanceladas->execute()->current();
            
            $valorCancelado = isset($resultVentasCanceladas['valor_cancelado']) ? 
                            (float)$resultVentasCanceladas['valor_cancelado'] : 0;
            $transaccionesCanceladas = isset($resultVentasCanceladas['transacciones_canceladas']) ? 
                                    (int)$resultVentasCanceladas['transacciones_canceladas'] : 0;
        }
    } catch (\Exception $e) {
        // Log del error para depuración
        error_log("Error en consultas de dashboard: " . $e->getMessage());
    }

    // IMPORTANTE: Asegurar que los JSON son válidos y sanitizados
    $jsonVentasAnuales = json_encode($ventasAnualesArray, JSON_NUMERIC_CHECK | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $jsonTopProductos = json_encode($topProductosArray, JSON_NUMERIC_CHECK | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

    // Si los JSON son inválidos, usar arrays vacíos
    if ($jsonVentasAnuales === false) $jsonVentasAnuales = '[]';
    if ($jsonTopProductos === false) $jsonTopProductos = '[]';

    // Pasar todos los datos a la vista
    return new ViewModel([
        'data' => $data,
        'jsonVentasAnuales' => $jsonVentasAnuales,
        'jsonTopProductos' => $jsonTopProductos,
        'ventaBrutaMensual' => $ventaBrutaMensual,
        'impuestoBrutoMensual' => $impuestoBrutoMensual,
        'totalTransaccionesMes' => $totalTransaccionesMes,
        'valorCancelado' => $valorCancelado,
        'transaccionesCanceladas' => $transaccionesCanceladas,
        'totalVentas' => $totalVentas,
        'totalRegistros' => $totalRegistros
    ]);
}
    /**
     * Exportar órdenes a Excel
     */
    private function exportToExcel(array $orderIds, string $table = null)
    {
        // Asegúrate de que la clase PhpSpreadsheet esté disponible
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            throw new \Exception("La clase PhpSpreadsheet no está disponible. Asegúrate de tener instalado phpoffice/phpspreadsheet.");
        }

        // Determinar la tabla a usar
        $tables = [];
        if ($table && $table !== 'all') {
            $tables[] = $table;
        } else {
            $tables = [
                'Orders_WALLMART',
                'Orders_RIPLEY',
                'Orders_FALABELLA',
                'Orders_MERCADO_LIBRE',
                'Orders_PARIS',
                'Orders_WOOCOMMERCE'
            ];
        }

        // Recolectar datos
        $ordersData = [];
        
        foreach ($tables as $currentTable) {
            $marketplace = str_replace('Orders_', '', $currentTable);
            
            // Verificar que haya IDs de órdenes
            if (empty($orderIds)) {
                continue;
            }
            
            // Crear placeholders para la consulta SQL
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            
            // Construir consulta SQL para obtener todos los campos necesarios
            $sql = "SELECT * FROM `$currentTable` WHERE id IN ($placeholders)";
            
            try {
                $statement = $this->dbAdapter->createStatement($sql);
                $result = $statement->execute($orderIds);
                
                // Agregar marketplace a cada registro y convertir las IDs a string para evitar problemas
                foreach ($result as $row) {
                    // Convertir el ID a string para evitar problemas con números grandes
                    $row['id'] = (string)$row['id'];
                    $row['marketplace'] = $marketplace;
                    
                    // Asegúrate de que todas las claves estén presentes con valores predeterminados
                    $ordersData[] = array_merge([
                        'id' => '',
                        'marketplace' => '',
                        'cliente' => '',
                        'telefono' => '',
                        'direccion' => '',
                        'fecha_creacion' => '',
                        'fecha_entrega' => '',
                        'estado' => '',
                        'transportista' => '',
                        'num_seguimiento' => '',
                        'total' => 0,
                        'productos' => ''
                    ], $row);
                }
            } catch (\Exception $e) {
                // Registrar el error pero continuar con otras tablas
                error_log("Error al consultar tabla $currentTable: " . $e->getMessage());
            }
        }
        
        if (empty($ordersData)) {
            throw new \Exception("No se encontraron órdenes con los IDs seleccionados.");
        }
        
        // Crear objeto Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Órdenes');
        
        // Definir encabezados
        $headers = [
            'ID', 'Marketplace', 'Cliente', 'Teléfono', 'Dirección', 
            'Fecha Creación', 'Fecha Entrega', 'Estado', 'Transportista', 
            'Num. Seguimiento', 'Total', 'Productos'
        ];
        
        // Escribir encabezados (empezando desde la columna A, fila 1)
        foreach ($headers as $index => $header) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column . '1', $header);
        }
        
        // Añadir estilo a encabezados
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2EFDA']
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
                ]
            ]
        ];
        $sheet->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . '1')->applyFromArray($headerStyle);
        
        // Escribir datos empezando desde la fila 2
        $row = 2;
        foreach ($ordersData as $order) {
            $col = 1;
            $sheet->setCellValueByColumnAndRow($col++, $row, $order['id']);
            $sheet->setCellValueByColumnAndRow($col++, $row, $order['marketplace']);
            $sheet->setCellValueByColumnAndRow($col++, $row, $order['cliente'] ?? '');
            $sheet->setCellValueByColumnAndRow($col++, $row, $order['telefono'] ?? '');
            $sheet->setCellValueByColumnAndRow($col++, $row, $order['direccion'] ?? '');
            $sheet->setCellValueByColumnAndRow($col++, $row, $order['fecha_creacion'] ?? '');
            $sheet->setCellValueByColumnAndRow($col++, $row, $order['fecha_entrega'] ?? '');
            $sheet->setCellValueByColumnAndRow($col++, $row, $order['estado'] ?? '');
            $sheet->setCellValueByColumnAndRow($col++, $row, $order['transportista'] ?? '');
            $sheet->setCellValueByColumnAndRow($col++, $row, $order['num_seguimiento'] ?? '');
            $sheet->setCellValueByColumnAndRow($col++, $row, $order['total'] ? number_format((float)$order['total'], 0, ',', '.') : '0');
            $sheet->setCellValueByColumnAndRow($col++, $row, $order['productos'] ?? '');
            
            $row++;
        }
        
        // Autoajustar columnas
        foreach (range('A', \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers))) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        
        // Crear archivo Excel
        $writer = new Xlsx($spreadsheet);
        $filename = 'Ordenes_' . date('Y-m-d_His') . '.xlsx';
        $tempFile = tempnam(sys_get_temp_dir(), 'excel_');
        $writer->save($tempFile);
        
        // Leer el contenido del archivo
        $fileContent = file_get_contents($tempFile);
        
        // Crear respuesta HTTP
        $response = new Response();
        $response->setContent($fileContent);
        
        // Configurar cabeceras
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $headers->addHeaderLine('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $headers->addHeaderLine('Content-Length', strlen($fileContent));
        $headers->addHeaderLine('Cache-Control', 'max-age=0');
        
        // Eliminar archivo temporal
        unlink($tempFile);
        
        return $response;
    }

/**
 * Acción para mostrar el detalle de una tabla con paginación.
 * Se espera recibir el parámetro 'table' en la URL.
 */
public function detailAction()
{
    // Verificar autenticación
    $redirect = $this->checkAuth();
    if ($redirect !== null) {
        return $redirect;
    }
    
    // Obtener nombre de la tabla desde la ruta
    $table = $this->params()->fromRoute('table', null);
    if (!$table) {
        return $this->redirect()->toRoute('application', ['action' => 'dashboard']);
    }

    // Parámetros de paginación
    $page  = (int) $this->params()->fromQuery('page', 1);
    $limit = (int) $this->params()->fromQuery('limit', 150);
    // Limitar a máximo 150 registros
    $limit = min($limit, 50);
    $offset = ($page - 1) * $limit;

    // Verificar exportación a CSV
    $export = $this->params()->fromQuery('export', false);
    
    // Obtener búsqueda global
    $search = $this->params()->fromQuery('search', '');

    // Obtener parámetros de filtro
    $filters = [];
    $whereParams = [];
    $whereConditions = [];
    
    $queryParams = $this->getRequest()->getQuery()->toArray();
    foreach ($queryParams as $key => $value) {
        if (strpos($key, 'filter_') === 0 && !empty($value)) {
            $columnName = substr($key, 7); // Eliminar 'filter_' del nombre
            $filters[$columnName] = $value;
            $whereConditions[] = "`$columnName` LIKE ?";
            $whereParams[] = '%' . $value . '%';
        }
    }
    
    // Agregar búsqueda global si existe
    if (!empty($search)) {
        // Obtener las columnas de la tabla
        $metadataSql = "SHOW COLUMNS FROM `$table`";
        $metadataStmt = $this->dbAdapter->createStatement($metadataSql);
        $metadataResult = $metadataStmt->execute();
        
        $globalSearchConditions = [];
        foreach ($metadataResult as $col) {
            $globalSearchConditions[] = "`" . $col['Field'] . "` LIKE ?";
            $whereParams[] = '%' . $search . '%';
        }
        
        if (!empty($globalSearchConditions)) {
            $whereConditions[] = "(" . implode(" OR ", $globalSearchConditions) . ")";
        }
    }
    
    // Construir cláusula WHERE
    $whereClause = '';
    if (!empty($whereConditions)) {
        $whereClause = " WHERE " . implode(" AND ", $whereConditions);
    }
    
    // Si es exportación a CSV
    if ($export === 'csv') {
        return $this->exportToCsv($table, $whereClause, $whereParams);
    }
    
    // Consulta para contar registros
    $countSql = "SELECT COUNT(*) as total FROM `$table`" . $whereClause;
    $countStatement = $this->dbAdapter->createStatement($countSql);
    $countResult = $countStatement->execute($whereParams)->current();
    $total = (int) ($countResult['total'] ?? 0);
    
    // Consulta para obtener datos paginados
    $dataSql = "SELECT * FROM `$table`" . $whereClause . " LIMIT $limit OFFSET $offset";
    $dataStatement = $this->dbAdapter->createStatement($dataSql);
    $dataResult = $dataStatement->execute($whereParams);
    
    $data = [];
    foreach ($dataResult as $row) {
        $data[] = $row;
    }
    
    $totalPages = ceil($total / $limit);
    
    // Inicializar arrays para gráficos
    $ventasAnualesArray = [];
    $topProductosArray = [];
        
    // Comprobar si la tabla actual es un marketplace (MKP_)
    if (!empty($data) && (
        stripos($table, 'mkp_') !== false || 
        stripos($table, 'paris') !== false || 
        stripos($table, 'falabella') !== false ||
        stripos($table, 'ripley') !== false ||
        stripos($table, 'wallmart') !== false ||
        stripos($table, 'mercado_libre') !== false
    )) {
        $actualTableName = $table;  // Tabla real que estamos viendo
        error_log("Detectada tabla de marketplace: $actualTableName");
        
        try {
            // Verificar las columnas de la tabla para adaptar las consultas
            $columnsSql = "SHOW COLUMNS FROM `$actualTableName`";
            $columnsStmt = $this->dbAdapter->createStatement($columnsSql);
            $columnsResult = $columnsStmt->execute();
            
            // Mapear nombres de columnas reales
            $columnMap = [];
            $allColumns = [];
            
            foreach ($columnsResult as $column) {
                $columnName = $column['Field'];
                $allColumns[] = $columnName;
                
                // Mapeamos los nombres según patrones comunes
                if (stripos($columnName, 'precio_base') !== false || stripos($columnName, 'precio_sin') !== false || stripos($columnName, 'base_imponible') !== false || stripos($columnName, 'monto_liquidacion') !== false) {
                    $columnMap['precio_base'] = $columnName;
                }
                elseif (stripos($columnName, 'impuesto') !== false || stripos($columnName, 'iva') !== false || stripos($columnName, 'tax') !== false || stripos($columnName, 'monto_impuesto_boleta') !== false) {
                    $columnMap['impuesto'] = $columnName;
                }
                elseif (stripos($columnName, 'monto_total') !== false || stripos($columnName, 'total_boleta') !== false || stripos($columnName, 'precio_con') !== false || stripos($columnName, 'precio_despues_descuento') !== false || stripos($columnName, 'monto_total_boleta') !== false) {
                    $columnMap['monto_total'] = $columnName;
                }
                elseif (stripos($columnName, 'fecha_crea') !== false || stripos($columnName, 'date_created') !== false || stripos($columnName, 'creation_date') !== false) {
                    $columnMap['fecha_creacion'] = $columnName;
                }
                elseif (stripos($columnName, 'estado') !== false || stripos($columnName, 'status') !== false || stripos($columnName, 'state') !== false) {
                    $columnMap['estado'] = $columnName;
                }
                elseif (stripos($columnName, 'numero_boleta') !== false || stripos($columnName, 'num_boleta') !== false || stripos($columnName, 'orden_id') !== false || stripos($columnName, 'order_id') !== false || stripos($columnName, 'id_factura') !== false || stripos($columnName, 'pedido_id') !== false || stripos($columnName, 'numero_suborden') !== false) {
                    $columnMap['numero_boleta'] = $columnName;
                }
                elseif (stripos($columnName, 'producto') !== false || stripos($columnName, 'product') !== false || stripos($columnName, 'item') !== false || stripos($columnName, 'sku') !== false || stripos($columnName, 'descripcion') !== false || stripos($columnName, 'nombre_producto') !== false) {
                    $columnMap['producto'] = $columnName;
                }
            }
            
            // Log para depuración
            error_log("Columnas encontradas en $actualTableName: " . implode(", ", $allColumns));
            error_log("Mapeo de columnas: " . json_encode($columnMap));
            
            // Nombres de columna correctos para esta tabla específica
            // Usar el nombre real encontrado o caer en un valor predeterminado
            $colPrecioBase = $columnMap['precio_base'] ?? 'precio_base';
            $colImpuesto = $columnMap['impuesto'] ?? 'monto_impuesto_boleta';
            $colMontoTotal = $columnMap['monto_total'] ?? 'monto_total_boleta';
            $colFechaCreacion = $columnMap['fecha_creacion'] ?? 'fecha_creacion';
            $colEstado = $columnMap['estado'] ?? 'estado';
            $colNumeroBoletaDesambiguado = $columnMap['numero_boleta'] ?? 'numero_boleta';
            $colProducto = $columnMap['producto'] ?? 'nombre_producto';
            
            // Si no hay columna de número de boleta, usar uno alternativo o ID
            if (!isset($columnMap['numero_boleta'])) {
                if (in_array('numero_suborden', $allColumns)) {
                    $colNumeroBoletaDesambiguado = 'numero_suborden';
                    error_log("Usando número de suborden como identificador de boleta");
                } elseif (in_array('id', $allColumns)) {
                    $colNumeroBoletaDesambiguado = 'id';
                    error_log("Usando 'id' como identificador de boleta");
                }
            }
            
            // Condición para filtrar registros cancelados (buscar patrones comunes)
            $condicionNoCancelado = "(`$colEstado` NOT LIKE '%cancel%' 
                AND `$colEstado` NOT LIKE '%anulad%'
                AND `$colEstado` NOT LIKE '%rechaz%'
                AND `$colEstado` NOT LIKE '%delet%')";
                
            // ======== CONSULTA 4: Ventas por mes (ÚLTIMOS 12 MESES) - con unicidad ========
            try {
                // Calculamos la fecha de hace 12 meses 
                $fechaInicio = date('Y-m-d', strtotime('-11 months'));
                $fechaInicioArray = explode('-', $fechaInicio);
                $anioInicio = (int)$fechaInicioArray[0];
                $mesInicio = (int)$fechaInicioArray[1];
                
                $sqlVentasPorMes = "
                SELECT 
                    DATE_FORMAT(fecha_creacion, '%Y-%m') AS mes_anio,
                    DATE_FORMAT(fecha_creacion, '%M %Y') AS nombre_mes,
                    YEAR(fecha_creacion) AS anio,
                    MONTH(fecha_creacion) AS mes,
                    COUNT(*) AS cantidad,
                    SUM(monto_total) AS ventas
                FROM (
                    SELECT 
                        `$colNumeroBoletaDesambiguado` AS numero_boleta,
                        MIN(`$colFechaCreacion`) AS fecha_creacion,
                        MAX(`$colMontoTotal`) AS monto_total
                    FROM `$actualTableName`
                    WHERE $condicionNoCancelado
                    AND `$colFechaCreacion` >= '$fechaInicio'
                    GROUP BY `$colNumeroBoletaDesambiguado`
                ) AS boletas_unicas
                GROUP BY mes_anio, nombre_mes, anio, mes
                ORDER BY anio ASC, mes ASC
                ";
                
                error_log("Ejecutando consulta de ventas por mes (últimos 12 meses): $sqlVentasPorMes");
                $statementVentasPorMes = $this->dbAdapter->createStatement($sqlVentasPorMes);
                $resultVentasPorMes = $statementVentasPorMes->execute();
                
                // Generar array con los últimos 12 meses
                $ventasAnualesArray = [];
                $mesesUltimosDoce = [];
                
                // Crear array para todos los meses de los últimos 12 meses
                for ($i = 0; $i < 12; $i++) {
                    $fechaMes = date('Y-m-d', strtotime("-$i months"));
                    $timestamp = strtotime($fechaMes);
                    $nombreMes = date('F Y', $timestamp);
                    $mesAnio = date('Y-m', $timestamp);
                    $mesesUltimosDoce[$mesAnio] = [
                        'mes' => $nombreMes,
                        'ventas' => 0,
                        'cantidad' => 0
                    ];
                }
                
                // Rellenar con datos reales
                if ($resultVentasPorMes->count() > 0) {
                    foreach ($resultVentasPorMes as $row) {
                        $mesAnio = $row['mes_anio'];
                        if (isset($mesesUltimosDoce[$mesAnio])) {
                            $mesesUltimosDoce[$mesAnio] = [
                                'mes' => $row['nombre_mes'],
                                'ventas' => (float)($row['ventas'] ?? 0),
                                'cantidad' => (int)($row['cantidad'] ?? 0)
                            ];
                        }
                    }
                }
                
                // Convertir a array indexado y ordenar por fecha
                // Invertimos el orden para mostrar desde el mes más antiguo al más reciente
                $mesesOrdenados = [];
                foreach ($mesesUltimosDoce as $mesAnio => $mesData) {
                    $partes = explode('-', $mesAnio);
                    $anio = (int)$partes[0];
                    $mes = (int)$partes[1];
                    $mesesOrdenados[$anio * 100 + $mes] = $mesData;
                }
                
                ksort($mesesOrdenados);
                
                // Ahora creamos el array final
                foreach ($mesesOrdenados as $mesData) {
                    $ventasAnualesArray[] = $mesData;
                }
                
                error_log("Ventas de últimos 12 meses generadas: " . count($ventasAnualesArray) . " meses");
            } catch (\Exception $e) {
                error_log("Error en consulta de ventas por mes: " . $e->getMessage());
                
                // Intento alternativo sin unicidad
                try {
                    $fechaInicio = date('Y-m-d', strtotime('-11 months'));
                    
                    $sqlVentasPorMesAlt = "
                    SELECT 
                        DATE_FORMAT(`$colFechaCreacion`, '%Y-%m') AS mes_anio,
                        DATE_FORMAT(`$colFechaCreacion`, '%M %Y') AS nombre_mes,
                        YEAR(`$colFechaCreacion`) AS anio,
                        MONTH(`$colFechaCreacion`) AS mes,
                        COUNT(*) AS cantidad,
                        SUM(`$colMontoTotal`) AS ventas
                    FROM `$actualTableName`
                    WHERE $condicionNoCancelado
                    AND `$colFechaCreacion` >= '$fechaInicio'
                    GROUP BY mes_anio, nombre_mes, anio, mes
                    ORDER BY anio ASC, mes ASC
                    ";
                    
                    error_log("Ejecutando consulta alternativa: $sqlVentasPorMesAlt");
                    $statementVentasPorMesAlt = $this->dbAdapter->createStatement($sqlVentasPorMesAlt);
                    $resultVentasPorMesAlt = $statementVentasPorMesAlt->execute();
                    
                    // Preparamos array para los últimos 12 meses
                    $mesesUltimosDoce = [];
                    for ($i = 0; $i < 12; $i++) {
                        $fechaMes = date('Y-m-d', strtotime("-$i months"));
                        $timestamp = strtotime($fechaMes);
                        $nombreMes = date('F Y', $timestamp);
                        $mesAnio = date('Y-m', $timestamp);
                        $mesesUltimosDoce[$mesAnio] = [
                            'mes' => $nombreMes,
                            'ventas' => 0,
                            'cantidad' => 0
                        ];
                    }
                    
                    // Rellenar con datos reales
                    if ($resultVentasPorMesAlt->count() > 0) {
                        foreach ($resultVentasPorMesAlt as $row) {
                            $mesAnio = $row['mes_anio'];
                            if (isset($mesesUltimosDoce[$mesAnio])) {
                                $mesesUltimosDoce[$mesAnio] = [
                                    'mes' => $row['nombre_mes'],
                                    'ventas' => (float)($row['ventas'] ?? 0),
                                    'cantidad' => (int)($row['cantidad'] ?? 0)
                                ];
                            }
                        }
                    }
                    
                    // Ordenar y convertir a array
                    $mesesOrdenados = [];
                    foreach ($mesesUltimosDoce as $mesAnio => $mesData) {
                        $partes = explode('-', $mesAnio);
                        $anio = (int)$partes[0];
                        $mes = (int)$partes[1];
                        $mesesOrdenados[$anio * 100 + $mes] = $mesData;
                    }
                    
                    ksort($mesesOrdenados);
                    $ventasAnualesArray = array_values($mesesOrdenados);
                    
                    error_log("Ventas de últimos 12 meses alternativas: " . count($ventasAnualesArray) . " meses");
                } catch (\Exception $e2) {
                    error_log("Error en consulta alternativa de ventas por mes: " . $e2->getMessage());
                    
                    // Generar datos de ejemplo como fallback - CON CONVERSIÓN EXPLÍCITA A ENTERO
                    $ventasAnualesArray = [];
                    for ($i = 11; $i >= 0; $i--) {
                        $fechaMes = date('Y-m-d', strtotime("-$i months"));
                        $timestamp = strtotime($fechaMes);
                        $nombreMes = date('F Y', $timestamp);
                        $ventasAnualesArray[] = [
                            'mes' => $nombreMes,
                            'ventas' => 0,
                            'cantidad' => 0
                        ];
                    }
                    error_log("Generados " . count($ventasAnualesArray) . " meses con datos vacíos");
                }
            }
            
            // ======== CONSULTA 5: Top 10 productos (con unicidad) del MES ACTUAL ========
            try {
                // Mes y año actuales
                $mesActual = (int)date('n');  // Número de mes (1-12)
                $anioActual = (int)date('Y'); // Año actual
                
                // Condición para el mes actual
                $condicionMesActual = "MONTH(`$colFechaCreacion`) = " . $mesActual . " AND YEAR(`$colFechaCreacion`) = " . $anioActual;
                
                $sqlTopProductos = "
                SELECT 
                    producto,
                    COUNT(*) AS cantidad,
                    SUM(monto_total) AS ventas
                FROM (
                    SELECT 
                        `$colNumeroBoletaDesambiguado` AS numero_boleta,
                        `$colProducto` AS producto,
                        MAX(`$colMontoTotal`) AS monto_total
                    FROM `$actualTableName`
                    WHERE $condicionMesActual
                    AND $condicionNoCancelado
                    AND `$colProducto` IS NOT NULL
                    AND `$colProducto` != ''
                    GROUP BY `$colNumeroBoletaDesambiguado`, `$colProducto`
                ) AS productos_unicos
                GROUP BY producto
                ORDER BY ventas DESC
                LIMIT 10
                ";
                
                error_log("Ejecutando consulta de top productos: $sqlTopProductos");
                $statementTopProductos = $this->dbAdapter->createStatement($sqlTopProductos);
                $resultTopProductos = $statementTopProductos->execute();
                
                if ($resultTopProductos->count() > 0) {
                    foreach ($resultTopProductos as $row) {
                        // Extraer nombre del producto (limitado a 50 caracteres)
                        $nombreProducto = $row['producto'] ?? 'Producto sin nombre';
                        if (strlen($nombreProducto) > 50) {
                            $nombreProducto = substr($nombreProducto, 0, 47) . '...';
                        }
                        
                        $topProductosArray[] = [
                            'nombre' => $nombreProducto,
                            'ventas' => (float)($row['ventas'] ?? 0),
                            'cantidad' => (int)($row['cantidad'] ?? 0)
                        ];
                    }
                    error_log("Top productos generados: " . count($topProductosArray));
                } else {
                    // Si no hay productos este mes, buscar productos de toda la historia
                    $sqlTopProductosAlt = "
                    SELECT 
                        `$colProducto` AS producto,
                        COUNT(*) AS cantidad,
                        SUM(`$colMontoTotal`) AS ventas
                    FROM `$actualTableName`
                    WHERE $condicionNoCancelado
                    AND `$colProducto` IS NOT NULL
                    AND `$colProducto` != ''
                    GROUP BY producto
                    ORDER BY ventas DESC
                    LIMIT 10
                    ";
                    
                    error_log("Ejecutando consulta alternativa: $sqlTopProductosAlt");
                    $statementTopProductosAlt = $this->dbAdapter->createStatement($sqlTopProductosAlt);
                    $resultTopProductosAlt = $statementTopProductosAlt->execute();
                    
                    if ($resultTopProductosAlt->count() > 0) {
                        foreach ($resultTopProductosAlt as $row) {
                            $nombreProducto = $row['producto'] ?? 'Producto sin nombre';
                            if (strlen($nombreProducto) > 50) {
                                $nombreProducto = substr($nombreProducto, 0, 47) . '...';
                            }
                            
                            $topProductosArray[] = [
                                'nombre' => $nombreProducto,
                                'ventas' => (float)($row['ventas'] ?? 0),
                                'cantidad' => (int)($row['cantidad'] ?? 0)
                            ];
                        }
                        error_log("Top productos históricos generados: " . count($topProductosArray));
                    }
                }
            } catch (\Exception $e) {
                error_log("Error en consulta de top productos: " . $e->getMessage());
                
                // Generar productos de ejemplo si no hay datos
                if (empty($topProductosArray)) {
                    for ($i = 1; $i <= 5; $i++) {
                        $topProductosArray[] = [
                            'nombre' => "Producto Ejemplo $i",
                            'ventas' => 0,
                            'cantidad' => 0
                        ];
                    }
                    error_log("Generados 5 productos de ejemplo");
                }
            }
            
        } catch (\Exception $e) {
            // Log del error principal
            error_log("Error en consultas de KPIs: " . $e->getMessage());
            error_log("Rastreo: " . $e->getTraceAsString());
            
            // Crear datos de ejemplo para los últimos 12 meses
            $ventasAnualesArray = [];
            for ($i = 11; $i >= 0; $i--) {
                $fechaMes = date('Y-m-d', strtotime("-$i months"));
                $timestamp = strtotime($fechaMes);
                $nombreMes = date('F Y', $timestamp);
                $ventasAnualesArray[] = [
                    'mes' => $nombreMes,
                    'ventas' => 0,
                    'cantidad' => 0
                ];
            }
            
            for ($i = 1; $i <= 5; $i++) {
                $topProductosArray[] = [
                    'nombre' => "Producto Ejemplo $i",
                    'ventas' => 0,
                    'cantidad' => 0
                ];
            }
        }
    }
    
    // Sanitizar y codificar JSON
    $jsonVentasAnuales = json_encode($ventasAnualesArray, JSON_NUMERIC_CHECK) ?: '[]';
    $jsonTopProductos = json_encode($topProductosArray, JSON_NUMERIC_CHECK) ?: '[]';
    
    // Pasar todo a la vista
    return new ViewModel([
        'table'                   => $table,
        'data'                    => $data,
        'page'                    => $page,
        'limit'                   => $limit,
        'totalPages'              => $totalPages,
        'total'                   => $total,
        'search'                  => $search,
        'filters'                 => $filters,
        'jsonVentasAnuales'       => $jsonVentasAnuales,
        'jsonTopProductos'        => $jsonTopProductos
    ]);
}

    /**
     * Método para exportar a CSV
     */
    private function exportToCsv($table, $whereClause = '', $whereParams = [])
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
        
        // Consulta para obtener datos filtrados (límite alto para exportación)
        $sql = "SELECT * FROM `$table`" . $whereClause . " LIMIT 10000";
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute($whereParams);
        
        // Verificar si hay datos
        if ($result->count() === 0) {
            return $this->redirect()->toRoute('application', ['action' => 'detail', 'table' => $table]);
        }
        
        // Crear archivo CSV en memoria
        $output = fopen('php://temp', 'r+');
        
        // Escribir cabeceras
        $headers = array_keys($result->current());
        fputcsv($output, $headers);
        
        // Reposicionar al inicio
        $result->rewind();
        
        // Escribir filas
        foreach ($result as $row) {
            fputcsv($output, $row);
        }
        
        // Volver al inicio del archivo
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);
        
        // Crear respuesta HTTP
        $response = new Response();
        $response->setContent($csvContent);
        
        // Configurar cabeceras
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'text/csv; charset=utf-8');
        $headers->addHeaderLine('Content-Disposition', 'attachment; filename="' . $table . '_export_' . date('Y-m-d') . '.csv"');
        $headers->addHeaderLine('Content-Length', strlen($csvContent));
        $headers->addHeaderLine('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $headers->addHeaderLine('Pragma', 'public');
        $headers->addHeaderLine('Expires', '0');
        
        return $response;
    }

    /**
     * Acción para gestionar la configuración de integración de marketplaces
     */
    public function marketplaceConfigAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
        
        // Obtener todas las configuraciones existentes
        $sql = "SELECT * FROM api_config ORDER BY marketplace";
        $statement = $this->dbAdapter->createStatement($sql);
        $configs = $statement->execute();
        
        $configsArray = [];
        foreach ($configs as $row) {
            $configsArray[] = $row;
        }
        
        // Procesar formulario si se ha enviado
        $message = '';
        $messageType = '';
        
        if ($this->getRequest()->isPost()) {
            $postData = $this->params()->fromPost();
            
            if (isset($postData['id']) && $postData['id'] > 0) {
                // Actualizar registro existente
                $sql = "UPDATE api_config SET 
                        api_url = ?, 
                        api_key = ?, 
                        accesstoken = ?, 
                        offset = ?, 
                        marketplace = ?, 
                        update_at = NOW() 
                        WHERE id = ?";
                
                $params = [
                    $postData['api_url'],
                    $postData['api_key'],
                    $postData['accesstoken'] ?? null,
                    (int)($postData['offset'] ?? 0),
                    $postData['marketplace'],
                    $postData['id']
                ];
                
                $statement = $this->dbAdapter->createStatement($sql);
                $result = $statement->execute($params);
                
                $message = 'Configuración actualizada correctamente';
                $messageType = 'success';
            } else {
                // Insertar nuevo registro
                $sql = "INSERT INTO api_config 
                        (api_url, api_key, accesstoken, offset, marketplace, created_at, update_at) 
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
                
                $params = [
                    $postData['api_url'],
                    $postData['api_key'],
                    $postData['accesstoken'] ?? null,
                    (int)($postData['offset'] ?? 0),
                    $postData['marketplace']
                ];
                
                $statement = $this->dbAdapter->createStatement($sql);
                $result = $statement->execute($params);
                
                $message = 'Nueva configuración creada correctamente';
                $messageType = 'success';
            }
            
            // Recargar datos
            $statement = $this->dbAdapter->createStatement("SELECT * FROM api_config ORDER BY marketplace");
            $configs = $statement->execute();
            $configsArray = [];
            foreach ($configs as $row) {
                $configsArray[] = $row;
            }
        }
        
        // Procesar eliminación si se solicita
        $deleteId = $this->params()->fromQuery('delete', null);
        if ($deleteId) {
            $sql = "DELETE FROM api_config WHERE id = ?";
            $statement = $this->dbAdapter->createStatement($sql);
            $result = $statement->execute([$deleteId]);
            
            $message = 'Configuración eliminada correctamente';
            $messageType = 'success';
            
            // Redireccionar para evitar problemas con F5
            return $this->redirect()->toRoute('application', ['action' => 'marketplace-config']);
        }
        
        return new ViewModel([
            'configs' => $configsArray,
            'message' => $message,
            'messageType' => $messageType
        ]);
    }

    /**
     * Método para probar la conexión con un marketplace
     */
    public function testConnectionAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
        
        $id = $this->params()->fromQuery('id');
        if (!$id) {
            return $this->jsonResponse(['success' => false, 'message' => 'ID de configuración no proporcionado']);
        }
        
        // Obtener la configuración de la API
        $sql = "SELECT * FROM api_config WHERE id = ?";
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute([$id]);
        $config = $result->current();
        
        if (!$config) {
            return $this->jsonResponse(['success' => false, 'message' => 'Configuración no encontrada']);
        }
        
        try {
            // Crear cliente HTTP
            $client = new \Laminas\Http\Client();
            $client->setOptions([
                'timeout' => 30,
                'sslverifypeer' => false // Para desarrollo - en producción debería ser true
            ]);
            
            // Configurar la solicitud
            $apiUrl = $config['api_url'];
            // Añadir una ruta de prueba si el endpoint solo es la base
            if (substr($apiUrl, -1) === '/') {
                $apiUrl .= 'status'; // O algún endpoint común para verificar estado
            }
            
            $client->setUri($apiUrl);
            $client->setMethod('GET');
            
            // Agregar headers de autenticación según la configuración
            $client->setHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]);
            
            if (!empty($config['api_key'])) {
                $client->setHeaders(['X-API-Key' => $config['api_key']]);
            }
            
            if (!empty($config['accesstoken'])) {
                $client->setHeaders(['Authorization' => 'Bearer ' . $config['accesstoken']]);
            }
            
            // Realizar la solicitud
            $response = $client->send();
            
            // Verificar la respuesta
            if ($response->isSuccess()) {
                return $this->jsonResponse([
                    'success' => true, 
                    'message' => 'Conexión exitosa',
                    'statusCode' => $response->getStatusCode(),
                    'responseBody' => substr($response->getBody(), 0, 500) // Limitar longitud
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false, 
                    'message' => 'Error en la conexión',
                    'statusCode' => $response->getStatusCode(),
                    'responseBody' => substr($response->getBody(), 0, 500) // Limitar longitud
                ]);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false, 
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Método para devolver respuestas JSON
     */
    private function jsonResponse($data)
    {
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $response->setContent(json_encode($data));
        return $response;
    }



/**
 * Acción principal para visualizar todas las órdenes de todos los marketplaces
 */
public function ordersAction()
{
    $redirect = $this->checkAuth();
    if ($redirect !== null) {
        return $redirect;
    }

    $statsSql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN estado = 'Nueva' THEN 1 ELSE 0 END) as nuevas,
                    SUM(CASE WHEN estado = 'En Proceso' THEN 1 ELSE 0 END) as en_proceso,
                    SUM(CASE WHEN estado = 'Enviada' THEN 1 ELSE 0 END) as enviadas,
                    SUM(CASE WHEN estado = 'Entregada' THEN 1 ELSE 0 END) as entregadas,
                    SUM(CASE WHEN estado = 'Pendiente de Pago' THEN 1 ELSE 0 END) as pendiente_pago,
                    SUM(CASE WHEN estado = 'Cancelada' THEN 1 ELSE 0 END) as canceladas,
                    SUM(CASE WHEN estado = 'Devuelta' THEN 1 ELSE 0 END) as devueltas
                FROM (
                    SELECT estado FROM Orders_WALLMART
                    UNION ALL SELECT estado FROM Orders_RIPLEY
                    UNION ALL SELECT estado FROM Orders_FALABELLA
                    UNION ALL SELECT estado FROM Orders_MERCADO_LIBRE
                    UNION ALL SELECT estado FROM Orders_PARIS
                    UNION ALL SELECT estado FROM Orders_WOOCOMMERCE
                ) as all_orders";

    $statsStatement = $this->dbAdapter->createStatement($statsSql);
    $statsResult = $statsStatement->execute();
    $stats = $statsResult->current() ?: [
        'total' => 0,
        'nuevas' => 0,
        'en_proceso' => 0,
        'enviadas' => 0,
        'entregadas' => 0,
        'pendiente_pago' => 0,
        'canceladas' => 0,
        'devueltas' => 0
    ];

    $page = (int) $this->params()->fromQuery('page', 1);
    $limit = (int) $this->params()->fromQuery('limit', 30);
    $offset = ($page - 1) * $limit;

    $search = $this->params()->fromQuery('search', '');
    $statusFilter = $this->params()->fromQuery('status', '');
    $transportistaFilter = $this->params()->fromQuery('transportista', '');
    $startDate = $this->params()->fromQuery('startDate', '');
    $endDate = $this->params()->fromQuery('endDate', '');

    $whereConditions = [];
    $whereParams = [];

    if (!empty($search)) {
        $whereConditions[] = "(suborder_number LIKE ? OR cliente LIKE ? OR telefono LIKE ? OR direccion LIKE ?)";
        $whereParams[] = "%$search%";
        $whereParams[] = "%$search%";
        $whereParams[] = "%$search%";
        $whereParams[] = "%$search%";
    }
    if (!empty($statusFilter)) {
        $whereConditions[] = "estado = ?";
        $whereParams[] = $statusFilter;
    }
    if (!empty($transportistaFilter)) {
        $whereConditions[] = "transportista = ?";
        $whereParams[] = $transportistaFilter;
    }
    if (!empty($startDate)) {
        $whereConditions[] = "fecha_creacion >= ?";
        $whereParams[] = "$startDate 00:00:00";
    }
    if (!empty($endDate)) {
        $whereConditions[] = "fecha_creacion <= ?";
        $whereParams[] = "$endDate 23:59:59";
    }

    $whereClause = !empty($whereConditions) ? " WHERE " . implode(" AND ", $whereConditions) : "";

    $ordersSql = "SELECT 
            suborder_number AS id,
            marketplace,
            fecha_creacion,
            fecha_entrega,
            delivery_option,
            printed,
            cliente,
            telefono,
            direccion,
            productos,
            total,
            estado,
            transportista,
            num_seguimiento
        FROM (
            SELECT suborder_number, 'WALLMART' as marketplace, fecha_creacion, fecha_entrega, delivery_option, printed, cliente, telefono, direccion, productos, total, estado, transportista, num_seguimiento FROM Orders_WALLMART
            UNION ALL 
            SELECT suborder_number, 'RIPLEY', fecha_creacion, fecha_entrega, delivery_option, printed, cliente, telefono, direccion, productos, total, estado, transportista, num_seguimiento FROM Orders_RIPLEY
            UNION ALL 
            SELECT suborder_number, 'FALABELLA', fecha_creacion, fecha_entrega, delivery_option, printed, cliente, telefono, direccion, productos, total, estado, transportista, num_seguimiento FROM Orders_FALABELLA
            UNION ALL 
            SELECT suborder_number, 'MERCADO_LIBRE', fecha_creacion, fecha_entrega, delivery_option, printed, cliente, telefono, direccion, productos, total, estado, transportista, num_seguimiento FROM Orders_MERCADO_LIBRE
            UNION ALL 
            SELECT suborder_number, 'PARIS', fecha_creacion, fecha_entrega, delivery_option, printed, cliente, telefono, direccion, productos, total, estado, transportista, num_seguimiento FROM Orders_PARIS
            UNION ALL 
            SELECT suborder_number, 'WOOCOMMERCE', fecha_creacion, fecha_entrega, delivery_option, printed, cliente, telefono, direccion, productos, total, estado, transportista, num_seguimiento FROM Orders_WOOCOMMERCE
        ) as all_orders" .
        $whereClause .
        " ORDER BY fecha_creacion DESC
          LIMIT $limit OFFSET $offset";

    $ordersStatement = $this->dbAdapter->createStatement($ordersSql);
    $ordersResult = $ordersStatement->execute($whereParams);

    $orders = [];
    foreach ($ordersResult as $row) {
        $orders[] = $row;
    }

    return new ViewModel([
        'table' => 'all',
        'orders' => $orders,
        'stats' => $stats,
        'page' => $page,
        'limit' => $limit,
        'search' => $search,
        'statusFilter' => $statusFilter,
        'transportistaFilter' => $transportistaFilter,
        'startDate' => $startDate,
        'endDate' => $endDate
    ]);
}

    
    
    
    /**
     * Acción para visualizar las órdenes de un marketplace específico
     */
    public function ordersDetailAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
        
        // Obtener el marketplace desde la ruta
        $table = $this->params()->fromRoute('table', null);
        if (!$table) {
            // Si no se especifica, redirigir a la vista de todas las órdenes
            return $this->redirect()->toRoute('application', ['action' => 'orders']);
        }
        
        // Validar el nombre de la tabla (debe empezar con "Orders_")
        if (strpos($table, 'Orders_') !== 0) {
            return $this->redirect()->toRoute('application', ['action' => 'orders']);
        }
        
        // Obtener estadísticas para este marketplace
        $statsSql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN estado = 'Nueva' THEN 1 ELSE 0 END) as nuevas,
                        SUM(CASE WHEN estado = 'En Proceso' THEN 1 ELSE 0 END) as en_proceso,
                        SUM(CASE WHEN estado = 'Enviada' THEN 1 ELSE 0 END) as enviadas,
                        SUM(CASE WHEN estado = 'Entregada' THEN 1 ELSE 0 END) as entregadas,
                        SUM(CASE WHEN estado = 'Pendiente de Pago' THEN 1 ELSE 0 END) as pendiente_pago,
                        SUM(CASE WHEN estado = 'Cancelada' THEN 1 ELSE 0 END) as canceladas,
                        SUM(CASE WHEN estado = 'Devuelta' THEN 1 ELSE 0 END) as devueltas
                    FROM `$table`";
        
        $statsStatement = $this->dbAdapter->createStatement($statsSql);
        $statsResult = $statsStatement->execute();
        $stats = $statsResult->current() ?: [
            'total' => 0,
            'nuevas' => 0,
            'en_proceso' => 0,
            'enviadas' => 0,
            'entregadas' => 0,
            'pendiente_pago' => 0,
            'canceladas' => 0,
            'devueltas' => 0
        ];
        
        // Parámetros de paginación
        $page = (int) $this->params()->fromQuery('page', 1);
        $limit = (int) $this->params()->fromQuery('limit', 30);
        
        // Obtener filtros
        $filters = [
            'search' => $this->params()->fromQuery('search', ''),
            'status' => $this->params()->fromQuery('status', ''),
            'transportista' => $this->params()->fromQuery('transportista', ''),
            'startDate' => $this->params()->fromQuery('startDate', ''),
            'endDate' => $this->params()->fromQuery('endDate', '')
        ];
        
        // Obtener órdenes paginadas
        $paginatedData = $this->getOrdersWithPagination($table, $page, $limit, $filters);
        
        // Formatear resultados para la vista
        $orders = [];
        foreach ($paginatedData['orders'] as $row) {
            // Añadir el marketplace a cada registro
            $row['marketplace'] = str_replace('Orders_', '', $table);
            $orders[] = $row;
        }
        
        // Devolver la vista con los datos
        return new ViewModel([
            'table' => $table,
            'orders' => $orders,
            'stats' => $stats,
            'page' => $paginatedData['page'],
            'limit' => $paginatedData['limit'],
            'totalPages' => $paginatedData['totalPages'],
            'total' => $paginatedData['total'],
            'search' => $filters['search'],
            'statusFilter' => $filters['status'],
            'transportistaFilter' => $filters['transportista'],
            'startDate' => $filters['startDate'],
            'endDate' => $filters['endDate']
        ]);
    }

    /**
     * Obtiene las órdenes con paginación
     */
    private function getOrdersWithPagination($table, $page = 1, $limit = 30, $filters = [])
    {
        // Valores predeterminados
        $page = max(1, (int)$page);
        $limit = max(10, min(100, (int)$limit)); // Limitar entre 10 y 100
        $offset = ($page - 1) * $limit;
        
        // Construir condiciones de filtro
        $whereConditions = [];
        $whereParams = [];
        
        if (!empty($filters['search'])) {
            $whereConditions[] = "(id LIKE ? OR cliente LIKE ? OR telefono LIKE ? OR direccion LIKE ?)";
            $searchParam = '%' . $filters['search'] . '%';
            $whereParams = array_merge($whereParams, [$searchParam, $searchParam, $searchParam, $searchParam]);
        }
        
        if (!empty($filters['status'])) {
            $whereConditions[] = "estado = ?";
            $whereParams[] = $filters['status'];
        }
        
        if (!empty($filters['transportista'])) {
            $whereConditions[] = "transportista = ?";
            $whereParams[] = $filters['transportista'];
        }
        
        if (!empty($filters['startDate'])) {
            $whereConditions[] = "fecha_creacion >= ?";
            $whereParams[] = $filters['startDate'] . ' 00:00:00';
        }
        
        if (!empty($filters['endDate'])) {
            $whereConditions[] = "fecha_creacion <= ?";
            $whereParams[] = $filters['endDate'] . ' 23:59:59';
        }
        
        // Construir la cláusula WHERE
        $whereClause = empty($whereConditions) ? "" : " WHERE " . implode(" AND ", $whereConditions);
        
        // Primero contamos el total de registros para la paginación
        $countSql = "SELECT COUNT(*) as total FROM `$table`" . $whereClause;
        $countStatement = $this->dbAdapter->createStatement($countSql);
        $countResult = $countStatement->execute($whereParams);
        $totalRecords = (int)$countResult->current()['total'];
        
        // Ahora obtenemos los datos paginados
        $dataSql = "SELECT * FROM `$table`" . $whereClause . " ORDER BY fecha_creacion DESC LIMIT $limit OFFSET $offset";
        $dataStatement = $this->dbAdapter->createStatement($dataSql);
        $dataResult = $dataStatement->execute($whereParams);
        
        $orders = [];
        foreach ($dataResult as $row) {
            $orders[] = $row;
        }
        
        return [
            'orders' => $orders,
            'total' => $totalRecords,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($totalRecords / $limit)
        ];
    }
    
    public function orderDetailAction()
    {
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
    
        $orderId = $this->params()->fromRoute('id', null);
        $table = $this->params()->fromRoute('table', null);
    
        if (!$orderId || !$table) {
            return $this->redirect()->toRoute('application', ['action' => 'orders']);
        }
    
        if (strpos($table, 'Orders_') !== 0) {
            return $this->redirect()->toRoute('application', ['action' => 'orders']);
        }
    
        $sql = "SELECT * FROM `$table` WHERE suborder_number = ?";
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute([$orderId]);
        $order = $result->current();
    
        if (!$order) {
            return $this->redirect()->toRoute('application', ['action' => 'orders-detail', 'table' => $table]);
        }
    
        $productsSql = "SELECT * FROM `{$table}_Items` WHERE order_id = ?";
        $productsStatement = $this->dbAdapter->createStatement($productsSql);
    
        try {
            $productsResult = $productsStatement->execute([$orderId]);
            $products = [];
            foreach ($productsResult as $product) {
                $products[] = $product;
            }
        } catch (\Exception $e) {
            $products = [];
            $productStrings = explode(',', $order['productos'] ?? '');
            foreach ($productStrings as $i => $productString) {
                if (!empty(trim($productString))) {
                    $products[] = [
                        'id' => $i + 1,
                        'nombre' => trim($productString),
                        'sku' => 'SKU-' . str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT),
                        'cantidad' => 1,
                        'precio_unitario' => ($order['total'] ?? 0) / count(array_filter($productStrings)),
                        'subtotal' => ($order['total'] ?? 0) / count(array_filter($productStrings))
                    ];
                }
            }
        }
    
        $subtotal = array_sum(array_column($products, 'subtotal'));
        $envio = isset($order['costo_envio']) ? $order['costo_envio'] : 3990;
        $total = $subtotal + $envio;
    
        return new ViewModel([
            'order' => $order,
            'products' => $products,
            'subtotal' => $subtotal,
            'envio' => $envio,
            'total' => $total,
            'table' => $table
        ]);
    }
    
    
    /**
     * Acción para procesar cambios de estado de órdenes
     */
    public function updateOrderStatusAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
        
        if (!$this->getRequest()->isPost()) {
            return $this->jsonResponse(['success' => false, 'message' => 'Se requiere método POST']);
        }
        
        $orderId = $this->params()->fromPost('orderId', null);
        $table = $this->params()->fromPost('table', null);
        $newStatus = $this->params()->fromPost('newStatus', null);
        $notes = $this->params()->fromPost('notes', '');
        $notifyCustomer = (bool) $this->params()->fromPost('notifyCustomer', false);
        
        if (!$orderId || !$table || !$newStatus) {
            return $this->jsonResponse(['success' => false, 'message' => 'Faltan parámetros requeridos']);
        }
        
        // Validar tabla
        if (strpos($table, 'Orders_') !== 0) {
            return $this->jsonResponse(['success' => false, 'message' => 'Tabla inválida']);
        }
        
        // Validar estado
        $validStates = ['Nueva', 'En Proceso', 'Enviada', 'Entregada', 'Pendiente de Pago', 'Cancelada', 'Devuelta'];
        if (!in_array($newStatus, $validStates)) {
            return $this->jsonResponse(['success' => false, 'message' => 'Estado inválido']);
        }
        
        try {
            // Actualizar estado de la orden
            $sql = "UPDATE `$table` SET estado = ?, updated_at = NOW() WHERE id = ?";
            $statement = $this->dbAdapter->createStatement($sql);
            $result = $statement->execute([$newStatus, $orderId]);
            
            // Registrar el cambio en el historial
            $historySql = "INSERT INTO order_status_history (order_id, table_name, status, notes, created_at) 
                          VALUES (?, ?, ?, ?, NOW())";
            $historyStatement = $this->dbAdapter->createStatement($historySql);
            $historyResult = $historyStatement->execute([$orderId, $table, $newStatus, $notes]);
            
            // Si se debe notificar al cliente
            if ($notifyCustomer) {
                // Aquí iría la lógica para enviar un email al cliente
                // Por ahora solo registramos la intención
                $notifySql = "INSERT INTO order_notifications (order_id, table_name, notification_type, status, created_at) 
                             VALUES (?, ?, 'email', ?, NOW())";
                $notifyStatement = $this->dbAdapter->createStatement($notifySql);
                $notifyResult = $notifyStatement->execute([$orderId, $table, $newStatus]);
            }
            
            return $this->jsonResponse([
                'success' => true, 
                'message' => 'Estado actualizado correctamente',
                'newStatus' => $newStatus
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false, 
                'message' => 'Error al actualizar estado: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Acción para actualizar el transportista de una orden
     */
    public function updateOrderCarrierAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
        
        if (!$this->getRequest()->isPost()) {
            return $this->jsonResponse(['success' => false, 'message' => 'Se requiere método POST']);
        }
        
        $orderId = $this->params()->fromPost('orderId', null);
        $table = $this->params()->fromPost('table', null);
        $newCarrier = $this->params()->fromPost('newCarrier', null);
        $trackingNumber = $this->params()->fromPost('trackingNumber', '');
        $updateStatus = (bool) $this->params()->fromPost('updateStatus', false);
        $notifyCustomer = (bool) $this->params()->fromPost('notifyCustomer', false);
        
        if (!$orderId || !$table || !$newCarrier) {
            return $this->jsonResponse(['success' => false, 'message' => 'Faltan parámetros requeridos']);
        }
        
        // Validar tabla
        if (strpos($table, 'Orders_') !== 0) {
            return $this->jsonResponse(['success' => false, 'message' => 'Tabla inválida']);
        }
        
        try {
            // Actualizar transportista
            $sql = "UPDATE `$table` SET 
                    transportista = ?, 
                    num_seguimiento = ?,
                    updated_at = NOW()";
            
            $params = [$newCarrier, $trackingNumber];
            
            // Si también hay que actualizar el estado
            if ($updateStatus) {
                $sql .= ", estado = ?";
                $params[] = "Enviada";
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $orderId;
            
            $statement = $this->dbAdapter->createStatement($sql);
            $result = $statement->execute($params);
            
            // Registrar el cambio en el historial
            $historyNote = "Transportista actualizado a: $newCarrier";
            if (!empty($trackingNumber)) {
                $historyNote .= ". Número de seguimiento: $trackingNumber";
            }
            
            $historySql = "INSERT INTO order_shipping_history (order_id, table_name, carrier, tracking_number, created_at) 
                          VALUES (?, ?, ?, ?, NOW())";
            $historyStatement = $this->dbAdapter->createStatement($historySql);
            $historyResult = $historyStatement->execute([$orderId, $table, $newCarrier, $trackingNumber]);
            
            // Si se actualizó el estado, registrarlo también
            if ($updateStatus) {
                $statusSql = "INSERT INTO order_status_history (order_id, table_name, status, notes, created_at) 
                             VALUES (?, ?, 'Enviada', ?, NOW())";
                $statusStatement = $this->dbAdapter->createStatement($statusSql);
                $statusResult = $statusStatement->execute([$orderId, $table, $historyNote]);
            }
            
            // Si se debe notificar al cliente
            if ($notifyCustomer) {
                // Aquí iría la lógica para enviar un email al cliente
                $notifySql = "INSERT INTO order_notifications (order_id, table_name, notification_type, status, created_at) 
                             VALUES (?, ?, 'email', 'shipping_update', NOW())";
                $notifyStatement = $this->dbAdapter->createStatement($notifySql);
                $notifyResult = $notifyStatement->execute([$orderId, $table]);
            }
            
            return $this->jsonResponse([
                'success' => true, 
                'message' => 'Transportista actualizado correctamente',
                'carrier' => $newCarrier,
                'trackingNumber' => $trackingNumber
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false, 
                'message' => 'Error al actualizar transportista: ' . $e->getMessage()
            ]);
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
        
        if (!$this->getRequest()->isPost()) {
            return $this->jsonResponse(['success' => false, 'message' => 'Se requiere método POST']);
        }
        
        $orderIds = $this->params()->fromPost('orderIds', []);
        $table = $this->params()->fromPost('table', null);
        $action = $this->params()->fromPost('action', null);
        
        if (empty($orderIds) || !$action) {
            return $this->jsonResponse(['success' => false, 'message' => 'Faltan parámetros requeridos']);
        }
        
        // Procesar según la acción solicitada
        switch ($action) {
            case 'print-labels':
                // Generar etiquetas
                try {
                    $labelsPdf = $this->generateLabels($orderIds, $table);
                    return $labelsPdf;
                } catch (\Exception $e) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => 'Error al generar etiquetas: ' . $e->getMessage()
                    ]);
                }
                
            case 'generate-manifest':
                // Generar manifiesto
                try {
                    $manifestPdf = $this->generateManifest($orderIds, $table);
                    return $manifestPdf;
                } catch (\Exception $e) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => 'Error al generar manifiesto: ' . $e->getMessage()
                    ]);
                }
                
            case 'generate-packing':
                // Generar lista de empaque
                try {
                    $packingPdf = $this->generatePackingList($orderIds, $table);
                    return $packingPdf;
                } catch (\Exception $e) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => 'Error al generar lista de empaque: ' . $e->getMessage()
                    ]);
                }
                
            case 'generate-picking':
                // Generar lista de picking
                try {
                    $pickingPdf = $this->generatePickingList($orderIds, $table);
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
                    $invoicePdf = $this->generateInvoice($orderIds, $table);
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
                            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                            $sql = "UPDATE `$tableToUpdate` SET estado = ?, updated_at = NOW() WHERE id IN ($placeholders)";
                            $params = array_merge([$newStatus], $orderIds);
                            
                            $statement = $this->dbAdapter->createStatement($sql);
                            $result = $statement->execute($params);
                            $updated += $result->getAffectedRows();
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
                // Simular exportación a CSV
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Exportación a CSV generada',
                    'count' => count($orderIds),
                    'csvUrl' => '/exports/orders-' . time() . '.csv'
                ]);
                
            case 'export-excel':
                try {
                    return $this->exportToExcel($orderIds, $table);
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
     * Métodos para generar documentos para órdenes
     */
    
    /**
     * Generar etiquetas de envío para órdenes
     * @param array $orderIds IDs de las órdenes
     * @param string $table Tabla de órdenes (marketplace)
     * @return Response
     */
    private function generateLabels(array $orderIds, string $table = null)
    {
        // Determinar la tabla a usar o aplicarlo para todas
        $tables = [];
        if ($table && $table !== 'all') {
            $tables[] = $table;
        } else {
            $tables = [
                'Orders_WALLMART',
                'Orders_RIPLEY',
                'Orders_FALABELLA',
                'Orders_MERCADO_LIBRE',
                'Orders_PARIS',
                'Orders_WOOCOMMERCE'
            ];
        }
        
        // Recolectar URLs de etiquetas
        $labelUrls = [];
        
        foreach ($tables as $currentTable) {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $sql = "SELECT id, label_url FROM `$currentTable` WHERE id IN ($placeholders)";
            $statement = $this->dbAdapter->createStatement($sql);
            $result = $statement->execute($orderIds);
            
            foreach ($result as $row) {
                if (!empty($row['label_url']) && filter_var($row['label_url'], FILTER_VALIDATE_URL)) {
                    $labelUrls[$row['id']] = $row['label_url'];
                }
            }
        }
        
        if (empty($labelUrls)) {
            throw new \Exception("No se encontraron etiquetas para las órdenes seleccionadas.");
        }
        
        // Crear PDF combinado con FPDI
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        foreach ($labelUrls as $orderId => $url) {
            try {
                // Descargar el PDF de la etiqueta
                $tempFile = tempnam(sys_get_temp_dir(), 'etiqueta_') . '.pdf';
                file_put_contents($tempFile, file_get_contents($url));
                
                // Importar las páginas del PDF
                $pageCount = $pdf->setSourceFile($tempFile);
                for ($i = 1; $i <= $pageCount; $i++) {
                    $tplId = $pdf->importPage($i);
                    $size = $pdf->getTemplateSize($tplId);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($tplId);
                }
                
                // Eliminar archivo temporal
                unlink($tempFile);
            } catch (\Exception $e) {
                // Continuar con la siguiente etiqueta si hay error
                continue;
            }
        }
        
        // Generar la salida del PDF
        $pdfContent = $pdf->Output('S');
        
        // Crear respuesta HTTP
        $response = new Response();
        $response->setContent($pdfContent);
        
        // Configurar cabeceras
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/pdf');
        $headers->addHeaderLine('Content-Disposition', 'inline; filename="Etiquetas_' . date('Y-m-d') . '.pdf"');
        $headers->addHeaderLine('Content-Length', strlen($pdfContent));
        $headers->addHeaderLine('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $headers->addHeaderLine('Pragma', 'public');
        $headers->addHeaderLine('Expires', '0');
        
        return $response;
    }
    
    /**
     * Generar manifiesto para órdenes
     * @param array $orderIds IDs de las órdenes
     * @param string $table Tabla de órdenes (marketplace)
     * @return Response
     */
    private function generateManifest(array $orderIds, string $table = null)
    {
        // Determinar la tabla a usar o aplicarlo para todas
        $tables = [];
        if ($table && $table !== 'all') {
            $tables[] = $table;
        } else {
            $tables = [
                'Orders_WALLMART',
                'Orders_RIPLEY',
                'Orders_FALABELLA',
                'Orders_MERCADO_LIBRE',
                'Orders_PARIS',
                'Orders_WOOCOMMERCE'
            ];
        }
        
        // Recolectar datos de órdenes
        $orders = [];
        
        foreach ($tables as $currentTable) {
            $marketplace = str_replace('Orders_', '', $currentTable);
            
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $sql = "SELECT * FROM `$currentTable` WHERE id IN ($placeholders)";
            $statement = $this->dbAdapter->createStatement($sql);
            $result = $statement->execute($orderIds);
            
            foreach ($result as $row) {
                $row['marketplace'] = $marketplace;
                $orders[] = $row;
            }
        }
        
        if (empty($orders)) {
            throw new \Exception("No se encontraron órdenes con los IDs seleccionados.");
        }
        
        // Generar HTML para el manifiesto
        ob_start();
        ?>
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            th, td { border: 1px solid #000; padding: 6px; text-align: left; }
            th { background-color: #eee; }
            .title { font-size: 20px; font-weight: bold; margin-bottom: 10px; text-align: center; }
            .subtitle { margin: 10px 0; font-weight: bold; }
            .header { text-align: center; margin-bottom: 20px; }
        </style>

        <div class="header">
            <div class="title">Manifiesto de Envío</div>
            <div>GENERADO EL: <?= date("Y-m-d H:i:s") ?></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>ID Orden</th>
                    <th>Marketplace</th>
                    <th>Cliente</th>
                    <th>Dirección</th>
                    <th>Teléfono</th>
                    <th>Transportista</th>
                    <th>N° Seguimiento</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td><?= $order['id'] ?></td>
                    <td><?= $order['marketplace'] ?></td>
                    <td><?= $order['cliente'] ?></td>
                    <td><?= $order['direccion'] ?></td>
                    <td><?= $order['telefono'] ?></td>
                    <td><?= $order['transportista'] ?? 'Sin asignar' ?></td>
                    <td><?= $order['num_seguimiento'] ?? 'Sin asignar' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div><strong>TOTAL ÓRDENES:</strong> <?= count($orders) ?></div>
        
        <div style="margin-top: 50px; border-top: 1px solid #000; padding-top: 10px;">
            <table style="width: 100%; border: none;">
                <tr style="border: none;">
                    <td style="width: 50%; border: none; text-align: center; vertical-align: bottom; padding-top: 50px;">
                        ______________________________<br>
                        Firma Encargado de Envío
                    </td>
                    <td style="width: 50%; border: none; text-align: center; vertical-align: bottom; padding-top: 50px;">
                        ______________________________<br>
                        Firma Transportista
                    </td>
                </tr>
            </table>
        </div>
        <?php
        $html = ob_get_clean();
        
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
     * Generar lista de empaque (Packing List) para órdenes
     * @param array $orderIds IDs de las órdenes
     * @param string $table Tabla de órdenes (marketplace)
     * @return Response
     */
    private function generatePackingList(array $orderIds, string $table = null)
    {
        // Determinar la tabla a usar
        $tables = [];
        if ($table && $table !== 'all') {
            $tables[] = $table;
            $marketplace = str_replace('Orders_', '', $table);
        } else {
            $marketplace = "Todos";
            $tables = [
                'Orders_WALLMART',
                'Orders_RIPLEY',
                'Orders_FALABELLA',
                'Orders_MERCADO_LIBRE',
                'Orders_PARIS',
                'Orders_WOOCOMMERCE'
            ];
        }
        
        // Inicializar el generador de código de barras
        $generator = new BarcodeGeneratorPNG();
        
        // Recopilar órdenes y productos
        $allOrders = [];
        $allProducts = [];
        
        foreach ($tables as $currentTable) {
            $tableMarketplace = str_replace('Orders_', '', $currentTable);
            
            // Obtener los datos de las órdenes en esta tabla
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $sql = "SELECT * FROM `$currentTable` WHERE id IN ($placeholders)";
            $statement = $this->dbAdapter->createStatement($sql);
            $result = $statement->execute($orderIds);
            
            // Procesar cada orden
            foreach ($result as $order) {
                $order['marketplace'] = $tableMarketplace;
                $allOrders[] = $order;
                
                // Consultar productos de la orden
                $itemsTable = $currentTable . "_Items";
                try {
                    $productsStatement = $this->dbAdapter->createStatement("SELECT * FROM `$itemsTable` WHERE order_id = ?");
                    $productsResult = $productsStatement->execute([(string)$order['id']]);
                    
                    $orderProducts = [];
                    foreach ($productsResult as $product) {
                        $orderProducts[] = $product;
                    }
                } catch (\Exception $e) {
                    // Si no hay tabla de items, usar datos básicos
                    $orderProducts = [];
                    $productStrings = explode(',', $order['productos'] ?? '');
                    foreach ($productStrings as $i => $productString) {
                        if (!empty(trim($productString))) {
                            $orderProducts[] = [
                                'id' => $i + 1,
                                'sku' => 'SKU-' . str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT),
                                'name' => trim($productString),
                                'price' => ($order['total'] ?? 0) / count(array_filter($productStrings)),
                                'quantity' => 1
                            ];
                        }
                    }
                }
                
                // Agregar productos a la lista global
                foreach ($orderProducts as $product) {
                    $sku = $product['sku'] ?? $product['SKU'] ?? $product['codigo_sku'] ?? 'N/A';
                    $name = $product['name'] ?? $product['nombre'] ?? $product['nombre_producto'] ?? 'Sin nombre';
                    $quantity = $product['quantity'] ?? $product['cantidad'] ?? 1;
                    
                    if (!isset($allProducts[$sku])) {
                        $allProducts[$sku] = [
                            'sku' => $sku,
                            'nombre' => $name,
                            'cantidad' => 0,
                            'pedidos' => []
                        ];
                    }
                    
                    $allProducts[$sku]['cantidad'] += $quantity;
                    $allProducts[$sku]['pedidos'][] = (string)$order['id'];
                }
            }
        }
        
        if (empty($allProducts) || empty($allOrders)) {
            throw new \Exception("No se encontraron productos u órdenes con los IDs seleccionados.");
        }
        
        // Generar HTML para el documento
        ob_start();
        ?>
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #000; padding: 6px; text-align: left; }
            th { background-color: #f0f0f0; }
            .title { font-size: 20px; font-weight: bold; margin-bottom: 15px; }
            .subtitle { font-size: 16px; font-weight: bold; margin: 20px 0 10px 0; }
            .page-break { page-break-after: always; }
            .total-row { font-weight: bold; background-color: #f0f0f0; }
        </style>

        <div class="title">Lista de Empaque - <?= $marketplace ?> | LODORO</div>
        <div>Fecha de generación: <?= date("Y-m-d H:i:s") ?></div>
        
        <div class="subtitle">Resumen de Productos</div>
        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Producto</th>
                    <th>Cantidad Total</th>
                    <th>N° Órdenes</th>
                </tr>
            </thead>
            <tbody>
            <?php 
            $totalItems = 0;
            foreach ($allProducts as $product): 
                $totalItems += $product['cantidad'];
            ?>
                <tr>
                    <td><?= htmlspecialchars($product['sku']) ?></td>
                    <td><?= htmlspecialchars($product['nombre']) ?></td>
                    <td><?= $product['cantidad'] ?></td>
                    <td><?= count(array_unique($product['pedidos'])) ?></td>
                </tr>
            <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="2">TOTAL</td>
                    <td><?= $totalItems ?></td>
                    <td><?= count($allOrders) ?></td>
                </tr>
            </tbody>
        </table>
        
        <div class="subtitle">Detalle por Orden</div>
        <?php foreach ($allOrders as $index => $order): 
            // Generar código de barras para el ID de la orden
            $barcode = base64_encode($generator->getBarcode((string)$order['id'], $generator::TYPE_CODE_128));
            
            // Consultar productos de la orden
            $itemsTable = str_replace('Orders_', 'Orders_', $order['marketplace']) . "_Items";
            try {
                $productsStatement = $this->dbAdapter->createStatement("SELECT * FROM `$itemsTable` WHERE order_id = ?");
                $productsResult = $productsStatement->execute([(string)$order['id']]);
                
                $orderProducts = [];
                foreach ($productsResult as $product) {
                    $orderProducts[] = $product;
                }
            } catch (\Exception $e) {
                // Si no hay tabla de items, usar datos básicos
                $orderProducts = [];
                $productStrings = explode(',', $order['productos'] ?? '');
                foreach ($productStrings as $i => $productString) {
                    if (!empty(trim($productString))) {
                        $orderProducts[] = [
                            'id' => $i + 1,
                            'sku' => 'SKU-' . str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT),
                            'name' => trim($productString),
                            'price' => ($order['total'] ?? 0) / max(1, count(array_filter($productStrings))),
                            'quantity' => 1
                        ];
                    }
                }
            }
        ?>
        
        <div <?= $index < count($allOrders) - 1 ? 'class="page-break"' : '' ?>>
            <div style="margin-bottom: 10px; border-bottom: 1px solid #000; padding-bottom: 10px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong>Orden #:</strong> <?= htmlspecialchars((string)$order['id']) ?><br>
                        <strong>Cliente:</strong> <?= htmlspecialchars($order['cliente'] ?? 'N/A') ?><br>
                        <strong>Marketplace:</strong> <?= htmlspecialchars($order['marketplace']) ?><br>
                        <strong>Fecha:</strong> <?= $order['fecha_creacion'] ?? date('Y-m-d') ?>
                    </div>
                    <div>
                        <img src="data:image/png;base64,<?= $barcode ?>" style="height: 60px; max-width: 200px;"><br>
                        <div style="text-align: center;"><?= htmlspecialchars((string)$order['id']) ?></div>
                    </div>
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Producto</th>
                        <th>Cantidad</th>
                        <th>Precio</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                <?php 
                $orderTotal = 0;
                foreach ($orderProducts as $product): 
                    $price = $product['price'] ?? $product['precio'] ?? $product['precio_unitario'] ?? 0;
                    $quantity = $product['quantity'] ?? $product['cantidad'] ?? 1;
                    $subtotal = $price * $quantity;
                    $orderTotal += $subtotal;
                    $productName = $product['name'] ?? $product['nombre'] ?? $product['nombre_producto'] ?? 'Sin nombre';
                    $sku = $product['sku'] ?? $product['SKU'] ?? $product['codigo_sku'] ?? 'N/A';
                ?>
                    <tr>
                        <td><?= htmlspecialchars($sku) ?></td>
                        <td><?= htmlspecialchars($productName) ?></td>
                        <td><?= $quantity ?></td>
                        <td>$<?= number_format($price, 0, ',', '.') ?></td>
                        <td>$<?= number_format($subtotal, 0, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="4" align="right">Total:</td>
                        <td>$<?= number_format($orderTotal, 0, ',', '.') ?></td>
                    </tr>
                </tbody>
            </table>
            
            <div style="margin-top: 20px;">
                <strong>Dirección de Entrega:</strong><br>
                <?= htmlspecialchars($order['direccion'] ?? 'No disponible') ?>
            </div>
            
            <div style="margin-top: 20px;">
                <table width="100%" border="0">
                    <tr>
                        <td width="50%" style="border: none;">
                            <div style="border-top: 1px solid #000; margin-top: 50px; padding-top: 5px; text-align: center;">
                                Firma de Preparación
                            </div>
                        </td>
                        <td width="50%" style="border: none;">
                            <div style="border-top: 1px solid #000; margin-top: 50px; padding-top: 5px; text-align: center;">
                                Firma de Verificación
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        <?php
        $html = ob_get_clean();
        
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
     * Generar lista de picking para órdenes
     * @param array $orderIds IDs de las órdenes
     * @param string $table Tabla de órdenes (marketplace)
     * @return Response
     */
    private function generatePickingList(array $orderIds, string $table = null)
    {
        // Determinar la tabla a usar
        $tables = [];
        if ($table && $table !== 'all') {
            $tables[] = $table;
            $marketplace = str_replace('Orders_', '', $table);
        } else {
            $marketplace = "Todos";
            $tables = [
                'Orders_WALLMART',
                'Orders_RIPLEY',
                'Orders_FALABELLA',
                'Orders_MERCADO_LIBRE',
                'Orders_PARIS',
                'Orders_WOOCOMMERCE'
            ];
        }
        
        // Recolectar datos de productos con sus clientes
        $allProducts = [];
        
        foreach ($tables as $currentTable) {
            $tableMarketplace = str_replace('Orders_', '', $currentTable);
            
            // Obtener los datos de las órdenes en esta tabla
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $sql = "SELECT * FROM `$currentTable` WHERE id IN ($placeholders)";
            $statement = $this->dbAdapter->createStatement($sql);
            $result = $statement->execute($orderIds);
            
            // Almacenar información de productos y sus clientes
            foreach ($result as $row) {
                $clienteInfo = [
                    'id' => (string)$row['id'],
                    'nombre' => $row['cliente'] ?? 'N/A',
                    'marketplace' => $tableMarketplace,
                    'suborden' => $row['suborder_number'] ?? (string)$row['id'],
                    'direccion' => $row['direccion'] ?? 'No disponible'
                ];
                
                // Intentar obtener productos de la orden
                $itemsTable = $currentTable . "_Items";
                try {
                    $productsStatement = $this->dbAdapter->createStatement("SELECT * FROM `$itemsTable` WHERE order_id = ?");
                    $productsResult = $productsStatement->execute([(string)$row['id']]);
                    
                    // Agregar productos a la lista global
                    foreach ($productsResult as $product) {
                        $sku = $product['sku'] ?? $product['SKU'] ?? $product['codigo_sku'] ?? 'N/A';
                        $name = $product['name'] ?? $product['nombre'] ?? $product['nombre_producto'] ?? 'Sin nombre';
                        $quantity = $product['quantity'] ?? $product['cantidad'] ?? 1;
                        
                        if (!isset($allProducts[$sku])) {
                            $allProducts[$sku] = [
                                'sku' => $sku,
                                'nombre' => $name,
                                'cantidad' => 0,
                                'clientes' => []
                            ];
                        }
                        
                        $allProducts[$sku]['cantidad'] += $quantity;
                        $allProducts[$sku]['clientes'][] = $clienteInfo;
                    }
                } catch (\Exception $e) {
                    // Si no hay tabla de items, usar datos básicos de la orden
                    $productStrings = explode(',', $row['productos'] ?? '');
                    foreach ($productStrings as $i => $productString) {
                        if (!empty(trim($productString))) {
                            $mockSku = 'SKU-' . str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT);
                            
                            if (!isset($allProducts[$mockSku])) {
                                $allProducts[$mockSku] = [
                                    'sku' => $mockSku,
                                    'nombre' => trim($productString),
                                    'cantidad' => 0,
                                    'clientes' => []
                                ];
                            }
                            
                            $allProducts[$mockSku]['cantidad'] += 1;
                            $allProducts[$mockSku]['clientes'][] = $clienteInfo;
                        }
                    }
                }
            }
        }
        
        if (empty($allProducts)) {
            throw new \Exception("No se encontraron productos para las órdenes seleccionadas.");
        }
        
        // Generar HTML para el documento picking
        ob_start();
?>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    th, td { border: 1px solid #000; padding: 6px; text-align: left; }
    th { background-color: #f0f0f0; }
    .title { font-size: 18px; font-weight: bold; margin-bottom: 10px; }
</style>

<div class="title">PICKING LIST GENERADO: <?= date("Y-m-d H:i:s") ?></div>

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
    <tbody>
    <?php foreach ($allProducts as $product): ?>
        <?php foreach ($product['clientes'] as $cliente): ?>
            <tr>
                <td><?= htmlspecialchars($cliente['nombre']) ?></td>
                <td><?= htmlspecialchars($cliente['suborden']) ?></td>
                <td><?= htmlspecialchars($product['nombre']) ?></td>
                <td><?= htmlspecialchars($product['sku']) ?></td>
                <td>1</td>
                <td><?= htmlspecialchars($cliente['marketplace']) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>

<div><strong>TOTAL PRODUCTOS:</strong> <?= array_sum(array_column($allProducts, 'cantidad')) ?></div>
<?php
$html = ob_get_clean();

        
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
     * Generar boleta/factura para órdenes
     * @param array $orderIds IDs de las órdenes
     * @param string $table Tabla de órdenes (marketplace)
     * @return Response
     */
    private function generateInvoice(array $orderIds, string $table = null)
    {
        // Determinar la tabla a usar
        $tables = [];
        if ($table && $table !== 'all') {
            $tables[] = $table;
        } else {
            $tables = [
                'Orders_WALLMART',
                'Orders_RIPLEY',
                'Orders_FALABELLA',
                'Orders_MERCADO_LIBRE',
                'Orders_PARIS',
                'Orders_WOOCOMMERCE'
            ];
        }
        
        // Recolectar URLs de boletas/facturas
        $invoiceUrls = [];
        
        foreach ($tables as $currentTable) {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $sql = "SELECT id, url_pdf_boleta FROM `$currentTable` WHERE id IN ($placeholders)";
            $statement = $this->dbAdapter->createStatement($sql);
            $result = $statement->execute($orderIds);
            
            foreach ($result as $row) {
                if (!empty($row['url_pdf_boleta']) && filter_var($row['url_pdf_boleta'], FILTER_VALIDATE_URL)) {
                    $invoiceUrls[$row['id']] = $row['url_pdf_boleta'];
                }
            }
        }
        
        if (empty($invoiceUrls)) {
            // Si no se encuentran URLs de boletas, generar boletas básicas
            return $this->generateBasicInvoices($orderIds, $table);
        }
        
        // Crear PDF combinado con FPDI
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        $pagesAdded = false;
        
        foreach ($invoiceUrls as $orderId => $url) {
            try {
                // Descargar el PDF de la boleta
                $tempFile = tempnam(sys_get_temp_dir(), 'invoice_') . '.pdf';
                file_put_contents($tempFile, file_get_contents($url));
                
                // Importar las páginas del PDF
                $pageCount = $pdf->setSourceFile($tempFile);
                for ($i = 1; $i <= $pageCount; $i++) {
                    $tplId = $pdf->importPage($i);
                    $size = $pdf->getTemplateSize($tplId);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($tplId);
                    $pagesAdded = true;
                }
                
                // Eliminar archivo temporal
                unlink($tempFile);
            } catch (\Exception $e) {
                // Registrar error pero continuar con la siguiente boleta
                error_log("Error al procesar boleta para orden {$orderId}: " . $e->getMessage());
                continue;
            }
        }
        
        if (!$pagesAdded) {
            // Si no se pudieron procesar las boletas con URLs, generar boletas básicas
            return $this->generateBasicInvoices($orderIds, $table);
        }
        
        // Generar la salida del PDF
        $pdfContent = $pdf->Output('S');
        
        // Crear respuesta HTTP
        $response = new Response();
        $response->setContent($pdfContent);
        
        // Configurar cabeceras
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/pdf');
        $headers->addHeaderLine('Content-Disposition', 'inline; filename="Boletas_' . date('Y-m-d') . '.pdf"');
        $headers->addHeaderLine('Content-Length', strlen($pdfContent));
        $headers->addHeaderLine('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $headers->addHeaderLine('Pragma', 'public');
        $headers->addHeaderLine('Expires', '0');
        
        return $response;
    }
    
    /**
     * Generar facturas básicas para órdenes (cuando no hay URLs disponibles)
     * @param array $orderIds IDs de las órdenes
     * @param string $table Tabla de órdenes (marketplace)
     * @return Response
     */
    private function generateBasicInvoices(array $orderIds, string $table = null)
    {
        // Determinar la tabla a usar o aplicarlo para todas
        $tables = [];
        if ($table && $table !== 'all') {
            $tables[] = $table;
        } else {
            $tables = [
                'Orders_WALLMART',
                'Orders_RIPLEY',
                'Orders_FALABELLA',
                'Orders_MERCADO_LIBRE',
                'Orders_PARIS',
                'Orders_WOOCOMMERCE'
            ];
        }
        
        // Inicializar el generador de código de barras
        $generator = new BarcodeGeneratorPNG();
        
        $html = '';
        
        foreach ($tables as $currentTable) {
            $tableMarketplace = str_replace('Orders_', '', $currentTable);
            
            // Obtener los datos de las órdenes en esta tabla
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $sql = "SELECT * FROM `$currentTable` WHERE id IN ($placeholders)";
            $statement = $this->dbAdapter->createStatement($sql);
            $result = $statement->execute($orderIds);
            
            $tableOrders = [];
            foreach ($result as $row) {
                $tableOrders[] = $row;
            }
            
            // Procesar cada orden para esta tabla
            foreach ($tableOrders as $index => $order) {
                // Consultar productos de la orden
                $itemsTable = $currentTable . "_Items";
                $productsSql = "SELECT * FROM `$itemsTable` WHERE order_id = ?";
                
                try {
                    $productsStatement = $this->dbAdapter->createStatement($productsSql);
                    $productsResult = $productsStatement->execute([$order['id']]);
                    
                    $products = [];
                    foreach ($productsResult as $product) {
                        $products[] = $product;
                    }
                } catch (\Exception $e) {
                    // Si no hay tabla de items, usar datos básicos
                    $products = [];
                    $productStrings = explode(',', $order['productos'] ?? '');
                    foreach ($productStrings as $i => $productString) {
                        if (!empty(trim($productString))) {
                            $products[] = [
                                'id' => $i + 1,
                                'sku' => 'SKU-' . str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT),
                                'name' => trim($productString),
                                'price' => ($order['total'] ?? 0) / max(1, count(array_filter($productStrings))),
                                'quantity' => 1
                            ];
                        }
                    }
                }
                
                // Generar código de barras para el ID de la orden
                $barcode = base64_encode($generator->getBarcode((string)$order['id'], $generator::TYPE_CODE_128));
                
                // Calcular totales
                $subtotal = 0;
                foreach ($products as $product) {
                    $price = $product['price'] ?? $product['precio'] ?? $product['precio_unitario'] ?? 0;
                    $quantity = $product['quantity'] ?? $product['cantidad'] ?? 1;
                    $subtotal += $price * $quantity;
                }
                
                $impuesto = $order['impuesto'] ?? $order['tax'] ?? ($subtotal * 0.19); // 19% IVA por defecto
                $envio = $order['costo_envio'] ?? $order['shipping_cost'] ?? 0;
                $total = $subtotal + $impuesto + $envio;
                
                // Generar el HTML para esta factura
                $html .= '<div style="page-break-after: always;">';
                $html .= '
                <html>
                <head>
                    <style>
                        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
                        .invoice-container { border: 1px solid #000; padding: 10px; }
                        .invoice-header { border-bottom: 2px solid #000; margin-bottom: 20px; padding-bottom: 10px; }
                        .invoice-title { font-size: 24px; font-weight: bold; }
                        .invoice-subtitle { font-size: 14px; }
                        .invoice-info { display: flex; justify-content: space-between; margin-bottom: 20px; }
                        .invoice-info-section { width: 48%; }
                        .invoice-info-section h3 { margin: 0; font-size: 14px; border-bottom: 1px solid #ccc; padding-bottom: 5px; }
                        .invoice-items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                        .invoice-items th, .invoice-items td { border: 1px solid #000; padding: 8px; }
                        .invoice-items th { background-color: #f0f0f0; }
                        .text-right { text-align: right; }
                        .totals-table { width: 300px; margin-left: auto; border-collapse: collapse; }
                        .totals-table td { padding: 5px; }
                        .totals-table .total-row { font-weight: bold; border-top: 1px solid #000; }
                    </style>
                </head>
                <body>
                    <div class="invoice-container">
                        <div class="invoice-header">
                            <table width="100%">
                                <tr>
                                    <td width="70%">
                                        <div class="invoice-title">FACTURA</div>
                                        <div class="invoice-subtitle">LODORO Analytics</div>
                                        <div>RUT: 76.123.456-7</div>
                                        <div>Dirección: Av. Principal 123, Santiago</div>
                                    </td>
                                    <td width="30%" align="right">
                                        <div>N°: '.$order['id'].'</div>
                                        <div>Fecha: '.date('d/m/Y').'</div>
                                        <img src="data:image/png;base64,'.$barcode.'" style="height: 50px; max-width: 200px;">
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <div class="invoice-info">
                            <div class="invoice-info-section">
                                <h3>Cliente</h3>
                                <div>Nombre: '.$order['cliente'].'</div>
                                <div>Teléfono: '.$order['telefono'].'</div>
                                <div>Dirección: '.$order['direccion'].'</div>
                            </div>
                            <div class="invoice-info-section">
                                <h3>Detalles de Envío</h3>
                                <div>Marketplace: '.$tableMarketplace.'</div>
                                <div>Transportista: '.($order['transportista'] ?? 'No asignado').'</div>
                                <div>N° Seguimiento: '.($order['num_seguimiento'] ?? 'No asignado').'</div>
                            </div>
                        </div>
                        
                        <table class="invoice-items">
                            <thead>
                                <tr>
                                    <th>Cantidad</th>
                                    <th>SKU</th>
                                    <th>Descripción</th>
                                    <th class="text-right">Precio Unit.</th>
                                    <th class="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>';
                            
                foreach ($products as $product) {
                    $price = $product['price'] ?? $product['precio'] ?? $product['precio_unitario'] ?? 0;
                    $quantity = $product['quantity'] ?? $product['cantidad'] ?? 1;
                    $productName = $product['name'] ?? $product['nombre'] ?? $product['nombre_producto'] ?? 'Sin nombre';
                    $sku = $product['sku'] ?? $product['SKU'] ?? $product['codigo_sku'] ?? 'N/A';
                    $itemTotal = $price * $quantity;
                    
                    $html .= '
                                <tr>
                                    <td>'.$quantity.'</td>
                                    <td>'.$sku.'</td>
                                    <td>'.$productName.'</td>
                                    <td class="text-right">'.number_format($price, 0, ',', '.').'</td>
                                    <td class="text-right">'.number_format($itemTotal, 0, ',', '.').'</td>
                                </tr>';
                }
                
                $html .= '
                            </tbody>
                        </table>
                        
                        <table class="totals-table">
                            <tr>
                                <td>Subtotal:</td>
                                <td class="text-right">'.number_format($subtotal, 0, ',', '.').' CLP</td>
                            </tr>
                            <tr>
                                <td>IVA (19%):</td>
                                <td class="text-right">'.number_format($impuesto, 0, ',', '.').' CLP</td>
                            </tr>
                            <tr>
                                <td>Costo de Envío:</td>
                                <td class="text-right">'.number_format($envio, 0, ',', '.').' CLP</td>
                            </tr>
                            <tr class="total-row">
                                <td>Total:</td>
                                <td class="text-right">'.number_format($total, 0, ',', '.').' CLP</td>
                            </tr>
                        </table>
                        
                        <div style="margin-top: 50px; text-align: center; font-size: 10px;">
                            <p>Este documento es una representación gráfica de factura electrónica</p>
                        </div>
                    </div>
                </body>
                </html>
                ';
                $html .= '</div>';
            }
        }
        
        if (empty($html)) {
            throw new \Exception("No se encontraron órdenes con los IDs seleccionados.");
        }
        
        // Crear PDF con DOMPDF
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        $pdfContent = $dompdf->output();
        
        // Crear respuesta HTTP
        $response = new Response();
        $response->setContent($pdfContent);
        
        // Configurar cabeceras
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/pdf');
        $headers->addHeaderLine('Content-Disposition', 'inline; filename="Facturas_' . date('Y-m-d') . '.pdf"');
        $headers->addHeaderLine('Content-Length', strlen($pdfContent));
        $headers->addHeaderLine('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $headers->addHeaderLine('Pragma', 'public');
        $headers->addHeaderLine('Expires', '0');
        
        return $response;
    }
    
    /**
     * Acción para seleccionar órdenes y generar documentos
     */
    public function selectOrdersAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
        
        // Obtener parámetros (marketplace, estado, etc.)
        $marketplace = $this->params()->fromQuery('marketplace', 'all');
        $status = $this->params()->fromQuery('status', '');
        $limit = (int) $this->params()->fromQuery('limit', 50);
        
        // Determinar tabla a consultar
        $table = $marketplace !== 'all' ? 'Orders_' . $marketplace : null;
        
        // Obtener órdenes para seleccionar
        $orders = [];
        
        if ($table) {
            // Consultar órdenes de un marketplace específico
            $sql = "SELECT id, cliente, fecha_creacion, estado FROM `$table`";
            if (!empty($status)) {
                $sql .= " WHERE estado = ?";
                $params = [$status];
            } else {
                $params = [];
            }
            $sql .= " ORDER BY fecha_creacion DESC LIMIT $limit";
            
            $statement = $this->dbAdapter->createStatement($sql);
            $result = $statement->execute($params);
            
            foreach ($result as $row) {
                $row['marketplace'] = str_replace('Orders_', '', $table);
                $orders[] = $row;
            }
        } else {
            // Consultar órdenes de todos los marketplaces
            $tables = [
                'Orders_WALLMART',
                'Orders_RIPLEY',
                'Orders_FALABELLA',
                'Orders_MERCADO_LIBRE',
                'Orders_PARIS',
                'Orders_WOOCOMMERCE'
            ];
            
            foreach ($tables as $currentTable) {
                $currentMarketplace = str_replace('Orders_', '', $currentTable);
                
                $sql = "SELECT id, cliente, fecha_creacion, estado FROM `$currentTable`";
                if (!empty($status)) {
                    $sql .= " WHERE estado = ?";
                    $params = [$status];
                } else {
                    $params = [];
                }
                $sql .= " ORDER BY fecha_creacion DESC LIMIT " . (int)($limit / count($tables));
                
                try {
                    $statement = $this->dbAdapter->createStatement($sql);
                    $result = $statement->execute($params);
                    
                    foreach ($result as $row) {
                        $row['marketplace'] = $currentMarketplace;
                        $orders[] = $row;
                    }
                } catch (\Exception $e) {
                    // Ignorar tablas que no existen
                    continue;
                }
            }
        }
        
        // Obtener lista de marketplaces para el selector
        $marketplaces = [
            'all' => 'Todos los Marketplaces',
            'WALLMART' => 'Walmart',
            'RIPLEY' => 'Ripley',
            'FALABELLA' => 'Falabella',
            'MERCADO_LIBRE' => 'Mercado Libre',
            'PARIS' => 'Paris',
            'WOOCOMMERCE' => 'WooCommerce'
        ];
        
        // Obtener lista de estados para el selector
        $estados = [
            '' => 'Todos los estados',
            'Nueva' => 'Nueva',
            'En Proceso' => 'En Proceso',
            'Enviada' => 'Enviada',
            'Entregada' => 'Entregada',
            'Pendiente de Pago' => 'Pendiente de Pago',
            'Cancelada' => 'Cancelada',
            'Devuelta' => 'Devuelta'
        ];
        
        // Devolver la vista
        return new ViewModel([
            'orders' => $orders,
            'marketplace' => $marketplace,
            'status' => $status,
            'marketplaces' => $marketplaces,
            'estados' => $estados
        ]);
    }
}