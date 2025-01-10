<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;
use App\Libraries\Token;

class User extends Entity
{
    protected $attributes = [
        'id' => null,
        'name' => null,
        'email' => null,
        'password_hash' => null,
        'is_admin' => null,
        'photo_url' => null,
        'created_at' => null,
        'updated_at' => null,
        'reset_hash' => null,
        'reset_expires_at' => null
    ];

    public function verifyPassword($password)
    {
        return password_verify($password, $this->password_hash);
    }

    public function startActivation()
    {
        $token = new Token;

        $this->token = $token->getValue();

        $this->activation_hash = $token->getHash();
    }

    public function activate()
    {
        $this->is_active = true;
        $this->activation_hash = null;
    }

    public function startPasswordReset()
    {
        $token = new Token;

        $this->reset_token = $token->getValue();

        $this->reset_hash = $token->getHash();

        $this->reset_expires_at = date('Y-m-d H:i:s', time() + 7200);
    }

    public function completePasswordReset()
    {
        $this->reset_hash = null;
        $this->reset_expires_at = null;
    }
}











