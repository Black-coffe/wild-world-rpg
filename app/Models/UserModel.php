<?php
namespace App\Models;

use App\Libraries\Token;
use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table = 'users';
    protected $allowedFields = ['name', 'email', 'password_hash', 'password', 'is_admin', 'photo_url', 'reset_hash', 'reset_expires_at'];
    protected $returnType = 'App\Entities\User';
    protected $useTimestamps = true;
    protected $validationRules = [
        'name' => 'required|alpha_space|min_length[3]|max_length[100]',
        'email' => 'required|valid_email|is_unique[users.email]',
        'password' => 'required|min_length[8]',
        'password_confirmation' => 'required_with[password]|matches[password]',
        'photo_url' => 'permit_empty',
        'is_admin' => 'permit_empty|in_list[0,1]'
    ];
    protected $validationMessages = [
        // Ваши сообщения об ошибках
    ];
    protected $beforeInsert = ['hashPassword'];
    protected function hashPassword(array $data)
    {
        if (isset($data['data']['password'])){
            $data['data']['password_hash'] = password_hash($data['data']['password'], PASSWORD_DEFAULT);
            unset($data['data']['password']); // удаляем оригинальный пароль, он больше не нужен
        }

        return $data;
    }

    public function findByEmail($email)
    {
        return $this->where('email', $email)
            ->first();
    }

    public function disablePasswordValidation()
    {
        unset($this->validationRules['password']);
        unset($this->validationRules['password_confirmation']);
    }

    public function activateByToken($token)
    {
        $token = new Token($token);

        $token_hash = $token->getHash();

        $user = $this->where('activation_hash', $token_hash)
            ->first();

        if ($user !== null) {

            $user->activate();

            $this->protect(false)
                ->save($user);

        }
    }

    public function getUserForPasswordReset($token)
    {
        $token = new Token($token);

        $token_hash = $token->getHash();

        $user = $this->where('reset_hash', $token_hash)
            ->first();

        if ($user) {

            if ($user->reset_expires_at < date('Y-m-d H:i:s')) {

                $user = null;

            }
        }

        return $user;
    }
}
