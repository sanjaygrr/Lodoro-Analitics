<?php
// module/Application/src/Model/UserTable.php

namespace Application\Model;

use RuntimeException;
use Laminas\Db\TableGateway\TableGatewayInterface;

class UserTable
{
    private $tableGateway;

    public function __construct(TableGatewayInterface $tableGateway)
    {
        $this->tableGateway = $tableGateway;
    }

    public function fetchAll()
    {
        return $this->tableGateway->select();
    }

    public function getUser($id)
    {
        $id = (int) $id;
        $rowset = $this->tableGateway->select(['id' => $id]);
        $row = $rowset->current();
        if (! $row) {
            throw new RuntimeException(sprintf(
                'Could not find user with identifier %d',
                $id
            ));
        }

        return $row;
    }

    public function getUserByUsername($username)
    {
        $rowset = $this->tableGateway->select(['username' => $username]);
        $row = $rowset->current();
        return $row;
    }

    public function saveUser(User $user)
    {
        $data = [
            'username'   => $user->username,
            'email'      => $user->email,
            'role'       => $user->role,
            'active'     => $user->active,
        ];

        $id = (int) $user->id;

        if ($id === 0) {
            // Hash the password for new users
            $data['password'] = password_hash($user->password, PASSWORD_DEFAULT);
            $this->tableGateway->insert($data);
            return;
        }

        if (! $this->getUser($id)) {
            throw new RuntimeException(sprintf(
                'Cannot update user with identifier %d; does not exist',
                $id
            ));
        }

        // Only update password if it's provided
        if (!empty($user->password)) {
            $data['password'] = password_hash($user->password, PASSWORD_DEFAULT);
        }

        $this->tableGateway->update($data, ['id' => $id]);
    }

    public function verifyCredentials($username, $password)
    {
        $user = $this->getUserByUsername($username);
        
        if (!$user) {
            return false;
        }
        
        return password_verify($password, $user->password) && $user->active;
    }

    public function deleteUser($id)
    {
        $this->tableGateway->delete(['id' => (int) $id]);
    }
}