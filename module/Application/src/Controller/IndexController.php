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

class IndexController extends AbstractActionController
{
    /** @var AdapterInterface */
    private $dbAdapter;

    public function __construct(AdapterInterface $dbAdapter)
    {
        $this->dbAdapter = $dbAdapter;
    }

    /**
     * Acción por defecto: redirige al dashboard.
     */
    public function indexAction()   
    {
        return $this->redirect()->toRoute('application', ['action' => 'dashboard']);
    }

    /**
     * Acción que muestra un dashboard con resumen de todas las tablas.
     */
    public function dashboardAction()
    {
        $sql = "SELECT table_name, engine, table_rows, create_time, update_time 
                FROM information_schema.tables 
                WHERE table_schema = 'dbpgzmb4lvvly0'";
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute();

        $data = [];
        foreach ($result as $row) {
            $data[] = $row;
        }

        return new ViewModel([
            'data' => $data,
        ]);
    }

    /**
     * Acción para mostrar el detalle de una tabla con paginación.
     * Se espera recibir el parámetro 'table' en la URL.
     */
    public function detailAction()
    {
        // Obtener nombre de la tabla desde la ruta
        $table = $this->params()->fromRoute('table', null);
        if (!$table) {
            return $this->redirect()->toRoute('application', ['action' => 'dashboard']);
        }

        // Parámetros de paginación
        $page  = (int) $this->params()->fromQuery('page', 1);
        $limit = (int) $this->params()->fromQuery('limit', 20);
        // Limitar a máximo 50 registros
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
        
        return new ViewModel([
            'table'      => $table,
            'data'       => $data,
            'page'       => $page,
            'limit'      => $limit,
            'totalPages' => $totalPages,
            'total'      => $total,
            'search'     => $search,
            'filters'    => $filters
        ]);
    }
    
    /**
     * Método para exportar a CSV
     */
    private function exportToCsv($table, $whereClause = '', $whereParams = [])
    {
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
}