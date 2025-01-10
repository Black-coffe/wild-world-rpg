<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class LoginFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! service('auth')->isAdmin()){
            return redirect()->to('/')
                ->with('message', 'Войдите, как администратор ресурса');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Do something here
    }
}