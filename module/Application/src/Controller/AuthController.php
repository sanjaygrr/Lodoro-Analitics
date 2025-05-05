<?php
// module/Application/src/Controller/AuthController.php

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Authentication\AuthenticationService;
use Laminas\Session\Container;
use Application\Form\LoginForm;
use Application\Model\UserTable;

class AuthController extends AbstractActionController
{
    /**
     * @var AuthenticationService
     */
    private $authService;

    /**
     * @var UserTable
     */
    private $userTable;

    /**
     * Constructor que recibe los servicios necesarios
     */
    public function __construct(AuthenticationService $authService, UserTable $userTable)
    {
        $this->authService = $authService;
        $this->userTable = $userTable;
    }

    /**
     * Método para manejar el login
     */
    public function loginAction()
    {
        // Verificar si el usuario ya está autenticado
        if ($this->authService->hasIdentity()) {
            return $this->redirect()->toRoute('dashboard');
        }

        $form = new LoginForm();
        $request = $this->getRequest();
        $error = null;

        $sessionContainer = new Container('Redirect');
        $redirectUrl = $sessionContainer->redirectUrl ?? null;

        if ($request->isPost()) {
            $form->setData($request->getPost());

            if ($form->isValid()) {
                $formData = $form->getData();
                
                $username = $formData['username'];
                $password = $formData['password'];
                
                // Verificar credenciales contra la base de datos
                if ($this->userTable->verifyCredentials($username, $password)) {
                    // Obtener los datos del usuario
                    $user = $this->userTable->getUserByUsername($username);
                    
                    // Autenticación exitosa
                    $this->authService->getStorage()->write($username);
                    
                    // Guardar datos adicionales en la sesión
                    $userSession = new Container('user');
                    $userSession->username = $username;
                    $userSession->role = $user->role;
                    $userSession->email = $user->email;
                    
                    // Redireccionar
                    if ($redirectUrl) {
                        $sessionContainer->redirectUrl = null;
                        return $this->redirect()->toUrl($redirectUrl);
                    } else {
                        return $this->redirect()->toRoute('dashboard');
                    }
                } else {
                    $error = 'Nombre de usuario o contraseña incorrectos';
                }
            } else {
                $error = 'Por favor, complete todos los campos correctamente';
            }
        }

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
            // Limpiar la identidad (cerrar sesión)
            $this->authService->clearIdentity();
            
            // Destruir las sesiones
            $userSession = new Container('user');
            $userSession->getManager()->getStorage()->clear('user');
            
            $redirectSession = new Container('Redirect');
            $redirectSession->getManager()->getStorage()->clear('Redirect');
        }
        
        // Redirigir al login
        return $this->redirect()->toRoute('login');
    }
}