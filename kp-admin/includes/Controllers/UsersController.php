<?php

declare(strict_types=1);

namespace Kivopress\Admin\Controllers;

use Kivopress\Response;

final class UsersController extends Controller
{
    public function index(): Response
    {
        if ($redirect = $this->guardUsers()) {
            return $redirect;
        }

        $role = (string) ($_GET['role'] ?? '');
        $search = trim((string) ($_GET['search'] ?? ''));
        $users = $this->auth->allUsers(['role' => $role, 'search' => $search]);
        $rows = '';

        foreach ($users as $user) {
            $editUrl = '/admin/users/' . $user['id'] . '/edit';
            $rows .= '<tr>
                <td><div class="kp-user-cell"><span class="kp-avatar">' . \e($this->initials($user['name'])) . '</span><div><a class="kp-link-strong" href="' . $editUrl . '">' . \e($user['name']) . '</a><div class="kp-muted">' . \e($user['email']) . '</div></div></div></td>
                <td><span class="kp-pill">' . \e($this->roleLabel($user['role'])) . '</span><div class="kp-muted">' . \e($this->auth->roleDescription($user['role'])) . '</div></td>
                <td>' . \e($user['created_at']) . '</td>
                <td class="kp-actions-cell">' . $this->actions($user) . '</td>
            </tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="kp-empty">No users found.</td></tr>';
        }

        $html = '<div class="kp-toolbar">
                <form method="get" action="/admin/users" class="kp-search-form">
                    <input name="search" value="' . \e($search) . '" placeholder="Search users">
                    <button class="kp-button kp-button-secondary">Search</button>
                </form>
                <a class="kp-button" href="/admin/users/new">' . $this->view->icon('person_add') . 'Add User</a>
            </div>
            ' . $this->roleFilters($role) . '
            <section class="kp-panel"><div class="kp-table-wrap"><table>
                <thead><tr><th>User</th><th>Role</th><th>Created</th><th class="kp-actions-head">Actions</th></tr></thead>
                <tbody>' . $rows . '</tbody>
            </table></div></section>';

        return $this->view->layout('Users', $html);
    }

    public function create(): Response
    {
        if ($redirect = $this->guardUsers()) {
            return $redirect;
        }

        return $this->view->layout('Add User', $this->form('/admin/users'));
    }

    public function store(): Response
    {
        if ($redirect = $this->guardPost()) {
            return $redirect;
        }

        if (!$this->auth->canManageUsers()) {
            return $this->forbidden();
        }

        try {
            $user = $this->auth->createUser($_POST);
            $this->auth->flash('notice', 'User created.');

            return Response::redirect('/admin/users/' . $user['id'] . '/edit');
        } catch (\Throwable $exception) {
            $this->auth->flash('error', $exception->getMessage());

            return Response::redirect('/admin/users/new');
        }
    }

    public function edit(int $id): Response
    {
        if ($redirect = $this->guardUsers()) {
            return $redirect;
        }

        $user = $this->auth->userById($id);

        if (!$user) {
            return $this->view->layout('Not Found', '<p>User not found.</p>', true, 404);
        }

        return $this->view->layout('Edit User', $this->form('/admin/users/' . $id, $user));
    }

    public function update(int $id): Response
    {
        if ($redirect = $this->guardPost()) {
            return $redirect;
        }

        if (!$this->auth->canManageUsers()) {
            return $this->forbidden();
        }

        try {
            if (!$this->auth->updateUser($id, $_POST)) {
                return $this->view->layout('Not Found', '<p>User not found.</p>', true, 404);
            }

            $this->auth->flash('notice', 'User saved.');
        } catch (\Throwable $exception) {
            $this->auth->flash('error', $exception->getMessage());
        }

        return Response::redirect('/admin/users/' . $id . '/edit');
    }

    public function delete(int $id): Response
    {
        if ($redirect = $this->guardPost()) {
            return $redirect;
        }

        if (!$this->auth->canManageUsers()) {
            return $this->forbidden();
        }

        try {
            $this->auth->deleteUser($id, (int) $this->auth->user()['id']);
            $this->auth->flash('notice', 'User deleted.');
        } catch (\Throwable $exception) {
            $this->auth->flash('error', $exception->getMessage());
        }

        return Response::redirect('/admin/users');
    }

    private function guardUsers(): ?Response
    {
        return $this->guardCapability('manage_users');
    }

    private function form(string $action, ?array $user = null): string
    {
        $passwordLabel = $user ? 'New Password' : 'Password';
        $passwordHelp = $user ? '<p class="kp-field-help">Leave blank to keep the current password.</p>' : '';
        $delete = $user ? '<form method="post" action="/admin/users/' . $user['id'] . '/delete" class="kp-delete-form" onsubmit="return confirm(\'Delete this user permanently?\')">' . $this->view->csrfField() . '<button class="kp-button kp-button-danger">' . $this->view->icon('delete') . 'Delete User</button></form>' : '';

        $fields = '<label>Name<input name="name" value="' . \e($user['name'] ?? '') . '" required autofocus></label>
            <label>Email<input type="email" name="email" value="' . \e($user['email'] ?? '') . '" required></label>
            <label>Role<select name="role">' . $this->roleOptions((string) ($user['role'] ?? 'author')) . '</select></label>
            <label>' . $passwordLabel . '<input type="password" name="password" ' . ($user ? '' : 'required') . ' minlength="8"></label>
            ' . $passwordHelp . '
            <button>' . $this->view->icon('save') . 'Save User</button>';

        return '<div class="kp-user-editor">
            <section class="kp-panel">' . $this->view->form($action, $fields) . '</section>
            <aside class="kp-panel">
                <h2>User Safety</h2>
                <p class="kp-muted">Administrator accounts can manage all admin settings, users, plugins, themes, content, and API tokens.</p>
                <h2>Role Capabilities</h2>
                ' . $this->roleCapabilityList() . '
                ' . $delete . '
            </aside>
        </div>';
    }

    private function roleOptions(string $selected): string
    {
        $html = '';

        foreach ($this->auth->roles() as $role => $label) {
            $html .= '<option value="' . \e($role) . '" ' . ($role === $selected ? 'selected' : '') . '>' . \e($label) . '</option>';
        }

        return $html;
    }

    private function roleCapabilityList(): string
    {
        $labels = $this->capabilityLabels();
        $html = '<div class="kp-role-list">';

        foreach ($this->auth->roleDefinitions() as $role => $definition) {
            $items = '';

            foreach ($definition['capabilities'] as $capability) {
                $items .= '<li>' . \e($labels[$capability] ?? ucfirst(str_replace('_', ' ', $capability))) . '</li>';
            }

            $html .= '<section>
                <h3>' . \e($definition['label']) . '</h3>
                <p class="kp-muted">' . \e($definition['description']) . '</p>
                <ul>' . $items . '</ul>
            </section>';
        }

        return $html . '</div>';
    }

    private function roleFilters(string $active): string
    {
        $html = '<div class="kp-media-filters"><a class="' . ($active === '' ? 'is-active' : '') . '" href="/admin/users">All <span>' . $this->auth->countUsers() . '</span></a>';

        foreach ($this->auth->roles() as $role => $label) {
            $html .= '<a class="' . ($active === $role ? 'is-active' : '') . '" href="/admin/users?role=' . \e($role) . '">' . \e($label) . ' <span>' . $this->auth->countUsers($role) . '</span></a>';
        }

        return $html . '</div>';
    }

    private function actions(array $user): string
    {
        $delete = (int) $user['id'] === (int) $this->auth->user()['id']
            ? ''
            : '<form method="post" action="/admin/users/' . $user['id'] . '/delete" class="kp-inline-form" onsubmit="return confirm(\'Delete this user permanently?\')">' . $this->view->csrfField() . '<button class="kp-row-action kp-row-action-danger">' . $this->view->icon('delete') . 'Delete</button></form>';

        return '<div class="kp-row-actions"><a class="kp-row-action" href="/admin/users/' . $user['id'] . '/edit">' . $this->view->icon('edit') . 'Edit</a>' . $delete . '</div>';
    }

    private function roleLabel(string $role): string
    {
        return $this->auth->roles()[$role] ?? ucfirst($role);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= strtoupper(substr($part, 0, 1));
        }

        return $letters ?: 'U';
    }

    private function capabilityLabels(): array
    {
        return [
            'read' => 'Access dashboard',
            'edit_posts' => 'Create and edit posts',
            'publish_posts' => 'Publish posts',
            'delete_posts' => 'Delete posts',
            'edit_pages' => 'Create and edit pages',
            'publish_pages' => 'Publish pages',
            'delete_pages' => 'Delete pages',
            'upload_media' => 'Upload media',
            'manage_media' => 'Manage media library',
            'manage_users' => 'Manage users and roles',
            'manage_settings' => 'Manage settings and API tokens',
            'manage_extensions' => 'Manage themes and plugins',
        ];
    }
}
