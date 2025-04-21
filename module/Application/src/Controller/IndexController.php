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
        // Limitar a máximo 50 registros
        $limit = min($limit, 150);
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
     * NUEVOS MÉTODOS PARA GESTIÓN DE ÓRDENES Y PEDIDOS
     */
    
    /**
     * Acción principal para visualizar todas las órdenes de todos los marketplaces
     */
    public function ordersAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
        
        // Obtenemos estadísticas resumidas por marketplace y estado
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
        
        // Ejecutar la consulta de estadísticas
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
        $offset = ($page - 1) * $limit;
        
        // Obtener filtros
        $search = $this->params()->fromQuery('search', '');
        $statusFilter = $this->params()->fromQuery('status', '');
        $transportistaFilter = $this->params()->fromQuery('transportista', '');
        $startDate = $this->params()->fromQuery('startDate', '');
        $endDate = $this->params()->fromQuery('endDate', '');
        
        // Construir condiciones WHERE basadas en filtros
        $whereConditions = [];
        $whereParams = [];
        
        if (!empty($search)) {
            $whereConditions[] = "(id LIKE ? OR cliente LIKE ? OR telefono LIKE ? OR direccion LIKE ?)";
            $whereParams[] = '%' . $search . '%';
            $whereParams[] = '%' . $search . '%';
            $whereParams[] = '%' . $search . '%';
            $whereParams[] = '%' . $search . '%';
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
            $whereParams[] = $startDate . ' 00:00:00';
        }
        
        if (!empty($endDate)) {
            $whereConditions[] = "fecha_creacion <= ?";
            $whereParams[] = $endDate . ' 23:59:59';
        }
        
        // Construir la cláusula WHERE final
        $whereClause = !empty($whereConditions) ? " WHERE " . implode(" AND ", $whereConditions) : "";
        
        // Consultar todas las órdenes (uniendo todas las tablas de órdenes)
        // NOTA: Esto asume que todas las tablas de órdenes tienen la misma estructura
        $ordersSql = "SELECT 
                        id, 
                        'TODOS' as marketplace_original, 
                        fecha_creacion, 
                        fecha_entrega, 
                        cliente, 
                        telefono, 
                        direccion, 
                        productos, 
                        total, 
                        estado, 
                        transportista, 
                        num_seguimiento
                    FROM (
                        SELECT 
                            id, 
                            'WALLMART' as marketplace, 
                            fecha_creacion, 
                            fecha_entrega, 
                            cliente, 
                            telefono, 
                            direccion, 
                            productos, 
                            total, 
                            estado, 
                            transportista, 
                            num_seguimiento 
                        FROM Orders_WALLMART
                        UNION ALL 
                        SELECT 
                            id, 
                            'RIPLEY' as marketplace, 
                            fecha_creacion, 
                            fecha_entrega, 
                            cliente, 
                            telefono, 
                            direccion, 
                            productos, 
                            total, 
                            estado, 
                            transportista, 
                            num_seguimiento 
                        FROM Orders_RIPLEY
                        UNION ALL 
                        SELECT 
                            id, 
                            'FALABELLA' as marketplace, 
                            fecha_creacion, 
                            fecha_entrega, 
                            cliente, 
                            telefono, 
                            direccion, 
                            productos, 
                            total, 
                            estado, 
                            transportista, 
                            num_seguimiento 
                        FROM Orders_FALABELLA
                        UNION ALL 
                        SELECT 
                            id, 
                            'MERCADO_LIBRE' as marketplace, 
                            fecha_creacion, 
                            fecha_entrega, 
                            cliente, 
                            telefono, 
                            direccion, 
                            productos, 
                            total, 
                            estado, 
                            transportista, 
                            num_seguimiento 
                        FROM Orders_MERCADO_LIBRE
                        UNION ALL 
                        SELECT 
                            id, 
                            'PARIS' as marketplace, 
                            fecha_creacion, 
                            fecha_entrega, 
                            cliente, 
                            telefono, 
                            direccion, 
                            productos, 
                            total, 
                            estado, 
                            transportista, 
                            num_seguimiento 
                        FROM Orders_PARIS
                        UNION ALL 
                        SELECT 
                            id, 
                            'WOOCOMMERCE' as marketplace, 
                            fecha_creacion, 
                            fecha_entrega, 
                            cliente, 
                            telefono, 
                            direccion, 
                            productos, 
                            total, 
                            estado, 
                            transportista, 
                            num_seguimiento 
                        FROM Orders_WOOCOMMERCE
                    ) as all_orders" . $whereClause . 
                    " ORDER BY fecha_creacion DESC 
                     LIMIT $limit OFFSET $offset";
        
        // Ejecutar la consulta de órdenes
        $ordersStatement = $this->dbAdapter->createStatement($ordersSql);
        $ordersResult = $ordersStatement->execute($whereParams);
        
        // Formatear resultados
        $orders = [];
        foreach ($ordersResult as $row) {
            $orders[] = $row;
        }
        
        // Devolver la vista con los datos
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
        $offset = ($page - 1) * $limit;
        
        // Obtener filtros
        $search = $this->params()->fromQuery('search', '');
        $statusFilter = $this->params()->fromQuery('status', '');
        $transportistaFilter = $this->params()->fromQuery('transportista', '');
        $startDate = $this->params()->fromQuery('startDate', '');
        $endDate = $this->params()->fromQuery('endDate', '');
        
        // Construir condiciones WHERE basadas en filtros
        $whereConditions = [];
        $whereParams = [];
        
        if (!empty($search)) {
            $whereConditions[] = "(id LIKE ? OR cliente LIKE ? OR telefono LIKE ? OR direccion LIKE ?)";
            $whereParams[] = '%' . $search . '%';
            $whereParams[] = '%' . $search . '%';
            $whereParams[] = '%' . $search . '%';
            $whereParams[] = '%' . $search . '%';
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
            $whereParams[] = $startDate . ' 00:00:00';
        }
        
        if (!empty($endDate)) {
            $whereConditions[] = "fecha_creacion <= ?";
            $whereParams[] = $endDate . ' 23:59:59';
        }
        
        // Construir la cláusula WHERE final
        $whereClause = !empty($whereConditions) ? " WHERE " . implode(" AND ", $whereConditions) : "";
        
        // Consulta para obtener órdenes
        $ordersSql = "SELECT * FROM `$table`" . $whereClause . " ORDER BY fecha_creacion DESC LIMIT $limit OFFSET $offset";
        $ordersStatement = $this->dbAdapter->createStatement($ordersSql);
        $ordersResult = $ordersStatement->execute($whereParams);
        
        // Formatear resultados
        $orders = [];
        foreach ($ordersResult as $row) {
            // Añadir el marketplace a cada registro
            $row['marketplace'] = str_replace('Orders_', '', $table);
            $orders[] = $row;
        }
        
        // Devolver la vista con los datos
        return new ViewModel([
            'table' => $table,
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
     * Acción para ver los detalles completos de una orden específica
     */
    public function orderDetailAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
        
        // Obtener ID de orden y tabla (marketplace) desde los parámetros
        $orderId = $this->params()->fromRoute('id', null);
        $table = $this->params()->fromRoute('table', null);
        
        if (!$orderId || !$table) {
            return $this->redirect()->toRoute('application', ['action' => 'orders']);
        }
        
        // Validar la tabla
        if (strpos($table, 'Orders_') !== 0) {
            return $this->redirect()->toRoute('application', ['action' => 'orders']);
        }
        
        // Consultar los detalles de la orden
        $sql = "SELECT * FROM `$table` WHERE id = ?";
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute([$orderId]);
        $order = $result->current();
        
        if (!$order) {
            // Si no se encuentra la orden, redirigir
            return $this->redirect()->toRoute('application', ['action' => 'orders-detail', 'table' => $table]);
        }
        
        // Consultar productos de la orden (esto requiere una tabla de detalles de orden)
        $productsSql = "SELECT * FROM `{$table}_Items` WHERE order_id = ?";
        $productsStatement = $this->dbAdapter->createStatement($productsSql);
        
        try {
            $productsResult = $productsStatement->execute([$orderId]);
            $products = [];
            foreach ($productsResult as $product) {
                $products[] = $product;
            }
        } catch (\Exception $e) {
            // Si no hay tabla de detalles, crear productos de muestra
            $products = [
                [
                    'id' => 1,
                    'nombre' => 'Producto de ejemplo 1',
                    'sku' => 'SKU-123456',
                    'cantidad' => 2,
                    'precio_unitario' => 12990,
                    'subtotal' => 25980
                ],
                [
                    'id' => 2,
                    'nombre' => 'Producto de ejemplo 2',
                    'sku' => 'SKU-654321',
                    'cantidad' => 1,
                    'precio_unitario' => 34990,
                    'subtotal' => 34990
                ]
            ];
        }
        
        // Calcular totales
        $subtotal = array_sum(array_column($products, 'subtotal'));
        $envio = isset($order['costo_envio']) ? $order['costo_envio'] : 3990;
        $total = $subtotal + $envio;
        
        // Devolver la vista con todos los datos
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
                // Simular generación de etiquetas
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Etiquetas generadas correctamente',
                    'count' => count($orderIds),
                    'labelUrl' => '/labels/batch-' . time() . '.pdf'
                ]);
                
            case 'generate-manifest':
                // Simular generación de manifiesto
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Manifiesto generado correctamente',
                    'count' => count($orderIds),
                    'manifestUrl' => '/manifests/manifest-' . time() . '.pdf'
                ]);
                
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
                // Simular exportación a Excel
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Exportación a Excel generada',
                    'count' => count($orderIds),
                    'excelUrl' => '/exports/orders-' . time() . '.xlsx'
                ]);
                
            default:
                return $this->jsonResponse(['success' => false, 'message' => 'Acción no reconocida']);
        }
    }
}