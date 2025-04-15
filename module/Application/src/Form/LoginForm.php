<?php
// module/Application/src/Form/LoginForm.php

namespace Application\Form;

use Laminas\Form\Form;
use Laminas\Form\Element;
use Laminas\InputFilter\InputFilterProviderInterface;

class LoginForm extends Form implements InputFilterProviderInterface
{
    public function __construct($name = null)
    {
        parent::__construct('login');
        
        $this->add([
            'name' => 'username',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Usuario',
            ],
            'attributes' => [
                'id' => 'username',
                'class' => 'form-control',
                'required' => true,
                'autofocus' => true,
            ],
        ]);
        
        $this->add([
            'name' => 'password',
            'type' => Element\Password::class,
            'options' => [
                'label' => 'Contraseña',
            ],
            'attributes' => [
                'id' => 'password',
                'class' => 'form-control',
                'required' => true,
            ],
        ]);
        
        $this->add([
            'name' => 'remember_me',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Recordarme',
                'label_attributes' => [
                    'class' => 'checkbox-inline',
                ],
            ],
            'attributes' => [
                'id' => 'remember_me',
            ],
        ]);
        
        $this->add([
            'name' => 'csrf',
            'type' => Element\Csrf::class,
            'options' => [
                'csrf_options' => [
                    'timeout' => 600,
                ],
            ],
        ]);
    }
    
    public function getInputFilterSpecification()
    {
        return [
            'username' => [
                'required' => true,
                'filters' => [
                    ['name' => 'StringTrim'],
                ],
                'validators' => [
                    [
                        'name' => 'StringLength',
                        'options' => [
                            'min' => 3,
                            'max' => 100,
                            'message' => 'El nombre de usuario debe tener entre 3 y 100 caracteres',
                        ],
                    ],
                ],
            ],
            'password' => [
                'required' => true,
                'validators' => [
                    [
                        'name' => 'StringLength',
                        'options' => [
                            'min' => 6,
                            'message' => 'La contraseña debe tener al menos 6 caracteres',
                        ],
                    ],
                ],
            ],
        ];
    }
}