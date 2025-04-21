<?php
namespace Application\Middleware;

use Laminas\Authentication\AuthenticationService;
use Laminas\Session\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\RedirectResponse;

class AuthenticationMiddleware implements MiddlewareInterface
{
    private $authService;
    
    public function __construct(AuthenticationService $authService)
    {
        $this->authService = $authService;
    }
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Agregar log para depuración
        error_log('AuthenticationMiddleware: Procesando solicitud a: ' . $request->getUri()->getPath());
        
        // Verificar si el usuario está autenticado
        $isAuthenticated = $this->authService->hasIdentity();
        error_log('AuthenticationMiddleware: Usuario autenticado: ' . ($isAuthenticated ? 'SÍ' : 'NO'));
        
        if (!$isAuthenticated) {
            // Guardar la URL solicitada para redirigir después del login
            $uri = $request->getUri();
            $path = $uri->getPath();
            
            $sessionContainer = new Container('Redirect');
            $sessionContainer->redirectUrl = $path;
            error_log('AuthenticationMiddleware: Redirigiendo al login, guardando URL: ' . $path);
            
            // Redirigir a la página de login
            return new RedirectResponse('/login');
        }
        
        // Usuario autenticado, continuar con la solicitud
        error_log('AuthenticationMiddleware: Usuario autenticado, continuando...');
        return $handler->handle($request);
    }
}