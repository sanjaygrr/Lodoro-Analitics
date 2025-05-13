<?php
declare(strict_types=1);

namespace Application\Controller;

use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Laminas\Authentication\AuthenticationService;
use Laminas\Db\Adapter\AdapterInterface;
use Application\Service\DatabaseService;
use Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * Controlador para el escáner de órdenes
 */
class ScanOrdersController extends BaseController
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
     * Acción principal del escáner de órdenes
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
        
        // Obtener estadísticas generales
        $stats = [
            'pendientes' => 0,
            'procesadas' => 0,
            'total' => 0
        ];
        
        // Obtener lista de marketplaces disponibles
        $marketplaces = $this->getAvailableMarketplaces();
        
        // Calcular estadísticas por marketplace
        $marketplaceStats = [];
        
        foreach ($marketplaces as $marketplace) {
            $table = 'Orders_' . $marketplace;
            
            try {
                $result = $this->databaseService->fetchOne(
                    "SELECT
                        COUNT(*) as total,
                        SUM(CASE WHEN procesado = 1 THEN 1 ELSE 0 END) as procesadas,
                        SUM(CASE WHEN procesado = 0 THEN 1 ELSE 0 END) as pendientes
                     FROM `$table`"
                );
                
                if ($result) {
                    $marketplaceStats[$marketplace] = [
                        'total' => (int)($result['total'] ?? 0),
                        'procesadas' => (int)($result['procesadas'] ?? 0),
                        'pendientes' => (int)($result['pendientes'] ?? 0)
                    ];
                    
                    // Actualizar estadísticas globales
                    $stats['total'] += $marketplaceStats[$marketplace]['total'];
                    $stats['procesadas'] += $marketplaceStats[$marketplace]['procesadas'];
                    $stats['pendientes'] += $marketplaceStats[$marketplace]['pendientes'];
                }
            } catch (\Exception $e) {
                // Si hay error, simplemente ignoramos esta tabla
                error_log("Error al consultar tabla $table: " . $e->getMessage());
            }
        }
        
        return new ViewModel([
            'stats' => $stats,
            'marketplaces' => $marketplaces,
            'marketplaceStats' => $marketplaceStats
        ]);
    }

    /**
     * Acción para búsqueda de productos por código EAN/SKU
     * Versión específica para la vista de detalle de orden
     *
     * @return JsonModel
     */
    public function searchEanAction()
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

        // Extraer parámetros necesarios
        $ean = $data['ean'] ?? null;
        $orderId = $data['orderId'] ?? null;
        $table = $data['table'] ?? null;

        if (empty($ean)) {
            return new JsonModel([
                'success' => false,
                'message' => 'Código EAN/SKU requerido'
            ]);
        }

        // Si se proporcionó información específica de la orden
        if ($orderId && $table) {
            try {
                // Buscar el EAN dentro de esta orden específica
                return $this->searchEanInSpecificOrder($ean, $orderId, $table);
            } catch (\Exception $e) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Error al buscar en la orden: ' . $e->getMessage()
                ]);
            }
        }

        // Caso general: buscar en todas las órdenes
        try {
            // Buscar en todas las tablas de órdenes
            $marketplaces = $this->getAvailableMarketplaces();
            $results = [];

            foreach ($marketplaces as $marketplace) {
                $table = 'Orders_' . $marketplace;

                try {
                    // Primero buscar en el campo sku
                    $skuOrders = $this->databaseService->fetchAll(
                        "SELECT * FROM `$table`
                         WHERE sku LIKE ?
                         ORDER BY fecha_creacion DESC
                         LIMIT 5",
                        ['%' . $ean . '%']
                    );

                    if (!empty($skuOrders)) {
                        foreach ($skuOrders as $order) {
                            $results[] = [
                                'marketplace' => $marketplace,
                                'order_id' => $order['id'],
                                'suborder_number' => $order['suborder_number'] ?? 'N/A',
                                'fecha_creacion' => $order['fecha_creacion'] ?? 'N/A',
                                'sku' => $order['sku'] ?? 'N/A',
                                'ean' => $ean,
                                'productName' => 'Producto ' . ($order['sku'] ?? $ean),
                                'estado' => $order['estado'] ?? 'N/A',
                                'procesado' => ($order['procesado'] ?? 0) == 1
                            ];
                        }
                    }

                    // Luego buscar en el campo productos
                    $productOrders = $this->databaseService->fetchAll(
                        "SELECT * FROM `$table`
                         WHERE productos LIKE ?
                         ORDER BY fecha_creacion DESC
                         LIMIT 5",
                        ['%' . $ean . '%']
                    );

                    if (!empty($productOrders)) {
                        foreach ($productOrders as $order) {
                            // Procesar productos para encontrar el EAN específico
                            $productos = $order['productos'];
                            if (!is_array($productos)) {
                                $productos = json_decode($productos, true);
                                if (json_last_error() !== JSON_ERROR_NONE || !is_array($productos)) {
                                    continue;
                                }
                            }

                            // Buscar producto específico con el EAN/SKU
                            foreach ($productos as $producto) {
                                if (isset($producto['sku']) && $producto['sku'] === $ean ||
                                    isset($producto['codigo']) && $producto['codigo'] === $ean ||
                                    isset($producto['ean']) && $producto['ean'] === $ean) {

                                    // Añadir información del producto
                                    $results[] = [
                                        'marketplace' => $marketplace,
                                        'order_id' => $order['id'],
                                        'suborder_number' => $order['suborder_number'] ?? 'N/A',
                                        'fecha_creacion' => $order['fecha_creacion'] ?? 'N/A',
                                        'sku' => $producto['sku'] ?? $ean,
                                        'ean' => $ean,
                                        'productName' => $producto['nombre'] ?? ('Producto ' . ($producto['sku'] ?? $ean)),
                                        'estado' => $order['estado'] ?? 'N/A',
                                        'procesado' => isset($producto['procesado']) && $producto['procesado'] ? true : false
                                    ];
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Ignorar errores por tabla no existente
                    continue;
                }
            }

            // Ordenar resultados por fecha de creación (más recientes primero)
            usort($results, function($a, $b) {
                $dateA = strtotime($a['fecha_creacion']);
                $dateB = strtotime($b['fecha_creacion']);
                return $dateB - $dateA;
            });

            if (empty($results)) {
                return new JsonModel([
                    'success' => true,
                    'found' => false,
                    'message' => 'No se encontraron productos con el código ' . $ean
                ]);
            }

            // Usar el primer resultado como respuesta principal
            $firstResult = reset($results);

            return new JsonModel([
                'success' => true,
                'found' => true,
                'ean' => $ean,
                'sku' => $firstResult['sku'],
                'productName' => $firstResult['productName'],
                'procesado' => $firstResult['procesado']
            ]);
        } catch (\Exception $e) {
            return new JsonModel([
                'success' => false,
                'message' => 'Error al buscar: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Busca un EAN/SKU en una orden específica
     *
     * @param string $ean
     * @param string $orderId
     * @param string $table
     * @return JsonModel
     */
    private function searchEanInSpecificOrder($ean, $orderId, $table)
    {
        // Obtener información de la orden
        $order = $this->databaseService->fetchOne(
            "SELECT * FROM `$table` WHERE id = ?",
            [$orderId]
        );

        if (!$order) {
            return new JsonModel([
                'success' => false,
                'message' => 'Orden no encontrada'
            ]);
        }

        // Verificar si el EAN coincide con el campo SKU
        if (isset($order['sku']) && !empty($order['sku'])) {
            $skus = $order['sku'];
            if (is_string($skus)) {
                // Intentar decodificar como JSON
                $skuArray = json_decode($skus, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Es un array JSON de SKUs
                    foreach ($skuArray as $sku) {
                        if ($sku === $ean) {
                            return new JsonModel([
                                'success' => true,
                                'found' => true,
                                'ean' => $ean,
                                'sku' => $sku,
                                'productName' => 'Producto ' . $sku,
                                'procesado' => ($order['procesado'] ?? 0) == 1
                            ]);
                        }
                    }
                } else {
                    // Si no es JSON, podría ser una lista separada por comas
                    $skuList = explode(',', $skus);
                    foreach ($skuList as $sku) {
                        $sku = trim($sku);
                        if ($sku === $ean) {
                            return new JsonModel([
                                'success' => true,
                                'found' => true,
                                'ean' => $ean,
                                'sku' => $sku,
                                'productName' => 'Producto ' . $sku,
                                'procesado' => ($order['procesado'] ?? 0) == 1
                            ]);
                        }
                    }
                }
            }
        }

        // Verificar en el campo productos
        if (isset($order['productos'])) {
            $productos = $order['productos'];
            if (!is_array($productos)) {
                $productos = json_decode($productos, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($productos)) {
                    foreach ($productos as $index => $producto) {
                        if ((isset($producto['sku']) && $producto['sku'] === $ean) ||
                            (isset($producto['codigo']) && $producto['codigo'] === $ean) ||
                            (isset($producto['ean']) && $producto['ean'] === $ean)) {

                            return new JsonModel([
                                'success' => true,
                                'found' => true,
                                'ean' => $ean,
                                'sku' => $producto['sku'] ?? $ean,
                                'productName' => $producto['nombre'] ?? ('Producto ' . ($producto['sku'] ?? $ean)),
                                'productId' => $index,
                                'procesado' => isset($producto['procesado']) && $producto['procesado'] ? true : false
                            ]);
                        }
                    }
                }
            }
        }

        // No se encontró el EAN en esta orden específica
        return new JsonModel([
            'success' => true,
            'found' => false,
            'message' => 'El código ' . $ean . ' no se encontró en esta orden'
        ]);
    }

    /**
     * Acción para generar código de barras
     *
     * @return \Laminas\Http\Response
     */
    public function generateBarcodeAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
        
        $code = $this->params()->fromQuery('code');
        if (empty($code)) {
            return $this->getResponse()->setStatusCode(400);
        }
        
        // Crear generador de código de barras
        $generator = new BarcodeGeneratorPNG();
        
        // Generar código de barras
        $barcodeType = $this->determineCodeType($code);
        $barcode = $generator->getBarcode($code, $barcodeType);
        
        // Configurar respuesta
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'image/png');
        $response->setContent($barcode);
        
        return $response;
    }

    /**
     * Determina el tipo adecuado de código de barras según el formato
     *
     * @param string $code
     * @return string
     */
    private function determineCodeType(string $code): string
    {
        // Verificar formato del código
        if (strlen($code) == 8 && ctype_digit($code)) {
            // Código EAN-8
            return $generator::TYPE_EAN_8;
        } elseif (strlen($code) == 13 && ctype_digit($code)) {
            // Código EAN-13
            return $generator::TYPE_EAN_13;
        } elseif (ctype_digit($code)) {
            // Código numérico
            return $generator::TYPE_CODE_128;
        } else {
            // Código alfanumérico
            return $generator::TYPE_CODE_128;
        }
    }

    /**
     * Obtiene la lista de marketplaces disponibles
     *
     * @return array
     */
    private function getAvailableMarketplaces(): array
    {
        // Lista predefinida de marketplaces
        return [
            'WALLMART',
            'RIPLEY',
            'FALABELLA',
            'MERCADO_LIBRE',
            'PARIS',
            'WOOCOMMERCE'
        ];
    }
}