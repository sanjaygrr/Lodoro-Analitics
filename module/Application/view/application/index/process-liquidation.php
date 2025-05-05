#!/usr/bin/env php
<?php
// process-liquidation.php
// Este script procesa los archivos de liquidación en segundo plano

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'data/logs/process-liquidation.log');

// Verificar si se ejecuta desde línea de comandos
if (php_sapi_name() !== 'cli') {
    die("Este script debe ejecutarse desde la línea de comandos.\n");
}

// Verificar parámetros
if ($argc < 3) {
    die("Uso: php process-liquidation.php <jobId> <filePath>\n");
}

$jobId = $argv[1];
$filePath = $argv[2];

// Establecer directorio de trabajo
chdir(__DIR__);

// Inicializar la aplicación Laminas
require 'vendor/autoload.php';

try {
    $appConfig = require 'config/application.config.php';
    if (file_exists('config/development.config.php')) {
        $devConfig = require 'config/development.config.php';
        $appConfig = Laminas\Stdlib\ArrayUtils::merge($appConfig, $devConfig);
    }

    // Inicializar el Service Manager
    $application = Laminas\Mvc\Application::init($appConfig);
    $serviceManager = $application->getServiceManager();
    
    // Obtener adaptador de base de datos
    $dbAdapter = $serviceManager->get(\Laminas\Db\Adapter\AdapterInterface::class);
    
    // Iniciar transacción
    $connection = $dbAdapter->getDriver()->getConnection();
    $connection->beginTransaction();
    
    try {
        // Verificar que el trabajo existe
        $sql = "SELECT * FROM liquidaciones_jobs WHERE id = ?";
        $statement = $dbAdapter->createStatement($sql);
        $result = $statement->execute([$jobId]);
        $job = $result->current();
        
        if (!$job) {
            throw new Exception("Job ID $jobId no encontrado");
        }
        
        // Verificar que el archivo existe
        if (!file_exists($filePath)) {
            throw new Exception("Archivo no encontrado: $filePath");
        }
        
        // Actualizar estado a "processing"
        $sql = "UPDATE liquidaciones_jobs SET status = 'processing', started_at = NOW() WHERE id = ?";
        $statement = $dbAdapter->createStatement($sql);
        $statement->execute([$jobId]);
        
        // Cargar el archivo Excel
        echo "Cargando archivo Excel...\n";
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        
        // Mapeo de columnas Excel a campos de la tabla
        $columnMap = [
            'B' => 'descripcion',
            'C' => 'tipo', 
            'D' => 'numero_orden',
            'E' => 'sku',
            'F' => 'monto',
            'G' => 'acuerdo_comercial',
            'H' => 'comision',
            'I' => 'monto_a_pagar',
            'J' => 'monto_total_factura',
            'K' => 'fecha',
            'L' => 'estado',
            'M' => 'estado_del_pago',
            'N' => 'nro_solicitud_pago',
            'O' => 'nro_solicitud_factura',
            'P' => 'numero_factura',
            'Q' => 'link_factura',
            'R' => 'fecha_factura',
            'S' => 'categoria',
            'T' => 'nro_suborden',
            'U' => 'nro_solicitud_nota_credito',
            'V' => 'numero_nota_credito',
            'W' => 'link_nota_credito',
            'X' => 'seller_sku',
            'Y' => 'fecha_de_entrega',
            'Z' => 'reputacion',
            'AA' => 'descuento_comercial',
            'AB' => 'tipo_de_transporte'
        ];
        
        // Procesar filas
        $processedRows = 0;
        $errorRows = 0;
        $totalRows = $highestRow - 1; // Excluir encabezado
        $batchSize = 100; // Insertar en lotes de 100 registros
        $batch = [];
        $batchParameters = [];
        
        echo "Procesando $totalRows filas...\n";
        
        for ($row = 2; $row <= $highestRow; $row++) {
            try {
                $data = [];
                
                foreach ($columnMap as $column => $field) {
                    $cellValue = $worksheet->getCell($column . $row)->getValue();
                    
                    // Convertir fechas
                    if (in_array($field, ['fecha', 'fecha_factura', 'fecha_de_entrega'])) {
                        if (is_numeric($cellValue)) {
                            // Fecha Excel
                            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($cellValue);
                            $cellValue = $date->format('Y-m-d');
                        } elseif (!empty($cellValue)) {
                            try {
                                $date = new DateTime($cellValue);
                                $cellValue = $date->format('Y-m-d');
                            } catch (Exception $e) {
                                $cellValue = null;
                            }
                        } else {
                            $cellValue = null;
                        }
                    }
                    
                    // Convertir valores decimales
                    if (in_array($field, ['monto', 'comision', 'monto_a_pagar', 'monto_total_factura', 'descuento_comercial'])) {
                        if (is_string($cellValue)) {
                            // Limpiar formato de moneda
                            $cellValue = preg_replace('/[^0-9.,\-]/', '', $cellValue);
                            $cellValue = str_replace(',', '.', $cellValue);
                        }
                        $cellValue = !empty($cellValue) ? floatval($cellValue) : null;
                    }
                    
                    $data[$field] = $cellValue;
                }
                
                // Agregar a batch
                $placeholders = str_repeat('?,', count($data) - 1) . '?';
                $batch[] = "(" . $placeholders . ")";
                $batchParameters = array_merge($batchParameters, array_values($data));
                
                // Insertar batch si está lleno
                if (count($batch) >= $batchSize) {
                    $this->insertBatch($dbAdapter, $batch, $batchParameters, $data);
                    $batch = [];
                    $batchParameters = [];
                }
                
                $processedRows++;
                
                // Actualizar progreso cada 100 filas
                if ($processedRows % 100 == 0) {
                    $progress = round(($processedRows / $totalRows) * 100);
                    $sql = "UPDATE liquidaciones_jobs SET progress = ? WHERE id = ?";
                    $statement = $dbAdapter->createStatement($sql);
                    $statement->execute([$progress, $jobId]);
                    echo "Progreso: $progress%\n";
                }
                
            } catch (Exception $e) {
                $errorRows++;
                error_log("Error en fila $row: " . $e->getMessage());
                echo "Error en fila $row: " . $e->getMessage() . "\n";
            }
        }
        
        // Insertar batch restante
        if (!empty($batch)) {
            $this->insertBatch($dbAdapter, $batch, $batchParameters, $data);
        }
        
        // Guardar estadísticas
        $sql = "INSERT INTO liquidaciones_import_log (
                    marketplace, 
                    filename, 
                    total_rows, 
                    processed_rows, 
                    error_rows, 
                    import_date,
                    user_id
                ) VALUES (?, ?, ?, ?, ?, NOW(), ?)";
        
        $statement = $dbAdapter->createStatement($sql);
        $statement->execute([
            'PARIS',
            $job['original_filename'],
            $totalRows,
            $processedRows,
            $errorRows,
            $job['user_id']
        ]);
        
        // Confirmar transacción
        $connection->commit();
        
        // Marcar trabajo como completado
        $sql = "UPDATE liquidaciones_jobs SET 
                status = 'completed', 
                progress = 100, 
                completed_at = NOW(), 
                processed_rows = ?,
                error_message = ?
                WHERE id = ?";
        $statement = $dbAdapter->createStatement($sql);
        $errorMessage = $errorRows > 0 ? "Procesado con $errorRows errores" : null;
        $statement->execute([$processedRows, $errorMessage, $jobId]);
        
        echo "Procesamiento completado: $processedRows filas procesadas, $errorRows errores\n";
        
        // Eliminar archivo temporal
        if (file_exists($filePath)) {
            unlink($filePath);
            echo "Archivo temporal eliminado\n";
        }
        
    } catch (Exception $e) {
        $connection->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    $errorLine = $e->getLine();
    $errorFile = $e->getFile();
    
    echo "ERROR: $errorMessage en $errorFile línea $errorLine\n";
    error_log("ERROR: $errorMessage en $errorFile línea $errorLine");
    
    // Marcar trabajo como fallido
    try {
        $sql = "UPDATE liquidaciones_jobs SET 
                status = 'failed', 
                error_message = ?, 
                completed_at = NOW() 
                WHERE id = ?";
        $statement = $dbAdapter->createStatement($sql);
        $statement->execute([$errorMessage, $jobId]);
    } catch (Exception $dbError) {
        error_log("Error al actualizar estado de trabajo: " . $dbError->getMessage());
    }
}

// Función para insertar batch
function insertBatch($dbAdapter, $batch, $parameters, $sampleData) {
    $fields = array_keys($sampleData);
    $sql = "INSERT INTO liquidaciones_paris (`" . implode('`, `', $fields) . "`) VALUES " . implode(',', $batch);
    
    $statement = $dbAdapter->createStatement($sql);
    $statement->execute($parameters);
}