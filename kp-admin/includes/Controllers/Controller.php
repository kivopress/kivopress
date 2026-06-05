<?php

declare(strict_types=1);

namespace Kivopress\Admin\Controllers;

use Kivopress\Admin\AdminView;
use Kivopress\App;
use Kivopress\Auth;
use Kivopress\Content;
use Kivopress\Response;

abstract class Controller
{
    public function __construct(
        protected App $app,
        protected Auth $auth,
        protected Content $content,
        protected AdminView $view
    ) {
    }

    protected function guardPost(): ?Response
    {
        if ($redirect = $this->auth->requireAdmin()) {
            return $redirect;
        }

        if (!$this->auth->validCsrf($_POST['_csrf'] ?? null)) {
            return $this->view->layout('Invalid Request', '<p>Your session token expired. Go back and try again.</p>', true, 419);
        }

        return null;
    }

    protected function guardCapability(string $capability): ?Response
    {
        if ($redirect = $this->auth->requireAdmin()) {
            return $redirect;
        }

        return $this->auth->can($capability) ? null : $this->forbidden();
    }

    protected function guardPostCapability(string $capability): ?Response
    {
        if ($redirect = $this->guardPost()) {
            return $redirect;
        }

        return $this->auth->can($capability) ? null : $this->forbidden();
    }

    protected function forbidden(): Response
    {
        return $this->view->layout('Forbidden', '<p>You do not have permission to access this area.</p>', true, 403);
    }
}
