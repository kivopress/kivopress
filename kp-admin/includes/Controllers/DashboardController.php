<?php

declare(strict_types=1);

namespace Kivopress\Admin\Controllers;

use Kivopress\Response;

final class DashboardController extends Controller
{
    public function index(): Response
    {
        if ($redirect = $this->auth->requireAdmin()) {
            return $redirect;
        }

        $cards = '';

        foreach ($this->content->types() as $type) {
            if ($this->auth->can('edit_' . $type['api_slug'])) {
                $cards .= '<a class="kp-metric-card" href="/admin/content/' . \e($type['name']) . '">
                    <span>' . \e($type['label']) . '</span>
                    <strong>' . $this->content->count($type['name']) . '</strong>
                </a>';
            }
        }

        if ($this->auth->can('upload_media') || $this->auth->can('manage_media')) {
            $cards .= '<a class="kp-metric-card" href="/admin/media">
                <span>Media</span>
                <strong>' . $this->app->media()->count() . '</strong>
            </a>';
        }

        if ($this->auth->can('manage_users')) {
            $cards .= '<a class="kp-metric-card" href="/admin/users">
                <span>Users</span>
                <strong>' . $this->auth->countUsers() . '</strong>
            </a>';
        }

        if ($cards === '') {
            $cards = '<section class="kp-panel"><p>Welcome to Kivopress.</p></section>';
        }

        return $this->view->layout('Dashboard', '<div class="kp-metric-grid">' . $cards . '</div>');
    }
}
