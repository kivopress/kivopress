<?php

declare(strict_types=1);

namespace Kivopress\Admin\Controllers;

use Kivopress\Response;

final class AuthController extends Controller
{
    public function setup(): Response
    {
        if ($this->auth->hasUsers()) {
            return Response::redirect('/admin/login');
        }

        return $this->view->layout('Create Admin', $this->view->form('/admin/setup', '
            <label>Name<input name="name" required autofocus></label>
            <label>Email<input type="email" name="email" required></label>
            <label>Password<input type="password" name="password" required minlength="8"></label>
            <button>Create Admin</button>
        '), false);
    }

    public function storeSetup(): Response
    {
        if ($this->auth->hasUsers()) {
            return Response::redirect('/admin/login');
        }

        if (!$this->auth->validCsrf($_POST['_csrf'] ?? null)) {
            return $this->view->layout('Invalid Request', '<p>Your session token expired. Go back and try again.</p>', true, 419);
        }

        $this->auth->createAdmin($_POST['name'] ?? '', $_POST['email'] ?? '', $_POST['password'] ?? '');
        $this->auth->flash('notice', 'Admin created.');

        return Response::redirect('/admin');
    }

    public function login(): Response
    {
        if (!$this->auth->hasUsers()) {
            return Response::redirect('/admin/setup');
        }

        return $this->view->layout('Sign In', $this->view->form('/admin/login', '
            <label>Email<input type="email" name="email" required autofocus></label>
            <label>Password<input type="password" name="password" required></label>
            <button>Sign In</button>
        '), false);
    }

    public function attemptLogin(): Response
    {
        if (!$this->auth->validCsrf($_POST['_csrf'] ?? null)) {
            return $this->view->layout('Invalid Request', '<p>Your session token expired. Go back and try again.</p>', true, 419);
        }

        if ($this->auth->attempt($_POST['email'] ?? '', $_POST['password'] ?? '')) {
            return Response::redirect('/admin');
        }

        $this->auth->flash('notice', 'Invalid email or password.');

        return Response::redirect('/admin/login');
    }

    public function logout(): Response
    {
        if (!$this->auth->validCsrf($_POST['_csrf'] ?? null)) {
            return $this->view->layout('Invalid Request', '<p>Your session token expired. Go back and try again.</p>', true, 419);
        }

        $this->auth->logout();

        return Response::redirect('/admin/login');
    }
}
