<?php
declare(strict_types=1);

namespace Application\Controller;

use Laminas\View\Model\JsonModel;
use Laminas\Db\Adapter\AdapterInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Controlador para manejar la subida y procesamiento de liquidaciones
 */
class UploadLiquidationController
{
    /** @var AdapterInterface */
    private $dbAdapter;

    /**
     * Constructor
     *
     * @param AdapterInterface $dbAdapter
     */
    public function __construct(AdapterInterface $dbAdapter)
    {
        $this->dbAdapter = $dbAdapter;
    }

    /**
     * Acción para manejar la subida de archivos de liquidación
     *
     * @return JsonModel
     */
    public function uploadAction()
    {
        // Verificar si es un POST
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel([
                'success' => false,
                'message' => 'Método no permitido'
            ]);
        }
        
        // Verificar si se ha enviado un archivo
        $files = $request->getFiles()->toArray();
        if (empty($files) || !isset($files['liquidationFile']) || $files['liquidationFile']['error'] !== UPLOAD_ERR_OK) {
            $error = isset($files['liquidationFile']) ? $this->getFileUploadErrorMessage($files['liquidationFile']['error']) : 'No se ha enviado ningún archivo';
            return new JsonModel([
                'success' => false,
                'message' => $error
            ]);
        }
        
        $file = $files['liquidationFile'];
        
        // Verificar tipo de archivo
        $mimeType = mime_content_type($file['tmp_name']);
        $allowedTypes = [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/octet-stream'
        ];
        
        if (!in_array($mimeType, $allowedTypes)) {
            return new JsonModel([
                'success' => false,
                'message' => 'Tipo de archivo no válido. Solo se permiten archivos Excel.'
            ]);
        }
        
        // Crear directorio de uploads si no existe
        $uploadDir = './data/uploads/liquidaciones';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        // Generar nombre único para el archivo
        $timestamp = time();
        $filename = $timestamp . '_' . $file['name'];
        $targetPath = $uploadDir . '/' . $filename;
        
        // Mover el archivo al directorio destino
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return new JsonModel([
                'success' => false,
                'message' => 'Error al guardar el archivo'
            ]);
        }
        
        try {
            // Verificar si procesar en segundo plano
            $inBackground = (bool)($this->params()->fromPost('processInBackground', false));
            
            if ($inBackground) {
                // Crear trabajo en base de datos
                $jobId = $this->createProcessingJob($filename);
                
                // Devolver respuesta con jobId para seguimiento
                return new JsonModel([
                    'success' => true,
                    'message' => 'Archivo subido correctamente. Se procesará en segundo plano.',
                    'jobId' => $jobId
                ]);
            } else {
                // Procesar archivo directamente
                $result = $this->processFile($targetPath);
                
                return new JsonModel([
                    'success' => true,
                    'message' => 'Archivo procesado correctamente. ' . $result['message'],
                    'processedRows' => $result['processedRows'] ?? 0
                ]);
            }
        } catch (\Exception $e) {
            // Registrar el error
            error_log('Error al procesar liquidación: ' . $e->getMessage());
            
            return new JsonModel([
                'success' => false,
                'message' => 'Error al procesar el archivo: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Procesa el archivo Excel de liquidación
     *
     * @param string $filePath
     * @return array Resultado del procesamiento
     */
    private function processFile(string $filePath): array
    {
        // Cargar el archivo Excel
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Obtener datos como array
        $rows = $worksheet->toArray();
        
        // Al menos una fila de encabezado y una de datos
        if (count($rows) < 2) {
            throw new \Exception('El archivo no contiene datos suficientes');
        }
        
        // Tomar la primera fila como encabezados
        $headers = array_map('trim', $rows[0]);
        
        // Preparar mappings y validar encabezados mínimos necesarios
        $requiredFields = ['fecha', 'boleta', 'sku', 'producto', 'precio', 'estado'];
        $mappings = $this->determineColumnMappings($headers, $requiredFields);
        
        // Procesar cada fila de datos
        $data = [];
        $processedCount = 0;
        
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            
            // Omitir filas vacías
            if (empty(array_filter($row))) {
                continue;
            }
            
            // Crear registro con los campos mapeados
            $record = [];
            foreach ($mappings as $field => $index) {
                if ($index !== null && isset($row[$index])) {
                    $record[$field] = $row[$index];
                }
            }
            
            // Verificar campos mínimos
            $valid = true;
            foreach ($requiredFields as $field) {
                if (!isset($record[$field]) || $record[$field] === '') {
                    $valid = false;
                    break;
                }
            }
            
            if ($valid) {
                $data[] = $record;
                $processedCount++;
            }
        }
        
        // Insertar datos en la base de datos
        if (!empty($data)) {
            $this->saveDataToDatabase($data);
        }
        
        return [
            'success' => true,
            'message' => "Se procesaron $processedCount registros correctamente.",
            'processedRows' => $processedCount
        ];
    }

    /**
     * Determina el mapeo de columnas entre encabezados del Excel y campos necesarios
     *
     * @param array $headers
     * @param array $requiredFields
     * @return array
     */
    private function determineColumnMappings(array $headers, array $requiredFields): array
    {
        $mappings = [];
        
        // Inicializar todos los campos como no encontrados
        foreach ($requiredFields as $field) {
            $mappings[$field] = null;
        }
        
        // Posibles encabezados para cada campo requerido
        $fieldOptions = [
            'fecha' => ['fecha', 'date', 'fecha_venta', 'fecha_pedido', 'fecha_orden'],
            'boleta' => ['boleta', 'nro_boleta', 'boleta_nro', 'factura', 'nro_factura', 'documento'],
            'sku' => ['sku', 'codigo', 'codigo_producto', 'code', 'product_code'],
            'producto' => ['producto', 'descripcion', 'nombre', 'nombre_producto', 'product_name'],
            'precio' => ['precio', 'monto', 'valor', 'price', 'amount', 'total'],
            'estado' => ['estado', 'status', 'situacion', 'condition']
        ];
        
        // Buscar coincidencias
        foreach ($headers as $index => $header) {
            $header = strtolower(trim($header));
            
            foreach ($fieldOptions as $field => $options) {
                // Si ya se encontró este campo, continuar
                if ($mappings[$field] !== null) {
                    continue;
                }
                
                // Verificar si el encabezado coincide con alguna opción
                foreach ($options as $option) {
                    if ($header === $option || strpos($header, $option) !== false) {
                        $mappings[$field] = $index;
                        break 2; // Continuar con el siguiente campo
                    }
                }
            }
        }
        
        // Verificar que todos los campos requeridos tengan un mapeo
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if ($mappings[$field] === null) {
                $missingFields[] = $field;
            }
        }
        
        if (!empty($missingFields)) {
            throw new \Exception('No se encontraron los siguientes campos requeridos: ' . implode(', ', $missingFields));
        }
        
        return $mappings;
    }

    /**
     * Guarda los datos procesados en la base de datos
     *
     * @param array $data
     * @return int Número de registros guardados
     */
    private function saveDataToDatabase(array $data): int
    {
        if (empty($data)) {
            return 0;
        }
        
        // Preparar consulta de inserción
        $tableName = 'MKP_PARIS';
        
        // Verificar si la tabla existe
        $checkTableSql = "SHOW TABLES LIKE '$tableName'";
        $checkTableStmt = $this->dbAdapter->createStatement($checkTableSql);
        $tableExists = $checkTableStmt->execute()->count() > 0;
        
        // Si la tabla no existe, crearla
        if (!$tableExists) {
            $createTableSql = "CREATE TABLE `$tableName` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fecha_creacion DATETIME,
                numero_boleta VARCHAR(50),
                codigo_sku VARCHAR(50),
                nombre_producto VARCHAR(255),
                precio_base DECIMAL(10,2),
                monto_impuesto_boleta DECIMAL(10,2),
                monto_total_boleta DECIMAL(10,2),
                estado VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            
            $createTableStmt = $this->dbAdapter->createStatement($createTableSql);
            $createTableStmt->execute();
        }
        
        // Insertar registros en lotes
        $batchSize = 100;
        $totalInserted = 0;
        
        for ($i = 0; $i < count($data); $i += $batchSize) {
            $batch = array_slice($data, $i, $batchSize);
            
            // Preparar columnas y valores
            $columns = ['fecha_creacion', 'numero_boleta', 'codigo_sku', 'nombre_producto', 
                      'precio_base', 'monto_impuesto_boleta', 'monto_total_boleta', 'estado'];
            
            $valuesSets = [];
            $params = [];
            
            foreach ($batch as $record) {
                // Calcular valores derivados
                $fechaCreacion = isset($record['fecha']) ? date('Y-m-d H:i:s', strtotime($record['fecha'])) : date('Y-m-d H:i:s');
                $numBoleta = $record['boleta'] ?? '';
                $sku = $record['sku'] ?? '';
                $nombreProducto = $record['producto'] ?? '';
                $precioBase = floatval($record['precio'] ?? 0);
                $montoImpuesto = round($precioBase * 0.19, 2); // 19% de IVA
                $montoTotal = $precioBase + $montoImpuesto;
                $estado = $record['estado'] ?? 'Procesado';
                
                $valuesSets[] = "(?, ?, ?, ?, ?, ?, ?, ?)";
                array_push($params, 
                    $fechaCreacion, 
                    $numBoleta, 
                    $sku, 
                    $nombreProducto, 
                    $precioBase, 
                    $montoImpuesto, 
                    $montoTotal, 
                    $estado
                );
            }
            
            // Crear y ejecutar la consulta
            $sql = "INSERT INTO `$tableName` (" . implode(', ', $columns) . ") VALUES " . implode(', ', $valuesSets);
            $statement = $this->dbAdapter->createStatement($sql);
            $result = $statement->execute($params);
            
            $totalInserted += count($batch);
        }
        
        return $totalInserted;
    }

    /**
     * Crea un registro de trabajo para procesamiento en segundo plano
     *
     * @param string $filename
     * @return string ID del trabajo
     */
    private function createProcessingJob(string $filename): string
    {
        $jobId = uniqid('job_');
        
        // Verificar si existe la tabla de trabajos
        $checkTableSql = "SHOW TABLES LIKE 'processing_jobs'";
        $checkTableStmt = $this->dbAdapter->createStatement($checkTableSql);
        $tableExists = $checkTableStmt->execute()->count() > 0;
        
        // Crear la tabla si no existe
        if (!$tableExists) {
            $createTableSql = "CREATE TABLE `processing_jobs` (
                id VARCHAR(50) PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                message TEXT,
                processed_rows INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            
            $createTableStmt = $this->dbAdapter->createStatement($createTableSql);
            $createTableStmt->execute();
        }
        
        // Insertar el trabajo
        $sql = "INSERT INTO processing_jobs (id, filename, status) VALUES (?, ?, 'pending')";
        $statement = $this->dbAdapter->createStatement($sql);
        $statement->execute([$jobId, $filename]);
        
        return $jobId;
    }

    /**
     * Obtiene un mensaje de error para códigos de error de subida de archivos
     *
     * @param int $errorCode
     * @return string
     */
    private function getFileUploadErrorMessage(int $errorCode): string
    {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido por el servidor',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido por el formulario',
            UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente',
            UPLOAD_ERR_NO_FILE => 'No se ha subido ningún archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal del servidor',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en el disco',
            UPLOAD_ERR_EXTENSION => 'Una extensión PHP detuvo la subida del archivo'
        ];
        
        return $errorMessages[$errorCode] ?? 'Error desconocido al subir el archivo';
    }
}