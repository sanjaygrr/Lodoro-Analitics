<?php
// module/Application/src/Model/User.php

namespace Application\Model;

class User
{
    public $id;
    public $username;
    public $email;
    public $password;
    public $role;
    public $created_at;
    public $active;

    public function exchangeArray(array $data)
    {
        $this->id         = !empty($data['id']) ? $data['id'] : null;
        $this->username   = !empty($data['username']) ? $data['username'] : null;
        $this->email      = !empty($data['email']) ? $data['email'] : null;
        $this->password   = !empty($data['password']) ? $data['password'] : null;
        $this->role       = !empty($data['role']) ? $data['role'] : 'user';
        $this->created_at = !empty($data['created_at']) ? $data['created_at'] : null;
        $this->active     = isset($data['active']) ? (bool) $data['active'] : true;
    }

    public function getArrayCopy()
    {
        return [
            'id'         => $this->id,
            'username'   => $this->username,
            'email'      => $this->email,
            'password'   => $this->password,
            'role'       => $this->role,
            'created_at' => $this->created_at,
            'active'     => $this->active,
        ];
    }
}