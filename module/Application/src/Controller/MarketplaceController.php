<?php
declare(strict_types=1);

namespace Application\Controller;

use Laminas\View\Model\ViewModel;
use Laminas\Http\Client;
use Laminas\Authentication\AuthenticationService;
use Laminas\Db\Adapter\AdapterInterface;
use Application\Service\DatabaseService;

/**
 * Controlador para gestionar configuraciones de marketplaces
 */
class MarketplaceController extends BaseController
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
     * Acción para la configuración de marketplaces
     *
     * @return ViewModel|\Laminas\Http\Response
     */
    public function configAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
        
        // Variables para la vista
        $message = '';
        $messageType = 'info';
        
        // Obtener configuraciones existentes
        $configsArray = $this->databaseService->fetchAll(
            "SELECT * FROM api_config ORDER BY marketplace"
        );
        
        // Procesar formulario si es POST
        if ($this->getRequest()->isPost()) {
            $postData = $this->params()->fromPost();
            
            if (isset($postData['id']) && $postData['id'] > 0) {
                // Actualizar registro existente
                $this->databaseService->execute(
                    "UPDATE api_config SET 
                     api_url = ?, 
                     api_key = ?, 
                     accesstoken = ?, 
                     offset = ?, 
                     marketplace = ?, 
                     update_at = NOW() 
                     WHERE id = ?",
                    [
                        $postData['api_url'],
                        $postData['api_key'],
                        $postData['accesstoken'] ?? null,
                        (int)($postData['offset'] ?? 0),
                        $postData['marketplace'],
                        $postData['id']
                    ]
                );
                
                $message = 'Configuración actualizada correctamente';
                $messageType = 'success';
            } else {
                // Insertar nuevo registro
                $this->databaseService->execute(
                    "INSERT INTO api_config 
                     (api_url, api_key, accesstoken, offset, marketplace, created_at, update_at) 
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        $postData['api_url'],
                        $postData['api_key'],
                        $postData['accesstoken'] ?? null,
                        (int)($postData['offset'] ?? 0),
                        $postData['marketplace']
                    ]
                );
                
                $message = 'Nueva configuración creada correctamente';
                $messageType = 'success';
            }
            
            // Recargar datos después de la operación
            $configsArray = $this->databaseService->fetchAll(
                "SELECT * FROM api_config ORDER BY marketplace"
            );
        }
        
        // Procesar eliminación si se solicita
        $deleteId = $this->params()->fromQuery('delete', null);
        if ($deleteId) {
            $this->databaseService->execute(
                "DELETE FROM api_config WHERE id = ?",
                [$deleteId]
            );
            
            $message = 'Configuración eliminada correctamente';
            $messageType = 'success';
            
            // Redireccionar para evitar problemas con F5
            return $this->redirect()->toRoute('marketplace', ['action' => 'config']);
        }
        
        return new ViewModel([
            'configs' => $configsArray,
            'message' => $message,
            'messageType' => $messageType
        ]);
    }

    /**
     * Método para probar la conexión con un marketplace
     *
     * @return \Laminas\Http\Response
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
            return $this->jsonResponse([
                'success' => false, 
                'message' => 'ID de configuración no proporcionado'
            ]);
        }
        
        // Obtener la configuración de la API
        $config = $this->databaseService->fetchOne(
            "SELECT * FROM api_config WHERE id = ?",
            [$id]
        );
        
        if (!$config) {
            return $this->jsonResponse([
                'success' => false, 
                'message' => 'Configuración no encontrada'
            ]);
        }
        
        try {
            // Crear cliente HTTP
            $client = new Client();
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
     * Acción para sincronizar datos con un marketplace
     *
     * @return \Laminas\Http\Response
     */
    public function syncAction()
    {
        // Verificar autenticación
        $redirect = $this->checkAuth();
        if ($redirect !== null) {
            return $redirect;
        }
        
        $id = $this->params()->fromQuery('id');
        if (!$id) {
            return $this->jsonResponse([
                'success' => false, 
                'message' => 'ID de configuración no proporcionado'
            ]);
        }
        
        // Obtener la configuración de la API
        $config = $this->databaseService->fetchOne(
            "SELECT * FROM api_config WHERE id = ?",
            [$id]
        );
        
        if (!$config) {
            return $this->jsonResponse([
                'success' => false, 
                'message' => 'Configuración no encontrada'
            ]);
        }
        
        // Aquí iría la lógica para sincronizar datos con el marketplace
        // Esta es una implementación simplificada
        
        try {
            // Simulamos una sincronización exitosa
            return $this->jsonResponse([
                'success' => true,
                'message' => 'Sincronización iniciada correctamente',
                'jobId' => uniqid('sync_')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Error al iniciar la sincronización: ' . $e->getMessage()
            ]);
        }
    }
}