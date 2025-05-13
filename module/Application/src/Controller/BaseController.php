<?php
declare(strict_types=1);

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Authentication\AuthenticationService;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\View\Model\JsonModel;

/**
 * Controlador base para todos los controladores de la aplicación
 * 
 * Proporciona funcionalidades comunes como verificación de autenticación,
 * respuestas JSON y acceso a la base de datos
 */
class BaseController extends AbstractActionController
{
    /** @var AdapterInterface */
    protected $dbAdapter;
    
    /** @var AuthenticationService */
    protected $authService;

    /**
     * Constructor
     *
     * @param AdapterInterface $dbAdapter
     * @param AuthenticationService $authService
     */
    public function __construct(AdapterInterface $dbAdapter, AuthenticationService $authService)
    {
        $this->dbAdapter = $dbAdapter;
        $this->authService = $authService;
    }
    
    /**
     * Método para verificar si el usuario está autenticado
     * Si no lo está, redirige al login
     *
     * @return null|\Laminas\Http\Response
     */
    protected function checkAuth()
    {
        if (!$this->authService->hasIdentity()) {
            return $this->redirect()->toRoute('login');
        }
        
        return null; // Continuar si está autenticado
    }
    
    /**
     * Método para crear respuestas JSON
     *
     * @param array $data
     * @return \Laminas\Http\Response
     */
    protected function jsonResponse($data)
    {
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $response->setContent(json_encode($data));
        return $response;
    }
    
    /**
     * Método para crear un JsonModel
     *
     * @param array $data
     * @return JsonModel
     */
    protected function createJsonModel(array $data): JsonModel
    {
        return new JsonModel($data);
    }
    
    /**
     * Ejecuta una consulta SQL y devuelve todos los resultados como array
     *
     * @param string $sql
     * @param array $params
     * @return array
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute($params);
        
        $resultArray = [];
        foreach ($result as $row) {
            $resultArray[] = $row;
        }
        
        return $resultArray;
    }
    
    /**
     * Ejecuta una consulta SQL y devuelve una fila
     *
     * @param string $sql
     * @param array $params
     * @return array|null
     */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute($params);
        
        if ($result->count() > 0) {
            return $result->current();
        }
        
        return null;
    }
    
    /**
     * Ejecuta una consulta de tipo INSERT, UPDATE o DELETE
     *
     * @param string $sql
     * @param array $params
     * @return int Número de filas afectadas
     */
    protected function execute(string $sql, array $params = []): int
    {
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute($params);
        
        return $result->getAffectedRows();
    }
}