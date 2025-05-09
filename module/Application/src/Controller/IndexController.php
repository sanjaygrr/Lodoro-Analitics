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
 * Upload liquidation action
 *
 * @return JsonModel
 */
public function uploadLiquidationAction()
{
    // Set default response
    $response = [
        'success' => false,
        'message' => 'Error al procesar la solicitud',
        'processedRows' => 0,
    ];

    // Check if request is POST
    if (!$this->getRequest()->isPost()) {
        $response['message'] = 'Método no permitido';
        return new \Laminas\View\Model\JsonModel($response);
    }

    try {
        // Get uploaded file
        $files = $this->getRequest()->getFiles()->toArray();
        
        if (empty($files) || !isset($files['liquidationFile']) || $files['liquidationFile']['error'] !== UPLOAD_ERR_OK) {
            $response['message'] = 'No se ha seleccionado un archivo válido';
            return new \Laminas\View\Model\JsonModel($response);
        }

        $file = $files['liquidationFile'];
        $filePath = $file['tmp_name'];
        
        // Check if process in background
        $processInBackground = $this->params()->fromPost('processInBackground', false);
        
        if ($processInBackground) {
            // Generate a job ID
            $jobId = uniqid('job_');
            
            // Save the file to a temporary location that can be accessed by the background process
            $tempFilePath = sys_get_temp_dir() . '/' . $jobId . '_' . basename($file['name']);
            move_uploaded_file($filePath, $tempFilePath);
            
            // Start a background process
            $this->startBackgroundProcess($tempFilePath, $jobId);
            
            $response = [
                'success' => true,
                'message' => 'El archivo se está procesando en segundo plano',
                'jobId' => $jobId,
            ];
            
            return new \Laminas\View\Model\JsonModel($response);
        } else {
            // Process immediately
            $result = $this->processLiquidationFile($filePath);
            
            $response = [
                'success' => $result['success'],
                'message' => $result['message'],
                'processedRows' => $result['processedRows'],
            ];
            
            return new \Laminas\View\Model\JsonModel($response);
        }
        
    } catch (\Exception $e) {
        $response['message'] = 'Error: ' . $e->getMessage();
        return new \Laminas\View\Model\JsonModel($response);
    }
}

/**
 * Start a background process to handle the Excel file
 *
 * @param string $filePath Path to the uploaded file
 * @param string $jobId Unique job identifier
 * @return void
 */
private function startBackgroundProcess($filePath, $jobId)
{
    // Create a log file for this job
    $logFile = sys_get_temp_dir() . '/' . $jobId . '_log.txt';
    
    // Escape path for shell
    $escapedFilePath = escapeshellarg($filePath);
    $escapedLogFile = escapeshellarg($logFile);
    $escapedJobId = escapeshellarg($jobId);
    
    // Command to run the processor in background
    $root = realpath(__DIR__ . '/../../../..');
    $cmd = sprintf(
        'php %s/public/process-liquidation.php %s %s %s > /dev/null 2>&1 &',
        $root,
        $escapedFilePath,
        $escapedLogFile,
        $escapedJobId
    );
    
    // Execute command (runs in background)
    exec($cmd);
}

/**
 * Process liquidation file (Excel)
 *
 * @param string $filePath Path to the uploaded file
 * @return array Processing result with success status, message and processed rows count
 */
private function processLiquidationFile($filePath)
{
    $result = [
        'success' => false,
        'message' => 'Error al procesar el archivo',
        'processedRows' => 0,
    ];
    
    try {
        // Load the spreadsheet
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Get the highest row and column
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        
        // If there are fewer than 2 rows (header + at least one data row), return error
        if ($highestRow < 2) {
            $result['message'] = 'El archivo no contiene datos suficientes';
            return $result;
        }
        
        // Initialize column mapping (try to find the columns we need)
        $columnMap = $this->findColumnMapping($worksheet, $highestColumnIndex);
        
        if (empty($columnMap['numero_suborden']) || 
            empty($columnMap['numero_liquidacion']) || 
            empty($columnMap['monto_liquidacion'])) {
            $result['message'] = 'No se encontraron las columnas necesarias en el archivo Excel';
            return $result;
        }
        
        // Process data rows
        $processedRows = 0;
        $updateCount = 0;
        $errorRows = [];
        
        for ($row = 2; $row <= $highestRow; $row++) {
            $numeroSuborden = trim($worksheet->getCellByColumnAndRow($columnMap['numero_suborden'], $row)->getValue());
            $numeroLiquidacion = trim($worksheet->getCellByColumnAndRow($columnMap['numero_liquidacion'], $row)->getValue());
            $montoLiquidacion = $worksheet->getCellByColumnAndRow($columnMap['monto_liquidacion'], $row)->getValue();
            
            // Skip empty rows
            if (empty($numeroSuborden) || empty($numeroLiquidacion)) {
                continue;
            }
            
            // Convert monto_liquidacion to a proper decimal
            $montoLiquidacion = floatval(str_replace(',', '.', str_replace('.', '', $montoLiquidacion)));
            
            // Update the database
            $updated = $this->updateLiquidationData($numeroSuborden, $numeroLiquidacion, $montoLiquidacion);
            
            if ($updated) {
                $updateCount++;
            } else {
                $errorRows[] = $row;
            }
            
            $processedRows++;
        }
        
        if ($processedRows > 0) {
            $result['success'] = true;
            $result['message'] = "Se procesaron {$processedRows} filas. Se actualizaron {$updateCount} registros.";
            if (!empty($errorRows)) {
                $result['message'] .= " No se pudieron actualizar " . count($errorRows) . " filas.";
            }
            $result['processedRows'] = $processedRows;
        } else {
            $result['message'] = 'No se procesaron filas. Verifique el formato del archivo.';
        }
        
        return $result;
        
    } catch (\Exception $e) {
        $result['message'] = 'Error al procesar el archivo: ' . $e->getMessage();
        return $result;
    }
}

/**
 * Find column mapping in the Excel file
 * 
 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet
 * @param int $highestColumnIndex
 * @return array Column mapping with column indices
 */
private function findColumnMapping($worksheet, $highestColumnIndex)
{
    $columnMap = [
        'numero_suborden' => null,
        'numero_liquidacion' => null,
        'monto_liquidacion' => null,
    ];
    
    // List of possible header names for each column
    $headerMapping = [
        'numero_suborden' => ['numero_suborden', 'numero de suborden', 'suborden', 'orden', 'id', 'codigo', 'order'],
        'numero_liquidacion' => ['numero_liquidacion', 'numero de liquidacion', 'liquidacion', 'num liquidacion', 'n° liquidacion', 'n liquidacion'],
        'monto_liquidacion' => ['monto_liquidacion', 'monto de liquidacion', 'valor liquidacion', 'importe', 'total liquidacion', 'monto'],
    ];
    
    // Read header row
    for ($col = 1; $col <= $highestColumnIndex; $col++) {
        $headerValue = trim(strtolower($worksheet->getCellByColumnAndRow($col, 1)->getValue()));
        
        // Check against possible header names
        foreach ($headerMapping as $column => $possibleHeaders) {
            if (in_array($headerValue, $possibleHeaders) || $this->containsKeyword($headerValue, $possibleHeaders)) {
                $columnMap[$column] = $col;
                break;
            }
        }
    }
    
    return $columnMap;
}

/**
 * Check if a header value contains any of the keywords
 * 
 * @param string $headerValue
 * @param array $keywords
 * @return bool
 */
private function containsKeyword($headerValue, $keywords)
{
    foreach ($keywords as $keyword) {
        if (strpos($headerValue, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Update liquidation data in the database
 * 
 * @param string $numeroSuborden
 * @param string $numeroLiquidacion
 * @param float $montoLiquidacion
 * @return bool Success or failure
 */
private function updateLiquidationData($numeroSuborden, $numeroLiquidacion, $montoLiquidacion)
{
    try {
        $sql = "UPDATE MKP_PARIS SET 
                numero_liquidacion = ?, 
                monto_liquidacion = ? 
                WHERE numero_suborden = ?";
        
        $statement = $this->dbAdapter->query($sql);
        $result = $statement->execute([
            $numeroLiquidacion,
            $montoLiquidacion,
            $numeroSuborden
        ]);
        
        return ($result->getAffectedRows() > 0);
    } catch (\Exception $e) {
        // Log the error but don't throw it up
        error_log("Error updating liquidation data: " . $e->getMessage());
        return false;
    }
}

/**
 * Check liquidation job status
 *
 * @return JsonModel
 */
public function checkStatusAction()
{
    $jobId = $this->params()->fromRoute('jobId', null);
    
    if (!$jobId) {
        return new \Laminas\View\Model\JsonModel([
            'success' => false,
            'message' => 'ID de trabajo no proporcionado'
        ]);
    }
    
    $logFile = sys_get_temp_dir() . '/' . $jobId . '_log.txt';
    
    if (!file_exists($logFile)) {
        return new \Laminas\View\Model\JsonModel([
            'success' => false,
            'message' => 'El trabajo no existe o aún no ha comenzado',
            'status' => 'pending'
        ]);
    }
    
    $logContent = file_get_contents($logFile);
    
    // Check if the job is completed
    if (strpos($logContent, 'COMPLETED') !== false) {
        // Extract completion data
        preg_match('/COMPLETED: (.*?)(\r\n|\n|$)/', $logContent, $completionMatch);
        $completionData = isset($completionMatch[1]) ? json_decode($completionMatch[1], true) : [];
        
        return new \Laminas\View\Model\JsonModel([
            'success' => true,
            'status' => 'completed',
            'message' => $completionData['message'] ?? 'Proceso completado',
            'processedRows' => $completionData['processedRows'] ?? 0,
            'timestamp' => $completionData['timestamp'] ?? time()
        ]);
    } 
    // Check if the job failed
    else if (strpos($logContent, 'FAILED') !== false) {
        preg_match('/FAILED: (.*?)(\r\n|\n|$)/', $logContent, $failedMatch);
        
        return new \Laminas\View\Model\JsonModel([
            'success' => false,
            'status' => 'failed',
            'message' => isset($failedMatch[1]) ? 'Error: ' . $failedMatch[1] : 'El proceso falló',
            'timestamp' => time()
        ]);
    } 
    // Job is still running
    else {
        // Extract progress if available
        preg_match('/PROGRESS: (\d+)\/(\d+)/', $logContent, $progressMatch);
        $current = isset($progressMatch[1]) ? (int)$progressMatch[1] : 0;
        $total = isset($progressMatch[2]) ? (int)$progressMatch[2] : 1;
        $percentage = $total > 0 ? round(($current / $total) * 100) : 0;
        
        return new \Laminas\View\Model\JsonModel([
            'success' => true,
            'status' => 'running',
            'message' => 'Procesando liquidaciones...',
            'progress' => $percentage,
            'current' => $current,
            'total' => $total
        ]);
    }
}
    /**
 * Liquidation status page
 *
 * @return ViewModel
 */
public function liquidationStatusAction()
{
    $jobId = $this->params()->fromRoute('jobId', null);
    
    if (!$jobId) {
        return $this->redirect()->toRoute('application', ['action' => 'detail', 'table' => 'MKP_PARIS']);
    }
    
    return new ViewModel([
        'jobId' => $jobId
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
    
    // Inicializar variables KPI
    $ventaBrutaMensual = 0;
    $impuestoBrutoMensual = 0;  
    $totalTransaccionesMes = 0;
    $valorCancelado = 0;
    $transaccionesCanceladas = 0;
    $totalVentas = 0;
    $totalRegistros = $total;
    
    // Obtener mensajes de la query string
    $message = $this->params()->fromQuery('message');
    $messageType = $this->params()->fromQuery('type', 'info');
    $processedRows = $this->params()->fromQuery('processed');
    
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
        'jsonTopProductos'        => $jsonTopProductos,
        'ventaBrutaMensual'       => $ventaBrutaMensual,
        'impuestoBrutoMensual'    => $impuestoBrutoMensual,
        'totalTransaccionesMes'   => $totalTransaccionesMes,
        'valorCancelado'         => $valorCancelado,
        'transaccionesCanceladas' => $transaccionesCanceladas,
        'totalVentas'            => $totalVentas,
        'totalRegistros'         => $totalRegistros,
        // Agregar mensajes
        'message'                => $message,
        'messageType'            => $messageType,
        'processedRows'          => $processedRows
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
 * Insert batch of records
 *
 * @param \Laminas\Db\Adapter\AdapterInterface $dbAdapter
 * @param array $batch Array of placeholder strings
 * @param array $params Array of parameters
 * @param array $fields Array of field names
 * @return void
 */

/**
 * Process the uploaded file directly
 *
 * @param int $jobId
 * @param string $filePath
 * @return void
 */





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


    public function ordersDetailAction()
{
    // Verificar autenticación
    $redirect = $this->checkAuth();
    if ($redirect !== null) {
        return $redirect;
    }
    
    // Obtain marketplace from route
    $table = $this->params()->fromRoute('table', null);
    if (!$table) {
        return $this->redirect()->toRoute('application', ['action' => 'orders']);
    }
    
    // Validate table name
    if (strpos($table, 'Orders_') !== 0) {
        return $this->redirect()->toRoute('application', ['action' => 'orders']);
    }
    
    // Get pagination parameters and filters
    $page = (int) $this->params()->fromQuery('page', 1);
    $limit = (int) $this->params()->fromQuery('limit', 30);
    
    // Collect all available filters
    $filters = [
        'search' => $this->params()->fromQuery('search', ''),
        'status' => $this->params()->fromQuery('status', ''),
        'printed' => $this->params()->fromQuery('printed', ''),
        'transportista' => $this->params()->fromQuery('transportista', ''),
        'startDate' => $this->params()->fromQuery('startDate', ''),
        'endDate' => $this->params()->fromQuery('endDate', '')
    ];
    
    try {
        // Calculate counters for dashboard using specific SQL query with your column structure
        $countersSql = "SELECT
            SUM(CASE WHEN printed = 0 AND procesado = 0 THEN 1 ELSE 0 END) AS sin_imprimir,
            SUM(CASE WHEN printed = 1 AND procesado = 0 THEN 1 ELSE 0 END) AS impresos_no_procesados,
            SUM(CASE WHEN printed = 1 AND procesado = 1 THEN 1 ELSE 0 END) AS procesados
        FROM `$table`";
        
        $countersStatement = $this->dbAdapter->createStatement($countersSql);
        $countersResult = $countersStatement->execute();
        
        // Get results (handle case of no results)
        $counters = $countersResult->current();
        if (!$counters) {
            $counters = [
                'sin_imprimir' => 0,
                'impresos_no_procesados' => 0,
                'procesados' => 0
            ];
        }
        
        // Ensure that values are numeric
        $counters['sin_imprimir'] = (int)($counters['sin_imprimir'] ?? 0);
        $counters['impresos_no_procesados'] = (int)($counters['impresos_no_procesados'] ?? 0);
        $counters['procesados'] = (int)($counters['procesados'] ?? 0);
        
        // Get paginated orders with applied filters
        $paginatedData = $this->getOrdersWithPagination($table, $page, $limit, $filters);
        
        // Format results for view
        $orders = [];
        foreach ($paginatedData['orders'] as $row) {
            // Add marketplace to each order to facilitate actions
            $row['marketplace'] = str_replace('Orders_', '', $table);
            
            // Ensure that 'printed' is a consistent boolean or numeric value
            if (isset($row['printed'])) {
                $row['printed'] = (int)$row['printed'];
            }
            
            // Process JSON product data if it exists
            if (isset($row['productos']) && !empty($row['productos'])) {
                // Check if $row['productos'] is already an array
                if (is_array($row['productos'])) {
                    // Already an array, no need to decode
                    $productos = $row['productos'];
                } else {
                    // Try to decode if it's a string
                    try {
                        $productos = json_decode($row['productos'], true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($productos)) {
                            $row['productos'] = $productos;
                        }
                    } catch (\Exception $e) {
                        // Keep products as text if not valid JSON
                    }
                }
            }
            
            $orders[] = $row;
        }
        
        // Create specific variables for the view from the counters
        $sinImprimir = $counters['sin_imprimir'];
        $impresosNoProcesados = $counters['impresos_no_procesados'];
        $procesados = $counters['procesados'];
        
        // Return the view with the data
        return new ViewModel([
            'table' => $table,
            'orders' => $orders,
            'page' => $paginatedData['page'],
            'limit' => $paginatedData['limit'],
            'totalPages' => $paginatedData['totalPages'],
            'total' => $paginatedData['total'],
            'search' => $filters['search'],
            'statusFilter' => $filters['status'],
            'printedFilter' => $filters['printed'],
            'transportistaFilter' => $filters['transportista'],
            'startDate' => $filters['startDate'],
            'endDate' => $filters['endDate'],
            // Add specific variables for dashboard counters
            'sinImprimir' => $sinImprimir,
            'impresosNoProcesados' => $impresosNoProcesados,
            'procesados' => $procesados
        ]);
    } catch (\Exception $e) {
        // Error handling - log and display generic message
        error_log('Error en ordersDetailAction: ' . $e->getMessage());
        
        // Create flash message with error
        $this->flashMessenger()->addErrorMessage('Error al cargar los datos: ' . $e->getMessage());
        
        // Return view with empty data
        return new ViewModel([
            'table' => $table,
            'orders' => [],
            'page' => 1,
            'limit' => $limit,
            'totalPages' => 0,
            'total' => 0,
            'search' => $filters['search'],
            'statusFilter' => $filters['status'],
            'printedFilter' => $filters['printed'],
            'transportistaFilter' => $filters['transportista'],
            'startDate' => $filters['startDate'],
            'endDate' => $filters['endDate'],
            'sinImprimir' => 0,
            'impresosNoProcesados' => 0,
            'procesados' => 0,
            'error' => true
        ]);
    }
}


    private function getOrdersWithPagination($table, $page, $limit, $filters)
    {
        // Construir la consulta base
        $sql = "SELECT * FROM `$table` WHERE 1=1";
        $countSql = "SELECT COUNT(*) as total FROM `$table` WHERE 1=1";
        
        $params = [];
        
        // Aplicar filtro de búsqueda
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $sql .= " AND (suborder_number LIKE ? OR cliente LIKE ? OR productos LIKE ?)";
            $countSql .= " AND (suborder_number LIKE ? OR cliente LIKE ? OR productos LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
        }
        
        // Aplicar filtro de estado
        if (!empty($filters['status'])) {
            $sql .= " AND estado = ?";
            $countSql .= " AND estado = ?";
            $params[] = $filters['status'];
        }
        
        // Aplicar filtro de impresión (NUEVO)
        if ($filters['printed'] !== '') {
            $sql .= " AND printed = ?";
            $countSql .= " AND printed = ?";
            $params[] = $filters['printed'];
        }
        
        // Aplicar filtro de transportista
        if (!empty($filters['transportista'])) {
            $sql .= " AND transportista = ?";
            $countSql .= " AND transportista = ?";
            $params[] = $filters['transportista'];
        }
        
        // Aplicar filtros de fecha
        if (!empty($filters['startDate'])) {
            $sql .= " AND fecha_creacion >= ?";
            $countSql .= " AND fecha_creacion >= ?";
            $params[] = $filters['startDate'] . ' 00:00:00';
        }
        
        if (!empty($filters['endDate'])) {
            $sql .= " AND fecha_creacion <= ?";
            $countSql .= " AND fecha_creacion <= ?";
            $params[] = $filters['endDate'] . ' 23:59:59';
        }
        
        // Ordenar por fecha de creación descendente
        $sql .= " ORDER BY fecha_creacion DESC";
        
        // Obtener total de registros
        $countStatement = $this->dbAdapter->createStatement($countSql);
        $countResult = $countStatement->execute($params);
        $countData = $countResult->current();
        $total = $countData['total'];
        
        // Calcular paginación
        $totalPages = ceil($total / $limit);
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $limit;
        
        // Aplicar paginación
        $sql .= " LIMIT $limit OFFSET $offset";
        
        // Ejecutar consulta principal
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute($params);
        
        // Convertir a array
        $orders = [];
        foreach ($result as $row) {
            // Deserializar campo de productos si está serializado
            if (isset($row['productos']) && !is_array($row['productos'])) {
                try {
                    $productos = json_decode($row['productos'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $row['productos'] = $productos;
                    }
                } catch (\Exception $e) {
                    // Si hay error, dejar como está
                }
            }
            $orders[] = $row;
        }
        
        return [
            'orders' => $orders,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $totalPages,
            'total' => $total
        ];
    }
    

    public function testRoutesAction()
{
    $router = $this->getEvent()->getRouter();
    $routes = $router->getRoutes();
    
    echo "<pre>";
    foreach ($routes as $name => $route) {
        echo "Ruta: $name\n";
        if ($route instanceof \Laminas\Router\Http\Segment) {
            echo "  Tipo: Segment\n";
            echo "  Ruta: " . $route->getOptions()['route'] . "\n";
        }
        echo "\n";
    }
    echo "</pre>";
    
    die();
}


public function orderDetailAction()
{
    $orderId = $this->params()->fromRoute('id', null);
    $table = $this->params()->fromRoute('table', null);

    if (!$orderId || !$table) {
        return $this->redirect()->toRoute('application', ['action' => 'orders-detail']);
    }

    if (strpos($table, 'Orders_') !== 0) {
        return $this->redirect()->toRoute('application', ['action' => 'orders-detail']);
    }

    try {
        // Obtener datos básicos de la orden de la tabla Orders_
        $orderSql = "SELECT * FROM `$table` WHERE suborder_number = ?";
        $orderStatement = $this->dbAdapter->createStatement($orderSql);
        $orderResult = $orderStatement->execute([$orderId]);
        $order = $orderResult->current();

        if (!$order) {
            return $this->redirect()->toRoute('application', [
                'action' => 'orders-detail', 
                'table' => $table
            ]);
        }

        // Obtener detalles adicionales de la tabla MKP_PARIS
        $marketplace = str_replace('Orders_', '', $table);
        $mkpTable = 'MKP_' . $marketplace;
        
        $mkpSql = "SELECT * FROM `$mkpTable` WHERE numero_suborden = ?";
        $mkpStatement = $this->dbAdapter->createStatement($mkpSql);
        $mkpResult = $mkpStatement->execute([$orderId]);
        $mkpData = $mkpResult->current();

        // Combinar datos
        $orderDetail = array_merge((array)$order, (array)$mkpData);

        // Procesar productos
        $products = $this->processOrderProducts($order, $mkpData);

        // Cálculos financieros
        $subtotal = 0;
        foreach ($products as $product) {
            $subtotal += $product['subtotal'];
        }
        
        $impuesto = $orderDetail['monto_impuesto_boleta'] ?? 0;
        $total = $orderDetail['monto_total_boleta'] ?? 0;
        $envio = $orderDetail['costo'] ?? 0;

        // Información de entrega
        $deliveryInfo = [
            'transportista' => $orderDetail['transportista'] ?? 'Sin asignar',
            'numero_seguimiento' => $orderDetail['num_seguimiento'] ?? '',
            'fecha_entrega' => $orderDetail['fecha_entrega'] ?? '',
        ];

        // Información de cliente
        $clientInfo = [
            'nombre' => $orderDetail['nombre_cliente'] ?? $orderDetail['cliente'] ?? 'N/A',
            'rut' => $orderDetail['rut_cliente'] ?? 'N/A',
            'telefono' => $orderDetail['telefono_cliente'] ?? $orderDetail['telefono'] ?? 'N/A',
            'direccion' => $orderDetail['direccion'] ?? 'N/A'
        ];

        return new ViewModel([
            'order' => $orderDetail,
            'products' => $products,
            'subtotal' => $subtotal,
            'impuesto' => $impuesto,
            'envio' => $envio,
            'total' => $total,
            'deliveryInfo' => $deliveryInfo,
            'clientInfo' => $clientInfo,
            'table' => $table,
            'marketplace' => $marketplace
        ]);

    } catch (\Exception $e) {
        // Log del error
        error_log("Error en orderDetailAction: " . $e->getMessage());
        return $this->redirect()->toRoute('application', [
            'action' => 'orders', 
            'error' => 'database'
        ]);
    }
}

private function processOrderProducts($order, $mkpData)
{
    $products = [];
    
    // Obtener SKU directamente de la tabla Orders
    $orderSku = $order['sku'] ?? null;
    
    // Si tenemos un SKU y un producto
    if ($orderSku && !empty($order['productos'])) {
        // Verificar si productos ya es un array
        if (is_array($order['productos'])) {
            // Ya es un array, usarlo directamente
            return $order['productos'];
        }
        
        // No dividir el SKU, mantenerlo como está para mostrarlo en una sola línea
        $productName = $order['productos'];
        
        $products[] = [
            'id' => 1,
            'nombre' => $productName,
            'sku' => $orderSku, // Mantener el SKU original con la coma
            'cantidad' => 1,
            'precio_unitario' => $order['total'] ?? 0,
            'subtotal' => $order['total'] ?? 0,
            'procesado' => $order['procesado'] ?? 0
        ];
        
        // Si ya hemos procesado el producto, retornamos
        if (!empty($products)) {
            return $products;
        }
    }
    
    // Código de respaldo por si lo anterior no funciona
    // Primero verificar si productos está en formato JSON
    if (!empty($order['productos'])) {
        // Verificar si ya es un array
        if (is_array($order['productos'])) {
            // Ya es un array, usarlo directamente
            $jsonProducts = $order['productos'];
        } else if (is_string($order['productos'])) {
            // Intentar decodificar JSON solo si es una cadena
            $jsonProducts = json_decode($order['productos'], true);
        } else {
            // No es ni string ni array, inicializar como array vacío
            $jsonProducts = [];
        }
        
        if (is_array($jsonProducts) && !empty($jsonProducts)) {
            // Ya tenemos productos en formato array
            $products = $jsonProducts;
        } else if (is_string($order['productos'])) {
            // Si no es JSON válido, procesar como cadena de productos
            $productStrings = explode(',', $order['productos']);
            foreach ($productStrings as $i => $productString) {
                $productString = trim($productString);
                if (!empty($productString)) {
                    $products[] = [
                        'id' => $i + 1,
                        'nombre' => $productString,
                        'sku' => $orderSku ?? 'SKU-' . str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT),
                        'cantidad' => 1,
                        'precio_unitario' => $order['precio_base'] ?? $order['total'] ?? 0,
                        'subtotal' => $order['precio_base'] ?? $order['total'] ?? 0,
                        'procesado' => $order['procesado'] ?? 0
                    ];
                }
            }
        }
    }
    
    // Si no hay productos o el array está vacío, usar datos de MKP
    if (empty($products) && $mkpData) {
        // Verificar si mkpData['productos'] es un array
        if (isset($mkpData['productos']) && is_array($mkpData['productos'])) {
            $products = $mkpData['productos'];
        } else {
            $products[] = [
                'id' => 1,
                'nombre' => $mkpData['productos'] ?? $order['productos'] ?? 'Sin nombre',
                'sku' => $mkpData['sku'] ?? $orderSku ?? 'N/A',
                'cantidad' => 1,
                'precio_unitario' => $mkpData['precio_base'] ?? $order['total'] ?? 0,
                'subtotal' => $mkpData['precio_base'] ?? $order['total'] ?? 0,
                'procesado' => $order['procesado'] ?? 0
            ];
        }
    }
    
    // Si después de todo no hay productos, crear uno genérico
    if (empty($products)) {
        $products[] = [
            'id' => 1,
            'nombre' => $order['productos'] ?? 'Producto sin nombre',
            'sku' => $orderSku ?? 'N/A',
            'cantidad' => 1,
            'precio_unitario' => $order['total'] ?? 0,
            'subtotal' => $order['total'] ?? 0,
            'procesado' => $order['procesado'] ?? 0
        ];
    }
    
    return $products;
}


public function updateOrderProcessedStatusAction()
{
    $request = $this->getRequest();
    $response = $this->getResponse();
    $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
    
    if ($request->isPost()) {
        $data = json_decode($request->getContent(), true);
        $orderId = $data['orderId'] ?? null;
        $table = $data['table'] ?? null;
        $allProcessed = $data['allProcessed'] ?? false;
        
        if (!$orderId || !$table) {
            $response->setContent(json_encode([
                'success' => false,
                'message' => 'Datos incompletos'
            ]));
            return $response;
        }
        
        try {
            // Actualizar el campo procesado de la orden
            $sql = "UPDATE `$table` SET procesado = 1 WHERE suborder_number = ?";
            $statement = $this->dbAdapter->createStatement($sql);
            $result = $statement->execute([$orderId]);
            
            if ($result->getAffectedRows() > 0) {
                $response->setContent(json_encode([
                    'success' => true,
                    'message' => 'Orden actualizada correctamente'
                ]));
            } else {
                $response->setContent(json_encode([
                    'success' => false,
                    'message' => 'No se pudo actualizar la orden'
                ]));
            }
        } catch (\Exception $e) {
            error_log("Error en updateOrderProcessedStatusAction: " . $e->getMessage());
            $response->setContent(json_encode([
                'success' => false,
                'message' => 'Error al actualizar orden: ' . $e->getMessage()
            ]));
        }
        
        return $response;
    }
    
    $response->setContent(json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ]));
    return $response;
}

/**
 * Acción para buscar un producto por código EAN mejorada con mejor manejo de coincidencias
 * REEMPLAZAR la función searchEanAction() existente con esta versión
 */
public function searchEanAction()
{
    // Configurar cabeceras para respuesta JSON
    $response = $this->getResponse();
    $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
    
    // Permitir solo peticiones AJAX con método POST
    $request = $this->getRequest();
    if (!$request->isXmlHttpRequest() || !$request->isPost()) {
        $response->setContent(json_encode([
            'success' => false,
            'message' => 'Método no permitido'
        ]));
        return $response;
    }
    
    // Obtener parámetros JSON del cuerpo de la petición
    $data = json_decode($request->getContent(), true);
    $ean = $data['ean'] ?? '';
    $orderId = $data['orderId'] ?? '';
    $table = $data['table'] ?? '';
    
    if (empty($ean)) {
        $response->setContent(json_encode([
            'success' => false,
            'message' => 'EAN no especificado'
        ]));
        return $response;
    }
    
    // Limpiar el código EAN de posibles espacios o caracteres no deseados
    $ean = trim($ean);
    
    // Registrar la búsqueda para fines de depuración
    error_log("Buscando EAN: " . $ean . " para orden: " . $orderId . " en tabla: " . $table);
    
    try {
        // Obtener detalles de la orden incluyendo productos
        $orderProducts = $this->getOrderProducts($orderId, $table);
        
        // Buscar el producto en la base de datos
        $product = $this->findProductByEan($ean);
        
        // Si no se encuentra, intentar con variaciones del EAN
        if (!$product) {
            error_log("Producto no encontrado con EAN exacto, intentando alternativas...");
            $product = $this->findProductWithAlternativeMethods($ean);
        }
        
        // Si se encontró el producto
        if ($product) {
            error_log("Producto encontrado: " . json_encode($product));
            
            // Verificar si este producto pertenece a la orden
            $belongsToOrder = false;
            $matchInfo = null;
            
            if (!empty($orderProducts)) {
                // Buscar coincidencias exactas o parciales
                $matchInfo = $this->findProductMatchInOrder($product, $orderProducts);
                $belongsToOrder = $matchInfo['found'];
            }
            
            // Construir respuesta
            $response->setContent(json_encode([
                'success' => true,
                'found' => true,
                'sku' => $product['code'],
                'ean' => $ean,
                'productName' => $product['product_name'] . ' - ' . $product['description'],
                'productId' => $product['product_id'],
                'inOrder' => $belongsToOrder,
                'matchType' => $belongsToOrder ? $matchInfo['matchType'] : 'none',
                'orderSku' => $belongsToOrder ? $matchInfo['orderSku'] : null,
                'suggestions' => $belongsToOrder ? null : $this->getSimilarProducts($product)
            ]));
            return $response;
        }
        
        // Si llegamos aquí, el producto no se encontró
        error_log("No se encontró ningún producto con EAN: " . $ean);
        $response->setContent(json_encode([
            'success' => true,
            'found' => false,
            'message' => 'No se encontró ningún producto con el EAN: ' . $ean
        ]));
        return $response;
    } catch (\Exception $e) {
        error_log('Error al buscar EAN: ' . $e->getMessage());
        
        $response->setContent(json_encode([
            'success' => false,
            'message' => 'Error al buscar el producto: ' . $e->getMessage()
        ]));
        return $response;
    }
}

/**
 * Obtiene los productos de una orden específica
 * AÑADIR como método privado en el controlador
 */
private function getOrderProducts($orderId, $table)
{
    if (empty($orderId) || empty($table)) {
        return [];
    }
    
    try {
        // Obtener los productos de la orden
        $orderSql = "SELECT id, suborder_number, productos, sku FROM {$table} WHERE id = ? OR suborder_number = ?";
        $orderStatement = $this->dbAdapter->createStatement($orderSql);
        $orderResult = $orderStatement->execute([$orderId, $orderId]);
        
        if ($orderResult->count() === 0) {
            error_log("Orden no encontrada: {$orderId} en tabla {$table}");
            return [];
        }
        
        $orderData = $orderResult->current();
        $productos = [];
        
        // Intentar procesar productos según el formato
        if (!empty($orderData['productos'])) {
            $productosStr = $orderData['productos'];
            
            // Intentar como JSON primero
            $decoded = json_decode($productosStr, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $productos = $decoded;
            } else {
                // Interpretar como lista separada por comas
                $skus = [];
                
                // Si hay un SKU en la orden, usarlo
                if (!empty($orderData['sku'])) {
                    $skus = explode(',', trim($orderData['sku']));
                }
                
                $productNames = explode(',', $productosStr);
                
                foreach ($productNames as $i => $name) {
                    $productos[] = [
                        'sku' => isset($skus[$i]) ? trim($skus[$i]) : 'SKU-' . str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT),
                        'nombre' => trim($name),
                        'procesado' => 0
                    ];
                }
            }
        }
        
        // Registrar los productos encontrados para depuración
        error_log("Productos en la orden: " . json_encode($productos));
        
        return $productos;
    } catch (\Exception $e) {
        error_log("Error al obtener productos de la orden: " . $e->getMessage());
        return [];
    }
}

/**
 * Busca un producto por código EAN en la base de datos
 * AÑADIR como método privado en el controlador
 */
private function findProductByEan($ean)
{
    // Buscar en la tabla de variantes por código de barras
    $sql = "SELECT v.id, v.code, v.barCode, v.description, v.product_id, p.name as product_name 
            FROM bsale_variants v 
            JOIN bsale_products p ON v.product_id = p.id 
            WHERE v.barCode = ?";
    
    $statement = $this->dbAdapter->createStatement($sql);
    $result = $statement->execute([$ean]);
    
    if ($result->count() > 0) {
        return $result->current();
    }
    
    // Intentar buscar por código (algunos sistemas guardan el EAN como código)
    $sql = "SELECT v.id, v.code, v.barCode, v.description, v.product_id, p.name as product_name 
            FROM bsale_variants v 
            JOIN bsale_products p ON v.product_id = p.id 
            WHERE v.code = ?";
    
    $statement = $this->dbAdapter->createStatement($sql);
    $result = $statement->execute([$ean]);
    
    if ($result->count() > 0) {
        return $result->current();
    }
    
    return null;
}

/**
 * Intenta encontrar un producto usando métodos alternativos cuando la búsqueda exacta falla
 * AÑADIR como método privado en el controlador
 */
private function findProductWithAlternativeMethods($ean)
{
    // 1. Intentar con los últimos dígitos (para códigos con prefijos variables)
    if (strlen($ean) > 6) {
        $lastDigits = substr($ean, -6);
        $sql = "SELECT v.id, v.code, v.barCode, v.description, v.product_id, p.name as product_name 
                FROM bsale_variants v 
                JOIN bsale_products p ON v.product_id = p.id 
                WHERE v.barCode LIKE ? OR v.code LIKE ?";
        
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute(['%' . $lastDigits, '%' . $lastDigits]);
        
        if ($result->count() > 0) {
            $product = $result->current();
            // Actualizar el código original para mantener consistencia
            $product['originalEan'] = $ean;
            return $product;
        }
    }
    
    // 2. Buscar códigos similares (por ejemplo, con errores tipográficos)
    // Esto busca códigos que difieran en un solo carácter
    for ($i = 0; $i < strlen($ean); $i++) {
        $pattern = substr($ean, 0, $i) . '_' . substr($ean, $i + 1);
        
        $sql = "SELECT v.id, v.code, v.barCode, v.description, v.product_id, p.name as product_name 
                FROM bsale_variants v 
                JOIN bsale_products p ON v.product_id = p.id 
                WHERE v.barCode LIKE ? OR v.code LIKE ?";
        
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute([$pattern, $pattern]);
        
        if ($result->count() > 0) {
            $product = $result->current();
            $product['originalEan'] = $ean;
            return $product;
        }
    }
    
    // 3. Intentar buscar por nombre parcial si el EAN parece contener texto
    if (!is_numeric($ean) && strlen($ean) > 3) {
        $sql = "SELECT v.id, v.code, v.barCode, v.description, v.product_id, p.name as product_name 
                FROM bsale_variants v 
                JOIN bsale_products p ON v.product_id = p.id 
                WHERE p.name LIKE ? OR v.description LIKE ?";
        
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute(['%' . $ean . '%', '%' . $ean . '%']);
        
        if ($result->count() > 0) {
            $product = $result->current();
            $product['originalEan'] = $ean;
            return $product;
        }
    }
    
    return null;
}

/**
 * Busca coincidencias de un producto en los productos de una orden
 * AÑADIR como método privado en el controlador
 */
private function findProductMatchInOrder($product, $orderProducts)
{
    $productCode = $product['code'];
    $productBarCode = $product['barCode'];
    $productName = strtolower($product['product_name'] . ' ' . $product['description']);
    
    // Resultado predeterminado
    $result = [
        'found' => false,
        'matchType' => 'none',
        'orderSku' => null,
        'confidence' => 0
    ];
    
    foreach ($orderProducts as $orderProduct) {
        $orderSku = isset($orderProduct['sku']) ? trim($orderProduct['sku']) : '';
        $orderName = isset($orderProduct['nombre']) ? strtolower(trim($orderProduct['nombre'])) : '';
        
        // 1. Coincidencia exacta por SKU
        if (!empty($orderSku) && ($orderSku === $productCode || $orderSku === $productBarCode)) {
            return [
                'found' => true,
                'matchType' => 'exact_sku',
                'orderSku' => $orderSku,
                'confidence' => 100
            ];
        }
        
        // 2. Coincidencia por nombre exacto
        if (!empty($orderName) && $orderName === $productName) {
            return [
                'found' => true,
                'matchType' => 'exact_name',
                'orderSku' => $orderSku,
                'confidence' => 95
            ];
        }
        
        // 3. Coincidencia parcial por SKU
        if (!empty($orderSku) && (
            strpos($orderSku, $productCode) !== false || 
            strpos($productCode, $orderSku) !== false
        )) {
            $confidence = min(strlen($orderSku), strlen($productCode)) / max(strlen($orderSku), strlen($productCode)) * 90;
            
            if ($confidence > $result['confidence']) {
                $result = [
                    'found' => true,
                    'matchType' => 'partial_sku',
                    'orderSku' => $orderSku,
                    'confidence' => $confidence
                ];
            }
        }
        
        // 4. Coincidencia por nombre parcial
        if (!empty($orderName) && !empty($productName)) {
            // Verificar coincidencia de palabras clave
            $productWords = explode(' ', $productName);
            $orderWords = explode(' ', $orderName);
            
            $commonWords = array_intersect($productWords, $orderWords);
            $wordMatchRatio = count($commonWords) / max(count($productWords), count($orderWords));
            
            $confidence = $wordMatchRatio * 85;
            
            if ($confidence > $result['confidence']) {
                $result = [
                    'found' => true,
                    'matchType' => 'partial_name',
                    'orderSku' => $orderSku,
                    'confidence' => $confidence
                ];
            }
            
            // Verificar si el nombre del producto contiene el nombre de la orden o viceversa
            if (strpos($productName, $orderName) !== false || strpos($orderName, $productName) !== false) {
                $confidence = min(strlen($orderName), strlen($productName)) / max(strlen($orderName), strlen($productName)) * 80;
                
                if ($confidence > $result['confidence']) {
                    $result = [
                        'found' => true,
                        'matchType' => 'contained_name',
                        'orderSku' => $orderSku,
                        'confidence' => $confidence
                    ];
                }
            }
        }
    }
    
    // Solo considerar como coincidencia si la confianza es superior al 60%
    if ($result['confidence'] < 60) {
        $result['found'] = false;
    }
    
    return $result;
}

/**
 * Obtiene productos similares para sugerir
 * AÑADIR como método privado en el controlador
 */
private function getSimilarProducts($product)
{
    try {
        $productName = $product['product_name'];
        $brandName = explode(' ', $productName)[0]; // Utiliza la primera palabra como marca
        
        $sql = "SELECT v.code, p.name as product_name, v.description
                FROM bsale_variants v
                JOIN bsale_products p ON v.product_id = p.id
                WHERE p.name LIKE ?
                LIMIT 5";
        
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute(['%' . $brandName . '%']);
        
        $suggestions = [];
        foreach ($result as $row) {
            $suggestions[] = [
                'sku' => $row['code'],
                'name' => $row['product_name'] . ' - ' . $row['description']
            ];
        }
        
        return $suggestions;
    } catch (\Exception $e) {
        error_log("Error al obtener productos similares: " . $e->getMessage());
        return [];
    }
}

/**
 * Función para obtener el nombre de un producto por su SKU
 * AÑADIR como método privado en el controlador
 */
private function getProductNameBySku($sku)
{
    try {
        $sql = "SELECT v.code, p.name as product_name, v.description 
                FROM bsale_variants v 
                JOIN bsale_products p ON v.product_id = p.id 
                WHERE v.code = ?";
        
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute([$sku]);
        
        if ($result->count() > 0) {
            $row = $result->current();
            return $row['product_name'] . ' - ' . $row['description'];
        }
        
        return null;
    } catch (\Exception $e) {
        error_log("Error al obtener nombre de producto: " . $e->getMessage());
        return null;
    }
}

/**
 * Compara dos nombres de productos para ver si están relacionados
 * AÑADIR como método privado en el controlador
 */
private function areNamesRelated($name1, $name2)
{
    $name1 = strtolower(trim($name1));
    $name2 = strtolower(trim($name2));
    
    // 1. Coincidencia exacta
    if ($name1 === $name2) {
        return true;
    }
    
    // 2. Uno contiene al otro
    if (strpos($name1, $name2) !== false || strpos($name2, $name1) !== false) {
        return true;
    }
    
    // 3. Palabras clave comunes (ignorando palabras comunes)
    $commonWords = ['el', 'la', 'los', 'las', 'de', 'del', 'para', 'por', 'con', 'y', 'o', 'a', 'al', 'en'];
    
    $words1 = array_filter(explode(' ', $name1), function($word) use ($commonWords) {
        return !in_array($word, $commonWords) && strlen($word) > 2;
    });
    
    $words2 = array_filter(explode(' ', $name2), function($word) use ($commonWords) {
        return !in_array($word, $commonWords) && strlen($word) > 2;
    });
    
    $commonKeywords = array_intersect($words1, $words2);
    
    // Si hay al menos 2 palabras clave en común o más del 50% de coincidencia
    return count($commonKeywords) >= 2 || 
           (count($words1) > 0 && count($words2) > 0 && 
            count($commonKeywords) / min(count($words1), count($words2)) >= 0.5);
}

/**
 * Obtiene información detallada de un producto por su SKU
 * AÑADIR como método privado en el controlador
 */
private function getDetailedProductInfo($sku)
{
    try {
        $sql = "SELECT v.code, p.name as product_name, v.description, v.price
                FROM bsale_variants v 
                JOIN bsale_products p ON v.product_id = p.id 
                WHERE v.code = ?";
        
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute([$sku]);
        
        if ($result->count() > 0) {
            $row = $result->current();
            return [
                'sku' => $row['code'],
                'nombre' => $row['product_name'] . ' - ' . $row['description'],
                'precio' => $row['price'] ?? 0
            ];
        }
        
        return null;
    } catch (\Exception $e) {
        error_log("Error al obtener información detallada del producto: " . $e->getMessage());
        return null;
    }
}

/**
 * REEMPLAZAR la función markProductProcessedAction() existente con esta versión
 * Acción para marcar un producto específico como procesado en una orden
 */
public function markProductProcessedAction()
{
    // Configurar cabeceras para respuesta JSON
    $response = $this->getResponse();
    $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
    
    // Permitir solo peticiones AJAX con método POST
    $request = $this->getRequest();
    if (!$request->isXmlHttpRequest() || !$request->isPost()) {
        $response->setContent(json_encode([
            'success' => false,
            'message' => 'Método no permitido'
        ]));
        return $response;
    }
    
    // Obtener parámetros JSON del cuerpo de la petición
    $data = json_decode($request->getContent(), true);
    $orderId = $data['orderId'] ?? '';
    $table = $data['table'] ?? '';
    $sku = $data['sku'] ?? '';
    $orderSku = $data['orderSku'] ?? $sku; // Usar orderSku si está disponible, sino usar sku
    
    if (empty($orderId) || empty($table) || empty($sku)) {
        $response->setContent(json_encode([
            'success' => false,
            'message' => 'Parámetros incompletos'
        ]));
        return $response;
    }
    
    try {
        // Primero obtenemos la orden para acceder a los productos
        $sql = "SELECT id, productos FROM {$table} WHERE id = ? OR suborder_number = ?";
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute([$orderId, $orderId]);
        
        if ($result->count() === 0) {
            $response->setContent(json_encode([
                'success' => false,
                'message' => 'Orden no encontrada'
            ]));
            return $response;
        }
        
        $orderData = $result->current();
        $orderRealId = $orderData['id']; // Guardamos el ID real de la orden
        $productosStr = $orderData['productos'] ?? '';
        
        // Decodificar los productos (puede ser JSON o string)
        $productos = json_decode($productosStr, true);
        $updated = false;
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($productos)) {
            // Es un JSON válido
            foreach ($productos as $key => $producto) {
                // Comprobar coincidencia exacta o parcial
                if (
                    (isset($producto['sku']) && $producto['sku'] === $orderSku) ||
                    (isset($producto['sku']) && $producto['sku'] === $sku) ||
                    (isset($producto['sku']) && strpos($producto['sku'], $sku) !== false) ||
                    (isset($producto['sku']) && strpos($sku, $producto['sku']) !== false)
                ) {
                    $productos[$key]['procesado'] = 1;
                    $updated = true;
                    break;
                }
            }
            
            if ($updated) {
                // Actualizar la orden con los productos procesados
                $updateSql = "UPDATE {$table} SET productos = ? WHERE id = ?";
                $updateStatement = $this->dbAdapter->createStatement($updateSql);
                $updateStatement->execute([json_encode($productos), $orderRealId]);
                
                // Verificar si todos los productos están procesados
                $allProcessed = true;
                foreach ($productos as $producto) {
                    if (!isset($producto['procesado']) || $producto['procesado'] != 1) {
                        $allProcessed = false;
                        break;
                    }
                }
                
                // Si todos los productos están procesados, actualizar estado general
                if ($allProcessed) {
                    $updateOrderSql = "UPDATE {$table} SET procesado = 1 WHERE id = ?";
                    $updateOrderStatement = $this->dbAdapter->createStatement($updateOrderSql);
                    $updateOrderStatement->execute([$orderRealId]);
                }
                
                $response->setContent(json_encode([
                    'success' => true, 
                    'message' => 'Producto marcado como procesado',
                    'allProcessed' => $allProcessed
                ]));
                return $response;
            } else {
                // Si no se actualizó ningún producto, intentamos con una búsqueda más flexible
                // Comparar nombres en lugar de SKUs
                foreach ($productos as $key => $producto) {
                    if (isset($producto['nombre'])) {
                        // Obtener el nombre del producto escaneado
                        $scannedProductName = $this->getProductNameBySku($sku);
                        
                        if ($scannedProductName && $this->areNamesRelated($producto['nombre'], $scannedProductName)) {
                            $productos[$key]['procesado'] = 1;
                            $updated = true;
                            break;
                        }
                    }
                }
                
                if ($updated) {
                    // Actualizar la orden
                    $updateSql = "UPDATE {$table} SET productos = ? WHERE id = ?";
                    $updateStatement = $this->dbAdapter->createStatement($updateSql);
                    $updateStatement->execute([json_encode($productos), $orderRealId]);
                    
                    // Verificar si todos los productos están procesados
                    $allProcessed = true;
                    foreach ($productos as $producto) {
                        if (!isset($producto['procesado']) || $producto['procesado'] != 1) {
                            $allProcessed = false;
                            break;
                        }
                    }
                    
                    if ($allProcessed) {
                        $updateOrderSql = "UPDATE {$table} SET procesado = 1 WHERE id = ?";
                        $updateOrderStatement = $this->dbAdapter->createStatement($updateOrderSql);
                        $updateOrderStatement->execute([$orderRealId]);
                    }
                    
                    $response->setContent(json_encode([
                        'success' => true, 
                        'message' => 'Producto marcado como procesado (coincidencia de nombre)',
                        'allProcessed' => $allProcessed
                    ]));
                    return $response;
                } else {
                    // Si aún no hay coincidencia, agregamos el producto a la orden
                    $scannedProductInfo = $this->getDetailedProductInfo($sku);
                    
                    if ($scannedProductInfo) {
                        $productos[] = [
                            'sku' => $sku,
                            'nombre' => $scannedProductInfo['nombre'],
                            'cantidad' => 1,
                            'precio_unitario' => $scannedProductInfo['precio'] ?? 0,
                            'subtotal' => $scannedProductInfo['precio'] ?? 0,
                            'procesado' => 1,
                            'added_manually' => true
                        ];
                        
                        $updateSql = "UPDATE {$table} SET productos = ? WHERE id = ?";
                        $updateStatement = $this->dbAdapter->createStatement($updateSql);
                        $updateStatement->execute([json_encode($productos), $orderRealId]);
                        
                        $response->setContent(json_encode([
                            'success' => true, 
                            'message' => 'Producto añadido a la orden y marcado como procesado',
                            'allProcessed' => false,
                            'productAdded' => true,
                            'productName' => $scannedProductInfo['nombre']
                        ]));
                        return $response;
                    }
                    
                    $response->setContent(json_encode([
                        'success' => false,
                        'message' => 'Producto no encontrado en la orden y no se pudo añadir'
                    ]));
                    return $response;
                }
            }
        } else {
            // No es un JSON válido, convertimos el campo de texto a formato JSON
            $productNames = explode(',', $productosStr);
            $productos = [];
            
            foreach ($productNames as $i => $nombre) {
                $productoSku = 'SKU-' . str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT);
                $procesado = (strpos(strtolower(trim($nombre)), strtolower($sku)) !== false) ? 1 : 0;
                
                if ($procesado === 1) {
                    $updated = true;
                }
                
                $productos[] = [
                    'sku' => $productoSku,
                    'nombre' => trim($nombre),
                    'cantidad' => 1,
                    'precio_unitario' => 0,
                    'subtotal' => 0,
                    'procesado' => $procesado
                ];
            }
            
            // Si no se encontró coincidencia, agregar el producto escaneado
            if (!$updated) {
                $scannedProductInfo = $this->getDetailedProductInfo($sku);
                
                if ($scannedProductInfo) {
                    $productos[] = [
                        'sku' => $sku,
                        'nombre' => $scannedProductInfo['nombre'],
                        'cantidad' => 1,
                        'precio_unitario' => $scannedProductInfo['precio'] ?? 0,
                        'subtotal' => $scannedProductInfo['precio'] ?? 0,
                        'procesado' => 1,
                        'added_manually' => true
                    ];
                    $updated = true;
                }
            }
            
            if ($updated) {
                // Actualizar la orden con el formato JSON
                $updateSql = "UPDATE {$table} SET productos = ? WHERE id = ?";
                $updateStatement = $this->dbAdapter->createStatement($updateSql);
                $updateStatement->execute([json_encode($productos), $orderRealId]);
                
                // Verificar si todos los productos están procesados
                $allProcessed = true;
                foreach ($productos as $producto) {
                    if (!isset($producto['procesado']) || $producto['procesado'] != 1) {
                        $allProcessed = false;
                        break;
                    }
                }
                
                if ($allProcessed) {
                    $updateOrderSql = "UPDATE {$table} SET procesado = 1 WHERE id = ?";
                    $updateOrderStatement = $this->dbAdapter->createStatement($updateOrderSql);
                    $updateOrderStatement->execute([$orderRealId]);
                }
                
                $response->setContent(json_encode([
                    'success' => true, 
                    'message' => 'Productos convertidos a formato JSON y actualizados',
                    'allProcessed' => $allProcessed
                ]));
                return $response;
            } else {
                $response->setContent(json_encode([
                    'success' => false,
                    'message' => 'No se pudo encontrar o agregar el producto'
                ]));
                return $response;
            }
        }
    } catch (\Exception $e) {
        // Registrar error para depuración
        error_log('Error al marcar producto como procesado: ' . $e->getMessage());
        
        $response->setContent(json_encode([
            'success' => false,
            'message' => 'Error al procesar la solicitud: ' . $e->getMessage()
        ]));
        return $response;
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
    
    public function scanOrdersAction()
    {
        return new ViewModel();
    }

    public function findOrderAction()
{
    // Configurar cabeceras para respuesta JSON
    $response = $this->getResponse();
    $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
    
    // Permitir solo peticiones AJAX con método POST
    $request = $this->getRequest();
    if (!$request->isXmlHttpRequest() || !$request->isPost()) {
        $response->setContent(json_encode([
            'success' => false,
            'message' => 'Método no permitido'
        ]));
        return $response;
    }
    
    // Obtener parámetros JSON del cuerpo de la petición
    $data = json_decode($request->getContent(), true);
    $suborderNumber = $data['suborderNumber'] ?? '';
    
    if (empty($suborderNumber)) {
        $response->setContent(json_encode([
            'success' => false,
            'message' => 'Número de suborden no especificado'
        ]));
        return $response;
    }
    
    // Limpiar el código de posibles espacios
    $suborderNumber = trim($suborderNumber);
    
    try {
        // Tablas donde buscar
        $tables = [
            'Orders_WALLMART', 
            'Orders_RIPLEY', 
            'Orders_FALABELLA', 
            'Orders_MERCADO_LIBRE', 
            'Orders_PARIS'
        ];
        
        // Buscar en cada tabla
        foreach ($tables as $table) {
            $sql = "SELECT id FROM `$table` WHERE suborder_number = ?";
            $statement = $this->dbAdapter->createStatement($sql);
            $result = $statement->execute([$suborderNumber]);
            
            if ($result->count() > 0) {
                // Orden encontrada, extraer marketplace
                $marketplace = str_replace('Orders_', '', $table);
                
                $response->setContent(json_encode([
                    'success' => true,
                    'tableName' => $table,
                    'marketplace' => $marketplace,
                    'message' => 'Orden encontrada'
                ]));
                return $response;
            }
        }
        
        // Si llegamos aquí, no se encontró la orden
        $response->setContent(json_encode([
            'success' => false,
            'message' => 'No se encontró ninguna orden con el número: ' . $suborderNumber
        ]));
        return $response;
        
    } catch (\Exception $e) {
        // Log del error
        error_log('Error al buscar orden: ' . $e->getMessage());
        
        $response->setContent(json_encode([
            'success' => false,
            'message' => 'Error al buscar la orden: ' . $e->getMessage()
        ]));
        return $response;
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
 * Acción para marcar orden como impresa
 */
public function markAsPrintedAction()
{
    // Verificar autenticación
    $redirect = $this->checkAuth();
    if ($redirect !== null) {
        return $redirect;
    }
    
    // Obtener datos de la solicitud
    $data = json_decode(file_get_contents('php://input'), true);
    $orderId = $data['orderId'] ?? null;
    $table = $data['table'] ?? null;
    
    if (!$orderId || !$table) {
        return $this->jsonResponse(['success' => false, 'message' => 'Faltan parámetros requeridos']);
    }
    
    // Validar tabla
    if (strpos($table, 'Orders_') !== 0) {
        return $this->jsonResponse(['success' => false, 'message' => 'Tabla inválida']);
    }
    
    try {
        // Actualizar estado de impresión
        $sql = "UPDATE `$table` SET printed = 1 WHERE id = ?";
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute([$orderId]);
        
        // Registrar historial
        try {
            $historySql = "INSERT INTO order_status_history (order_id, table_name, status, notes, created_at) 
                          VALUES (?, ?, 'printed', 'Marcado como impreso', NOW())";
            $historyStatement = $this->dbAdapter->createStatement($historySql);
            $historyResult = $historyStatement->execute([$orderId, $table]);
        } catch (\Exception $e) {
            // Continuar si la tabla de historial no existe
        }
        
        return $this->jsonResponse([
            'success' => true, 
            'message' => 'Estado de impresión actualizado correctamente'
        ]);
        
    } catch (\Exception $e) {
        return $this->jsonResponse([
            'success' => false, 
            'message' => 'Error al actualizar estado: ' . $e->getMessage()
        ]);
    }
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
    
    // Recolectar datos de productos con sus clientes
    $productos = [];
    
    foreach ($tables as $currentTable) {
        $tableMarketplace = str_replace('Orders_', '', $currentTable);
        
        // Obtener los datos de las órdenes en esta tabla
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        
        try {
            // Consulta para obtener detalles de órdenes
            $sqlOrders = "SELECT id, customer_name, suborder_number, productos, sku FROM `$currentTable` WHERE id IN ($placeholders)";
            $stmt = $this->dbAdapter->createStatement($sqlOrders);
            $ordersResult = $stmt->execute($orderIds);
            
            foreach ($ordersResult as $order) {
                $orderId = $order['id'];
                $suborderNumber = $order['suborder_number'] ?? '';
                $customerName = $order['customer_name'] ?? 'N/A';
                
                // Obtener los productos de la orden con el método dedicado
                $orderProducts = $this->getOrderProducts($orderId, $currentTable);
                
                if (!empty($orderProducts)) {
                    foreach ($orderProducts as $product) {
                        $productos[] = [
                            'sku' => (string)($product['sku'] ?? 'Sin SKU'),
                            'nombre' => (string)($product['nombre'] ?? 'Sin nombre'),
                            'subOrderNumber' => (string)$suborderNumber,
                            'customer_name' => (string)$customerName,
                            'marketplace' => (string)$tableMarketplace,
                            'cantidad' => (string)($product['cantidad'] ?? 1)
                        ];
                    }
                } else {
                    // Intentar extraer SKUs manualmente si el método anterior falló
                    if (!empty($order['productos'])) {
                        $productosStr = $order['productos'];
                        
                        // Intentar como lista separada por comas
                        $skus = [];
                        
                        // Si hay un SKU en la orden, usarlo
                        if (!empty($order['sku'])) {
                            $skus = explode(',', trim($order['sku']));
                        }
                        
                        $productNames = explode(',', $productosStr);
                        
                        foreach ($productNames as $i => $name) {
                            if (empty(trim($name))) continue;
                            
                            $productos[] = [
                                'sku' => (string)(isset($skus[$i]) ? trim($skus[$i]) : 'SKU-' . str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT)),
                                'nombre' => (string)trim($name),
                                'subOrderNumber' => (string)$suborderNumber,
                                'customer_name' => (string)$customerName,
                                'marketplace' => (string)$tableMarketplace,
                                'cantidad' => (string)1
                            ];
                        }
                    } else {
                        // Si no hay información de productos, crear un registro genérico
                        $productos[] = [
                            'sku' => (string)('SKU-' . substr(md5($suborderNumber), 0, 8)),
                            'nombre' => (string)('Producto de orden #' . $suborderNumber),
                            'subOrderNumber' => (string)$suborderNumber,
                            'customer_name' => (string)$customerName,
                            'marketplace' => (string)$tableMarketplace,
                            'cantidad' => (string)1
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Error procesando tabla {$currentTable}: " . $e->getMessage());
            continue;
        }
    }
    
    if (empty($productos)) {
        throw new \Exception("No se encontraron productos para las órdenes seleccionadas.");
    }
    
    // Generar HTML para el documento
    ob_start();
    ?>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #000; padding: 6px; text-align: left; }
        th { background-color: #eee; }
        .title { font-size: 20px; font-weight: bold; margin-bottom: 10px; }
        .subtitle { margin: 10px 0; font-weight: bold; }
    </style>

    <div class="title">Picking List - <?= $marketplace ?> | LODORO</div>
    <div>PICKING LIST GENERADO EL: <?= date("Y-m-d H:i:s") ?> |</div>
    <br>

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
        <?php foreach ($productos as $producto): ?>
            <tr>
                <td><?= htmlspecialchars((string)($producto['customer_name'] ?? 'N/A')) ?></td>
                <td><?= htmlspecialchars((string)($producto['subOrderNumber'] ?? 'N/A')) ?></td>
                <td><?= htmlspecialchars((string)($producto['nombre'] ?? 'Sin nombre')) ?></td>
                <td><?= htmlspecialchars((string)($producto['sku'] ?? 'Sin SKU')) ?></td>
                <td><?= htmlspecialchars((string)($producto['cantidad'] ?? '1')) ?></td>
                <td><?= htmlspecialchars((string)($producto['marketplace'] ?? 'N/A')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div><strong>TOTAL PRODUCTOS:</strong> <?= count($productos) ?></div>
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
    $headers->addHeaderLine('Content-Disposition', 'inline; filename="PickingList_' . date('Y-m-d_His') . '.pdf"');
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
        $marketplace = "TODOS";
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
        $mkpTable = 'MKP_' . $tableMarketplace;
        
        // Obtener los datos de las órdenes en esta tabla
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        // USAR id en lugar de suborder_number
        $sql = "SELECT * FROM `$currentTable` WHERE id IN ($placeholders)";
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute($orderIds);
        
        foreach ($result as $index => $order) {
            $items = [];
            $orderId = $order['id'];
            $suborderNumber = $order['suborder_number'] ?? '';
            
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
        throw new \Exception("No se encontraron órdenes con los IDs seleccionados.");
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


class UploadLiquidationController extends AbstractActionController
{
    private $dbAdapter;

    /**
     * Constructor
     *
     * @param AdapterInterface $dbAdapter Database adapter
     */
    public function __construct(AdapterInterface $dbAdapter)
    {
        $this->dbAdapter = $dbAdapter;
    }

    /**
     * Upload liquidation action
     *
     * @return JsonModel
     */
    public function uploadLiquidationAction()
    {
        // Set default response
        $response = [
            'success' => false,
            'message' => 'Error al procesar la solicitud',
            'processedRows' => 0,
        ];

        // Check if request is POST
        if (!$this->getRequest()->isPost()) {
            $response['message'] = 'Método no permitido';
            return new JsonModel($response);
        }

        try {
            // Get uploaded file
            $files = $this->getRequest()->getFiles()->toArray();
            
            if (empty($files) || !isset($files['liquidationFile']) || $files['liquidationFile']['error'] !== UPLOAD_ERR_OK) {
                $response['message'] = 'No se ha seleccionado un archivo válido';
                return new JsonModel($response);
            }

            $file = $files['liquidationFile'];
            $filePath = $file['tmp_name'];
            
            // Check if process in background
            $processInBackground = $this->params()->fromPost('processInBackground', false);
            
            if ($processInBackground) {
                // Generate a job ID
                $jobId = uniqid('job_');
                
                // Save the file to a temporary location that can be accessed by the background process
                $tempFilePath = sys_get_temp_dir() . '/' . $jobId . '_' . basename($file['name']);
                move_uploaded_file($filePath, $tempFilePath);
                
                // Start a background process
                $this->startBackgroundProcess($tempFilePath, $jobId);
                
                $response = [
                    'success' => true,
                    'message' => 'El archivo se está procesando en segundo plano',
                    'jobId' => $jobId,
                ];
                
                return new JsonModel($response);
            } else {
                // Process immediately
                $result = $this->processLiquidationFile($filePath);
                
                $response = [
                    'success' => $result['success'],
                    'message' => $result['message'],
                    'processedRows' => $result['processedRows'],
                ];
                
                return new JsonModel($response);
            }
            
        } catch (Exception $e) {
            $response['message'] = 'Error: ' . $e->getMessage();
            return new JsonModel($response);
        }
    }
    
    /**
     * Start a background process to handle the Excel file
     *
     * @param string $filePath Path to the uploaded file
     * @param string $jobId Unique job identifier
     * @return void
     */
    private function startBackgroundProcess($filePath, $jobId)
    {
        // Create a log file for this job
        $logFile = sys_get_temp_dir() . '/' . $jobId . '_log.txt';
        
        // Escape path for shell
        $escapedFilePath = escapeshellarg($filePath);
        $escapedLogFile = escapeshellarg($logFile);
        $escapedJobId = escapeshellarg($jobId);
        
        // Command to run the processor in background
        // This will run a PHP CLI script that processes the Excel file
        // The script would be a new file we need to create: process-liquidation.php
        $cmd = sprintf(
            'php %s/public/process-liquidation.php %s %s %s > /dev/null 2>&1 &',
            APPLICATION_PATH,
            $escapedFilePath,
            $escapedLogFile,
            $escapedJobId
        );
        
        // Execute command (runs in background)
        exec($cmd);
    }
    
    /**
     * Process liquidation file (Excel)
     *
     * @param string $filePath Path to the uploaded file
     * @return array Processing result with success status, message and processed rows count
     */
    private function processLiquidationFile($filePath)
    {
        $result = [
            'success' => false,
            'message' => 'Error al procesar el archivo',
            'processedRows' => 0,
        ];
        
        try {
            // Load the spreadsheet
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            
            // Get the highest row and column
            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
            
            // If there are fewer than 2 rows (header + at least one data row), return error
            if ($highestRow < 2) {
                $result['message'] = 'El archivo no contiene datos suficientes';
                return $result;
            }
            
            // Initialize column mapping (try to find the columns we need)
            $columnMap = $this->findColumnMapping($worksheet, $highestColumnIndex);
            
            if (empty($columnMap['numero_suborden']) || 
                empty($columnMap['numero_liquidacion']) || 
                empty($columnMap['monto_liquidacion'])) {
                $result['message'] = 'No se encontraron las columnas necesarias en el archivo Excel';
                return $result;
            }
            
            // Process data rows
            $processedRows = 0;
            $updateCount = 0;
            $errorRows = [];
            
            for ($row = 2; $row <= $highestRow; $row++) {
                $numeroSuborden = trim($worksheet->getCellByColumnAndRow($columnMap['numero_suborden'], $row)->getValue());
                $numeroLiquidacion = trim($worksheet->getCellByColumnAndRow($columnMap['numero_liquidacion'], $row)->getValue());
                $montoLiquidacion = $worksheet->getCellByColumnAndRow($columnMap['monto_liquidacion'], $row)->getValue();
                
                // Skip empty rows
                if (empty($numeroSuborden) || empty($numeroLiquidacion)) {
                    continue;
                }
                
                // Convert monto_liquidacion to a proper decimal
                $montoLiquidacion = floatval(str_replace(',', '.', str_replace('.', '', $montoLiquidacion)));
                
                // Update the database
                $updated = $this->updateLiquidationData($numeroSuborden, $numeroLiquidacion, $montoLiquidacion);
                
                if ($updated) {
                    $updateCount++;
                } else {
                    $errorRows[] = $row;
                }
                
                $processedRows++;
            }
            
            if ($processedRows > 0) {
                $result['success'] = true;
                $result['message'] = "Se procesaron {$processedRows} filas. Se actualizaron {$updateCount} registros.";
                if (!empty($errorRows)) {
                    $result['message'] .= " No se pudieron actualizar " . count($errorRows) . " filas.";
                }
                $result['processedRows'] = $processedRows;
            } else {
                $result['message'] = 'No se procesaron filas. Verifique el formato del archivo.';
            }
            
            return $result;
            
        } catch (Exception $e) {
            $result['message'] = 'Error al procesar el archivo: ' . $e->getMessage();
            return $result;
        }
    }
    
    /**
     * Find column mapping in the Excel file
     * 
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet
     * @param int $highestColumnIndex
     * @return array Column mapping with column indices
     */
    private function findColumnMapping($worksheet, $highestColumnIndex)
    {
        $columnMap = [
            'numero_suborden' => null,
            'numero_liquidacion' => null,
            'monto_liquidacion' => null,
        ];
        
        // List of possible header names for each column
        $headerMapping = [
            'numero_suborden' => ['numero_suborden', 'numero de suborden', 'suborden', 'orden', 'id', 'codigo', 'order'],
            'numero_liquidacion' => ['numero_liquidacion', 'numero de liquidacion', 'liquidacion', 'num liquidacion', 'n° liquidacion', 'n liquidacion'],
            'monto_liquidacion' => ['monto_liquidacion', 'monto de liquidacion', 'valor liquidacion', 'importe', 'total liquidacion', 'monto'],
        ];
        
        // Read header row
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerValue = trim(strtolower($worksheet->getCellByColumnAndRow($col, 1)->getValue()));
            
            // Check against possible header names
            foreach ($headerMapping as $column => $possibleHeaders) {
                if (in_array($headerValue, $possibleHeaders) || $this->containsKeyword($headerValue, $possibleHeaders)) {
                    $columnMap[$column] = $col;
                    break;
                }
            }
        }
        
        return $columnMap;
    }
    
    /**
     * Check if a header value contains any of the keywords
     * 
     * @param string $headerValue
     * @param array $keywords
     * @return bool
     */
    private function containsKeyword($headerValue, $keywords)
    {
        foreach ($keywords as $keyword) {
            if (strpos($headerValue, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Update liquidation data in the database
     * 
     * @param string $numeroSuborden
     * @param string $numeroLiquidacion
     * @param float $montoLiquidacion
     * @return bool Success or failure
     */
    private function updateLiquidationData($numeroSuborden, $numeroLiquidacion, $montoLiquidacion)
    {
        try {
            $sql = "UPDATE MKP_PARIS SET 
                    numero_liquidacion = ?, 
                    monto_liquidacion = ? 
                    WHERE numero_suborden = ?";
            
            $statement = $this->dbAdapter->query($sql);
            $result = $statement->execute([
                $numeroLiquidacion,
                $montoLiquidacion,
                $numeroSuborden
            ]);
            
            return ($result->getAffectedRows() > 0);
        } catch (Exception $e) {
            // Log the error but don't throw it up
            error_log("Error updating liquidation data: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check liquidation job status
     *
     * @return JsonModel
     */
    public function checkStatusAction()
    {
        $jobId = $this->params()->fromRoute('jobId', null);
        
        if (!$jobId) {
            return new JsonModel([
                'success' => false,
                'message' => 'ID de trabajo no proporcionado'
            ]);
        }
        
        $logFile = sys_get_temp_dir() . '/' . $jobId . '_log.txt';
        
        if (!file_exists($logFile)) {
            return new JsonModel([
                'success' => false,
                'message' => 'El trabajo no existe o aún no ha comenzado',
                'status' => 'pending'
            ]);
        }
        
        $logContent = file_get_contents($logFile);
        
        // Check if the job is completed
        if (strpos($logContent, 'COMPLETED') !== false) {
            // Extract completion data
            preg_match('/COMPLETED: (.*?)(\r\n|\n|$)/', $logContent, $completionMatch);
            $completionData = isset($completionMatch[1]) ? json_decode($completionMatch[1], true) : [];
            
            return new JsonModel([
                'success' => true,
                'status' => 'completed',
                'message' => $completionData['message'] ?? 'Proceso completado',
                'processedRows' => $completionData['processedRows'] ?? 0,
                'timestamp' => $completionData['timestamp'] ?? time()
            ]);
        } 
        // Check if the job failed
        else if (strpos($logContent, 'FAILED') !== false) {
            preg_match('/FAILED: (.*?)(\r\n|\n|$)/', $logContent, $failedMatch);
            
            return new JsonModel([
                'success' => false,
                'status' => 'failed',
                'message' => isset($failedMatch[1]) ? 'Error: ' . $failedMatch[1] : 'El proceso falló',
                'timestamp' => time()
            ]);
        } 
        // Job is still running
        else {
            // Extract progress if available
            preg_match('/PROGRESS: (\d+)\/(\d+)/', $logContent, $progressMatch);
            $current = isset($progressMatch[1]) ? (int)$progressMatch[1] : 0;
            $total = isset($progressMatch[2]) ? (int)$progressMatch[2] : 1;
            $percentage = $total > 0 ? round(($current / $total) * 100) : 0;
            
            return new JsonModel([
                'success' => true,
                'status' => 'running',
                'message' => 'Procesando liquidaciones...',
                'progress' => $percentage,
                'current' => $current,
                'total' => $total
            ]);
        }
    }
}
