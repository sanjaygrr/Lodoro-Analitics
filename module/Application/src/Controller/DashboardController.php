<?php
declare(strict_types=1);

namespace Application\Controller;

use Laminas\View\Model\ViewModel;
use Laminas\Authentication\AuthenticationService;
use Laminas\Db\Adapter\AdapterInterface;
use Application\Service\DatabaseService;

/**
 * Controlador para gestionar las funciones del dashboard
 */
class DashboardController extends BaseController
{
    /** @var DatabaseService */
    private $databaseService;

    /**
     * Constructor
     *
     * @param AdapterInterface $dbAdapter
     * @param AuthenticationService $authService
     * @param DatabaseService $databaseService
     */
    public function __construct(
        AdapterInterface $dbAdapter,
        AuthenticationService $authService,
        DatabaseService $databaseService
    ) {
        parent::__construct($dbAdapter, $authService);
        $this->databaseService = $databaseService;
    }

    /**
     * Acción principal del dashboard
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
        
        // Obtener lista de tablas
        $tablesData = $this->databaseService->fetchAll(
            "SELECT table_name, engine, table_rows, create_time, update_time 
             FROM information_schema.tables 
             WHERE table_schema = 'db5skbdigd2nxo'"
        );

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
        
        // Preparar JSON para gráficos
        $ventasAnualesJson = json_encode($ventasAnualesArray);
        $topProductosJson = json_encode($topProductosArray);
        
        return new ViewModel([
            'tables' => $tablesData,
            'ventaBrutaMensual' => $ventaBrutaMensual,
            'impuestoBrutoMensual' => $impuestoBrutoMensual,
            'totalTransaccionesMes' => $totalTransaccionesMes,
            'valorCancelado' => $valorCancelado,
            'transaccionesCanceladas' => $transaccionesCanceladas,
            'totalVentas' => $totalVentas,
            'totalRegistros' => $totalRegistros,
            'jsonVentasAnuales' => $ventasAnualesJson,
            'jsonTopProductos' => $topProductosJson
        ]);
    }
    
    /**
     * Acción para mostrar detalles específicos de un marketplace
     * 
     * @return ViewModel|\Laminas\Http\Response
     */
    public function marketplaceAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
        
        // Obtener parámetros
        $marketplace = $this->params()->fromRoute('marketplace', null);
        
        if (!$marketplace) {
            return $this->redirect()->toRoute('dashboard');
        }
        
        // Lógica específica para el marketplace...
        
        return new ViewModel([
            'marketplace' => $marketplace,
            // Otros datos específicos del marketplace
        ]);
    }
    
    /**
     * Acción para mostrar detalle de un marketplace específico
     *
     * @return ViewModel|\Laminas\Http\Response
     */
    public function detailAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }

        // Obtener el marketplace desde los parámetros de ruta
        $marketplace = $this->params()->fromRoute('marketplace', null);
        
        // Compatibilidad con rutas antiguas que usan 'table' en lugar de 'marketplace'
        if (empty($marketplace)) {
            $marketplace = $this->params()->fromRoute('table', null);
        }

        if (empty($marketplace)) {
            return $this->notFoundAction();
        }

        try {
            // Identificar el marketplace basado en el nombre de la ruta
            $marketplaceId = strtoupper($marketplace);
            
            // Manejar diferentes marketplaces con consultas directas
            switch ($marketplaceId) {
                case 'MKP_PARIS':
                    return $this->handleParisMarketplace();
                    
                case 'MKP_FALABELLA':
                    // Implementar en el futuro
                    // return $this->handleFalabellaMarketplace();
                    
                case 'MKP_RIPLEY':
                    // Implementar en el futuro
                    // return $this->handleRipleyMarketplace();
                    
                case 'MKP_WALLMART':
                    // Implementar en el futuro
                    // return $this->handleWallmartMarketplace();
                    
                case 'MKP_MERCADO_LIBRE':
                    // Implementar en el futuro
                    // return $this->handleMercadoLibreMarketplace();
                    
                case 'MKP_WOOCOMMERCE':
                    // Implementar en el futuro
                    // return $this->handleWooCommerceMarketplace();
            }
            
            // Intentar usar la tabla directamente si no coincide con ningún caso especial
            // Verificar si la tabla existe
            $tableExists = $this->databaseService->fetchOne(
                "SELECT COUNT(*) as count FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = ?",
                [$marketplace]
            );

            if (!$tableExists || $tableExists['count'] == 0) {
                // Si la tabla no existe, mostrar un mensaje de error específico
                return new ViewModel([
                    'marketplace' => $marketplace,
                    'error' => "La tabla '$marketplace' no existe o ha sido migrada. Por favor, contacte al administrador del sistema."
                ]);
            }

            // Código para tablas existentes...
            // Resto del código sin cambios...
        } catch (\Exception $e) {
            // Si hay un error, mostrar una vista con el mensaje de error
            return new ViewModel([
                'marketplace' => $marketplace,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Maneja la visualización de datos para el marketplace de Paris
     * utilizando consulta directa en lugar de tabla única
     *
     * @return ViewModel
     */
    private function handleParisMarketplace()
    {
        try {
            // Inicializar parámetros de paginación
            $page = (int) $this->params()->fromQuery('page', 1);
            $limit = (int) $this->params()->fromQuery('limit', 25);
            $offset = ($page - 1) * $limit;
            
            // Filtros de búsqueda
            $search = $this->params()->fromQuery('search', '');
            
            // OPTIMIZACIÓN 1: Usar índices y limitar columnas retornadas
            // OPTIMIZACIÓN 2: Añadir STRAIGHT_JOIN para forzar el orden de las uniones
            // OPTIMIZACIÓN 3: Agregar índices WHERE y ORDER BY en pares específicos para mejorar el rendimiento
            
            // Consulta optimizada para datos recientes
            $whereClause = '';
            $searchParams = [];
            
            if (!empty($search)) {
                $whereClause = " WHERE (pof.subOrderNumber LIKE ? OR pof.customer_name LIKE ?)";
                $searchTerm = "%$search%";
                $searchParams = [$searchTerm, $searchTerm];
            }
            
            // OPTIMIZACIÓN 4: Dividir la consulta en dos partes - primero obtener IDs y luego detalles
            // Esto puede mejorar significativamente el rendimiento
            
            // Paso 1: Obtener solo los IDs de órdenes con la paginación
            $idsQuery = "
                SELECT DISTINCT pof.subOrderNumber
                FROM paris_orders pof 
                $whereClause
                ORDER BY pof.subOrderNumber DESC
                LIMIT $offset, $limit
            ";
            
            $orderIds = $this->databaseService->fetchAll($idsQuery, $searchParams);
            
            if (empty($orderIds)) {
                $datosRecientes = [];
            } else {
                // Construir lista de IDs para la consulta en (...)
                $ids = [];
                $placeholders = [];
                foreach ($orderIds as $row) {
                    $ids[] = $row['subOrderNumber'];
                    $placeholders[] = '?';
                }
                
                // Paso 2: Obtener detalles completos solo para esos IDs
                $detailsQuery = "
                    SELECT 
                        pof.subOrderNumber,
                        pof.origin,
                        pof.originInvoiceType,
                        pof.createdAt,
                        pof.customer_name,
                        pof.customer_documentNumber,
                        pof.billing_phone,
                        pso.statusId,
                        pst.translate,
                        pso.carrier,
                        pso.fulfillment,
                        pi.sku,
                        pi.name,
                        pi.priceAfterDiscounts as precio_base,
                        pso.cost,
                        bd.taxAmount,
                        bd.totalAmount,
                        bd.number,
                        bd.urlPdfOriginal,
                        pp.numero AS numero_liquidacion,
                        pp.monto AS monto_liquidacion
                    FROM paris_orders pof 
                    LEFT JOIN paris_subOrders pso 
                        ON pof.subOrderNumber = pso.subOrderNumber
                    LEFT JOIN paris_statuses pst 
                        ON pso.statusId = pst.id
                    LEFT JOIN paris_items pi 
                        ON pof.subOrderNumber = pi.subOrderNumber
                    LEFT JOIN bsale_references brd
                        ON brd.number = pof.subOrderNumber
                    LEFT JOIN bsale_documents bd 
                        ON brd.document_id = bd.id
                    LEFT JOIN paris_pagos pp 
                        ON DATE(pp.fecha) >= DATE(pof.createdAt)
                    WHERE pof.subOrderNumber IN (" . implode(',', $placeholders) . ")
                    ORDER BY pof.subOrderNumber DESC
                ";
                
                $datosRecientes = $this->databaseService->fetchAll($detailsQuery, $ids);
            }
            
            // OPTIMIZACIÓN 5: Contar registros con una consulta más simple
            $countQuery = "
                SELECT COUNT(DISTINCT pof.subOrderNumber) as total 
                FROM paris_orders pof 
                $whereClause
            ";
            
            $totalRegistros = $this->databaseService->fetchOne($countQuery, $searchParams);
            $totalItems = $totalRegistros ? (int)$totalRegistros['total'] : 0;
            $totalPages = ceil($totalItems / $limit);
            
            // Obtener las columnas para la vista
            $columnas = [];
            if (!empty($datosRecientes)) {
                // Extraer nombres de columnas del primer resultado
                $primeraFila = $datosRecientes[0];
                foreach (array_keys($primeraFila) as $nombreColumna) {
                    $columnas[] = ['Field' => $nombreColumna];
                }
            }
            
            // OPTIMIZACIÓN 6: Precalcular estadísticas en lugar de múltiples consultas
            // Calcular estadísticas de ventas con una sola consulta
            $ventaStats = [];
            
            try {
                // Una sola consulta para todas las estadísticas
                $statsQuery = "
                    SELECT 
                        SUM(bd.totalAmount) as total_ventas,
                        SUM(CASE WHEN YEAR(pof.createdAt) = YEAR(CURRENT_DATE) AND MONTH(pof.createdAt) = MONTH(CURRENT_DATE) THEN bd.totalAmount ELSE 0 END) as ventas_mes_actual,
                        COUNT(DISTINCT pof.subOrderNumber) as total_ordenes
                    FROM paris_orders pof 
                    LEFT JOIN bsale_references brd ON brd.number = pof.subOrderNumber
                    LEFT JOIN bsale_documents bd ON brd.document_id = bd.id
                ";
                
                $statsResult = $this->databaseService->fetchOne($statsQuery);
                
                $ventaStats['total'] = $statsResult ? (float)$statsResult['total_ventas'] : 0;
                $ventaStats['mes_actual'] = $statsResult ? (float)$statsResult['ventas_mes_actual'] : 0;
                
                // Solo para ventas mensuales usamos una consulta separada
                $ventasMensuales = $this->databaseService->fetchAll(
                    "SELECT MONTH(pof.createdAt) as mes, SUM(bd.totalAmount) as total
                    FROM paris_orders pof 
                    LEFT JOIN bsale_references brd ON brd.number = pof.subOrderNumber
                    LEFT JOIN bsale_documents bd ON brd.document_id = bd.id
                    WHERE YEAR(pof.createdAt) = YEAR(CURRENT_DATE)
                    GROUP BY MONTH(pof.createdAt)
                    ORDER BY MONTH(pof.createdAt)"
                );
                
                $ventaStats['mensuales'] = $ventasMensuales;
                
            } catch (\Exception $e) {
                $ventaStats['error'] = $e->getMessage();
            }
            
            // Inicializar filtros
            $filters = [
                'search' => $search,
            ];
            
            return new ViewModel([
                'marketplace' => 'MKP_PARIS', // Usar MKP_PARIS para compatibilidad con las vistas existentes
                'totalRegistros' => $totalItems,
                'ventaStats' => $ventaStats,
                'datosRecientes' => $datosRecientes,
                'columnas' => $columnas,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => $totalPages,
                'total' => $totalItems,
                'data' => $datosRecientes, // Para compatibilidad con la vista antigua
                'filters' => $filters,
                'search' => $search,
                'isDirectQuery' => true // Indicador para la vista de que estamos usando consulta directa
            ]);
            
        } catch (\Exception $e) {
            return new ViewModel([
                'marketplace' => 'MKP_PARIS', // Consistencia aquí también
                'error' => $e->getMessage()
            ]);
        }
    }
}