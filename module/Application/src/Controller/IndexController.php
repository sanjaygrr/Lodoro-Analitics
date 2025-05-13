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
}