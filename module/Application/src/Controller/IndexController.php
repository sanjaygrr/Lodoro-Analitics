<?php
declare(strict_types=1);

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Authentication\AuthenticationService;
use Laminas\Http\Response;

/**
 * Simplified IndexController that primarily serves as a legacy handler
 * Most functionality has been moved to domain-specific controllers
 */
class IndexController extends AbstractActionController
{
    /** @var AuthenticationService */
    protected $authService;

    /**
     * Constructor
     *
     * @param AuthenticationService $authService
     */
    public function __construct(AuthenticationService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Default action: redirects to the dashboard
     */
    public function indexAction()
    {
        // Check authentication
        if (!$this->authService->hasIdentity()) {
            return $this->redirect()->toRoute('login');
        }
<<<<<<< HEAD
=======
        
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
>>>>>>> c989f4cb8390f8b3a9a182793f4a5152d17158eb

        // Redirect to the dashboard
        return $this->redirect()->toRoute('dashboard');
    }
    
    /**
     * Legacy action handler for backward compatibility
     * Redirects to appropriate controllers based on the action
     */
    public function __call($method, $params)
    {
        // Check authentication first
        if (!$this->authService->hasIdentity()) {
            return $this->redirect()->toRoute('login');
        }

        // Extract action name from method name
        if (substr($method, -6) === 'Action') {
            $action = substr($method, 0, -6);

            // Map old actions to new controllers/routes
            $actionMap = [
                'dashboard' => ['dashboard', 'index'],
                'detail' => ['dashboard', 'detail'],
                'marketplace-config' => ['marketplace', 'config'],
                'scan-orders' => ['scan-orders', 'index'],
                'search-ean' => ['scan-orders', 'search-ean'],
                'mark-product-processed' => ['orders', 'mark-product-processed'],
                'process-all-products' => ['orders', 'process-all-products'],
                'mark-as-printed' => ['orders', 'mark-as-printed'],
                'test-connection' => ['marketplace', 'test-connection'],
                'generate-barcode' => ['scan-orders', 'generate-barcode'],
                'upload-liquidation' => ['upload-liquidation', 'upload'],
            ];

            // If the action exists in our map, redirect to the new controller
            if (array_key_exists($action, $actionMap)) {
                $route = $actionMap[$action][0];
                $newAction = $actionMap[$action][1];

                // Pass along any parameters from the original request
                $routeParams = $this->params()->fromRoute();
                unset($routeParams['action']);
                $routeParams['action'] = $newAction;

                return $this->redirect()->toRoute($route, $routeParams);
            }
        }

        // If we don't have a mapping, show 404
        $this->getResponse()->setStatusCode(404);
        return new ViewModel(['message' => "Action '$method' not found"]);
    }
<<<<<<< HEAD
}
=======
    
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
>>>>>>> c989f4cb8390f8b3a9a182793f4a5152d17158eb
