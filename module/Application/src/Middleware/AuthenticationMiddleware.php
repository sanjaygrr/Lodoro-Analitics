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
        // Check if the user is authenticated
        if (!$this->authService->hasIdentity()) {
            // Store the requested URL to redirect after login
            $uri = $request->getUri();
            $path = $uri->getPath();
            
            $sessionContainer = new Container('Redirect');
            $sessionContainer->redirectUrl = $path;
            
            // Redirect to the login page
            return new RedirectResponse('/login');
        }
        
        // User is authenticated, proceed with the request
        return $handler->handle($request);
    }
}