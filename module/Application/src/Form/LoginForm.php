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
        parent::__construct('login-form');
        
        // Configuración del formulario
        $this->setAttribute('method', 'post');
        $this->setAttribute('class', 'login-form');
        
        // Campo de nombre de usuario
        $this->add([
            'name' => 'username',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Nombre de usuario',
            ],
            'attributes' => [
                'id' => 'username',
                'class' => 'form-control',
                'placeholder' => 'Ingrese su nombre de usuario',
                'required' => true,
                'autofocus' => true,
            ],
        ]);
        
        // Campo de contraseña
        $this->add([
            'name' => 'password',
            'type' => Element\Password::class,
            'options' => [
                'label' => 'Contraseña',
            ],
            'attributes' => [
                'id' => 'password',
                'class' => 'form-control',
                'placeholder' => 'Ingrese su contraseña',
                'required' => true,
            ],
        ]);
        
        // Checkbox de recordar sesión
        $this->add([
            'name' => 'remember_me',  // Nombre correcto según tu vista
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Recordarme',
                'label_attributes' => [
                    'class' => 'form-check-label',
                ],
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'id' => 'remember_me',
                'class' => 'form-check-input',
            ],
        ]);
        
        // Botón de envío
        $this->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
            'attributes' => [
                'value' => 'Iniciar sesión',
                'class' => 'login-btn',
            ],
        ]);
    }
    
    /**
     * Especificar las reglas de validación del formulario
     */
    public function getInputFilterSpecification()
    {
        return [
            'username' => [
                'required' => true,
                'filters' => [
                    ['name' => 'StripTags'],
                    ['name' => 'StringTrim'],
                ],
                'validators' => [
                    [
                        'name' => 'StringLength',
                        'options' => [
                            'min' => 3,
                            'max' => 50,
                            'messages' => [
                                'stringLengthTooShort' => 'El nombre de usuario debe tener al menos %min% caracteres',
                                'stringLengthTooLong' => 'El nombre de usuario no puede tener más de %max% caracteres',
                            ],
                        ],
                    ],
                ],
            ],
            'password' => [
                'required' => true,
                'filters' => [
                    ['name' => 'StringTrim'],
                ],
                'validators' => [
                    [
                        'name' => 'StringLength',
                        'options' => [
                            'min' => 6,
                            'messages' => [
                                'stringLengthTooShort' => 'La contraseña debe tener al menos %min% caracteres',
                            ],
                        ],
                    ],
                ],
            ],
            'remember_me' => [
                'required' => false,
            ],
        ];
    }
}