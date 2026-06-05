<?php

declare(strict_types=1);

namespace Kivopress\Admin\Controllers;

use Kivopress\PackageInstaller;
use Kivopress\Response;

final class ExtensionsController extends Controller
{
    public function themes(): Response
    {
        if ($redirect = $this->guardCapability('manage_extensions')) {
            return $redirect;
        }

        $cards = '';

        foreach ($this->app->theme()->all() as $theme) {
            $issues = $this->themeIssues($theme);
            $actions = $theme['active']
                ? '<span class="kp-status-note">Active theme</span>'
                : '<form method="post" action="/admin/themes/' . \e($theme['slug']) . '/activate">' . $this->view->csrfField() . '<button>Activate</button></form>';

            if (!$theme['valid']) {
                $actions = '<span class="kp-status-note kp-status-danger">Fix validation errors</span>';
            }

            $cards .= '<article class="kp-extension-card">
                <div class="kp-extension-preview">' . $this->view->icon('palette') . '</div>
                <div class="kp-extension-body">
                    <div class="kp-extension-title">
                        <h2>' . \e($theme['name']) . '</h2>
                        ' . ($theme['active'] ? '<span class="kp-pill kp-pill-live">Active</span>' : '') . '
                    </div>
                    <p>' . \e($theme['description']) . '</p>
                    <div class="kp-extension-meta">v' . \e($theme['version']) . ' by ' . \e($theme['author']) . '</div>
                    ' . $issues . '
                    <div class="kp-extension-actions">' . $actions . '</div>
                </div>
            </article>';
        }

        if ($cards === '') {
            $cards = '<section class="kp-panel kp-empty">No themes found in kp-content/themes.</section>';
        }

        return $this->view->layout('Themes', $this->uploadPanel('Theme', '/admin/themes/upload') . '<div class="kp-extension-grid">' . $cards . '</div>');
    }

    public function uploadTheme(): Response
    {
        if ($redirect = $this->guardPostCapability('manage_extensions')) {
            return $redirect;
        }

        try {
            $package = new PackageInstaller($this->app->path());
            $installed = $package->installThemeUpload($_FILES['package'] ?? []);
            $this->auth->flash('notice', 'Theme uploaded: ' . $installed['slug']);
        } catch (\Throwable $exception) {
            $this->auth->flash('error', $exception->getMessage());
        }

        return Response::redirect('/admin/themes');
    }

    public function activateTheme(string $slug): Response
    {
        if ($redirect = $this->guardPostCapability('manage_extensions')) {
            return $redirect;
        }

        try {
            $this->app->theme()->activate($slug);
            $this->auth->flash('notice', 'Theme activated.');
        } catch (\Throwable $exception) {
            $this->auth->flash('error', $exception->getMessage());
        }

        return Response::redirect('/admin/themes');
    }

    public function plugins(): Response
    {
        if ($redirect = $this->guardCapability('manage_extensions')) {
            return $redirect;
        }

        $rows = '';

        foreach ($this->app->plugins()->discover() as $plugin) {
            $status = $plugin['active'] ? '<span class="kp-pill kp-pill-live">Active</span>' : '<span class="kp-pill kp-pill-muted">Inactive</span>';

            if (!$plugin['valid']) {
                $action = '<span class="kp-status-note kp-status-danger">Missing plugin.php</span>';
            } elseif ($plugin['active']) {
                $action = '<form method="post" action="/admin/plugins/' . \e($plugin['slug']) . '/deactivate" class="kp-inline-form">' . $this->view->csrfField() . '<button class="kp-button kp-button-secondary">Deactivate</button></form>';
            } else {
                $action = '<form method="post" action="/admin/plugins/' . \e($plugin['slug']) . '/activate" class="kp-inline-form">' . $this->view->csrfField() . '<button>Activate</button></form>';
            }

            $rows .= '<tr>
                <td><strong>' . \e($plugin['name']) . '</strong><div class="kp-muted">' . \e($plugin['description']) . '</div></td>
                <td>' . $status . '</td>
                <td>v' . \e($plugin['version']) . '</td>
                <td>' . \e($plugin['author']) . '</td>
                <td class="kp-actions-cell">' . $action . '</td>
            </tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="kp-empty">No plugins found in kp-content/plugins.</td></tr>';
        }

        $html = $this->uploadPanel('Plugin', '/admin/plugins/upload') . '<section class="kp-panel"><div class="kp-table-wrap"><table>
            <thead><tr><th>Plugin</th><th>Status</th><th>Version</th><th>Author</th><th class="kp-actions-head">Actions</th></tr></thead>
            <tbody>' . $rows . '</tbody>
        </table></div></section>';

        return $this->view->layout('Plugins', $html);
    }

    public function uploadPlugin(): Response
    {
        if ($redirect = $this->guardPostCapability('manage_extensions')) {
            return $redirect;
        }

        try {
            $package = new PackageInstaller($this->app->path());
            $installed = $package->installPluginUpload($_FILES['package'] ?? []);
            $this->auth->flash('notice', 'Plugin uploaded: ' . $installed['slug']);
        } catch (\Throwable $exception) {
            $this->auth->flash('error', $exception->getMessage());
        }

        return Response::redirect('/admin/plugins');
    }

    public function activatePlugin(string $slug): Response
    {
        if ($redirect = $this->guardPostCapability('manage_extensions')) {
            return $redirect;
        }

        $this->app->plugins()->activate($slug);
        $this->auth->flash('notice', 'Plugin activated.');

        return Response::redirect('/admin/plugins');
    }

    public function deactivatePlugin(string $slug): Response
    {
        if ($redirect = $this->guardPostCapability('manage_extensions')) {
            return $redirect;
        }

        $this->app->plugins()->deactivate($slug);
        $this->auth->flash('notice', 'Plugin deactivated.');

        return Response::redirect('/admin/plugins');
    }

    private function uploadPanel(string $type, string $action): string
    {
        $requirement = $type === 'Theme' ? 'index.php' : 'plugin.php';
        $warning = class_exists(\ZipArchive::class) ? '' : '<div class="kp-notice kp-notice-error">The PHP zip extension is not enabled. Upload validation is available, but extraction needs ext-zip.</div>';

        return '<section class="kp-panel">
            <div class="kp-panel-head">
                <div><h2>Upload ' . \e($type) . '</h2><p>Upload a zip package containing ' . \e($requirement) . '. Existing packages are never overwritten.</p></div>
            </div>
            ' . $warning . '
            <form method="post" action="' . \e($action) . '" enctype="multipart/form-data" class="kp-upload-form">
                ' . $this->view->csrfField() . '
                <input type="file" name="package" accept=".zip" required>
                <button>Upload ' . \e($type) . '</button>
            </form>
        </section>';
    }

    private function themeIssues(array $theme): string
    {
        $messages = array_merge((array) ($theme['errors'] ?? []), (array) ($theme['warnings'] ?? []));

        if ($messages === []) {
            return '<div class="kp-extension-meta">' . max(0, count((array) ($theme['page_templates'] ?? [])) - 1) . ' page templates</div>';
        }

        $items = '';

        foreach ($messages as $message) {
            $items .= '<li>' . \e((string) $message) . '</li>';
        }

        return '<ul class="kp-extension-issues">' . $items . '</ul>';
    }
}
