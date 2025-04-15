<?php
// module/Application/src/Controller/AuthController.php

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Application\Form\LoginForm;

class AuthController extends AbstractActionController
{
    public function loginAction()
    {
        $form = new LoginForm();
        $request = $this->getRequest();
        $error = null;

        if ($request->isPost()) {
            $form->setData($request->getPost());

            if ($form->isValid()) {
                // Simulación simple de login exitoso
                return $this->redirect()->toRoute('dashboard');
            }
        }

        return new ViewModel([
            'form' => $form,
            'error' => $error,
        ]);
    }

    public function logoutAction()
    {
        return $this->redirect()->toRoute('home');
    }
}