<?php
// module/Application/src/Controller/AuthController.php

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Authentication\AuthenticationService;
use Laminas\Authentication\Result;
use Laminas\Session\Container;
use Application\Form\LoginForm;

class AuthController extends AbstractActionController
{
    /**
     * @var AuthenticationService
     */
    private $authService;

    /**
     * Constructor que recibe el servicio de autenticación
     */
    public function __construct(AuthenticationService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Método para manejar el login
     */
    public function loginAction()
    {
        // Verificar si el usuario ya está autenticado
        if ($this->authService->hasIdentity()) {
            // Si ya está autenticado, redireccionar al dashboard
            return $this->redirect()->toRoute('dashboard');
        }

        $form = new LoginForm();
        $request = $this->getRequest();
        $error = null;

        // Verificar si hay una URL de redirección guardada en la sesión
        $sessionContainer = new Container('Redirect');
        $redirectUrl = $sessionContainer->redirectUrl ?? null;

        if ($request->isPost()) {
            $form->setData($request->getPost());

            if ($form->isValid()) {
                $formData = $form->getData();
                
                // Obtener los datos del formulario
                $username = $formData['username'];
                $password = $formData['password'];
                
                // Imprimir las credenciales recibidas para depuración (quitar en producción)
                error_log("Intento de login - Usuario: $username, Contraseña: [OCULTA]");
                
                // IMPORTANTE: Esta es una autenticación muy simple para demostración
                // Se aceptará cualquier usuario/contraseña para fines de prueba
                // En producción, debes usar tu propio sistema de autenticación
                
                // Simular autenticación exitosa
                $isAuthenticated = true;
                
                if ($isAuthenticated) {
                    // Autenticación exitosa
                    // Guardar la identidad del usuario
                    $this->authService->getStorage()->write($username);
                    
                    // Guardar datos adicionales en la sesión
                    $userSession = new Container('user');
                    $userSession->username = $username;
                    $userSession->role = 'Administrador';
                    
                    error_log("Autenticación exitosa para usuario: $username");
                    
                    // Redireccionar a la URL guardada o al dashboard
                    if ($redirectUrl) {
                        // Limpiar la URL guardada
                        $sessionContainer->redirectUrl = null;
                        error_log("Redirigiendo a URL guardada: $redirectUrl");
                        return $this->redirect()->toUrl($redirectUrl);
                    } else {
                        error_log("Redirigiendo al dashboard");
                        return $this->redirect()->toRoute('dashboard');
                    }
                } else {
                    // Autenticación fallida (esto no se ejecutará con la configuración actual)
                    $error = 'Nombre de usuario o contraseña incorrectos';
                    error_log("Autenticación fallida para usuario: $username");
                }
            } else {
                $error = 'Por favor, complete todos los campos correctamente';
                error_log("Formulario inválido en intento de login");
            }
        }

        // Preparar la vista con el formulario de login y mensaje de error
        return new ViewModel([
            'form' => $form,
            'error' => $error,
        ]);
    }

    /**
     * Método para manejar el cierre de sesión
     */
    public function logoutAction()
    {
        // Si el usuario está autenticado, cerrar sesión
        if ($this->authService->hasIdentity()) {
            error_log("Cerrando sesión para usuario: " . $this->authService->getIdentity());
            
            // Limpiar la identidad (cerrar sesión)
            $this->authService->clearIdentity();
            
            // Destruir las sesiones
            $userSession = new Container('user');
            $userSession->getManager()->getStorage()->clear('user');
            
            $redirectSession = new Container('Redirect');
            $redirectSession->getManager()->getStorage()->clear('Redirect');
        } else {
            error_log("Intento de logout sin sesión activa");
        }
        
        // Redirigir al login
        return $this->redirect()->toRoute('login');
    }
}