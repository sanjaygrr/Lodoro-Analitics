<?php
// En module/Application/src/View/Helper/AuthenticationServiceHelper.php

namespace Application\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Laminas\Authentication\AuthenticationService;

class AuthenticationServiceHelper extends AbstractHelper
{
    protected $authService;
    
    public function __construct(AuthenticationService $authService)
    {
        $this->authService = $authService;
    }
    
    public function __invoke()
    {
        return $this->authService;
    }
}