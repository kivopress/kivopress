<?php

declare(strict_types=1);

namespace Kivopress\Admin\Controllers;

use Kivopress\Response;

final class SettingsController extends Controller
{
    public function index(): Response
    {
        if ($redirect = $this->guardCapability('manage_settings')) {
            return $redirect;
        }

        $html = $this->tabs('general') . '<section class="kp-panel">
            <div class="kp-panel-head">
                <div><h2>General</h2><p>Core site settings used by themes and APIs.</p></div>
            </div>
            ' . $this->view->form('/admin/settings', '
                <label>Site Name<input name="site_name" value="' . \e((string) option('site_name', 'Kivopress')) . '" required></label>
                ' . $this->frontPageField() . '
                <button>Save Settings</button>
            ') . '
        </section>' . $this->apiTokenPanel();

        return $this->view->layout('Settings', $html);
    }

    public function permalinks(): Response
    {
        if ($redirect = $this->guardCapability('manage_settings')) {
            return $redirect;
        }

        $current = (string) option('permalink_structure', '/%postname%/');
        $options = [
            '/%postname%/' => 'Post name',
            '/blog/%postname%/' => 'Blog prefix',
            '/%year%/%monthnum%/%postname%/' => 'Month and name',
        ];
        $choices = '';

        foreach ($options as $value => $label) {
            $choices .= '<label class="kp-radio-row"><input type="radio" name="permalink_preset" value="' . \e($value) . '" ' . ($current === $value ? 'checked' : '') . '> <span><strong>' . \e($label) . '</strong><code>' . \e($value) . '</code></span></label>';
        }

        $customChecked = !array_key_exists($current, $options);
        $html = $this->tabs('permalinks') . '<section class="kp-panel">
            <div class="kp-panel-head"><div><h2>Permalink Structure</h2><p>Control public post URLs. Pages keep clean page-name URLs.</p></div></div>
            ' . $this->view->form('/admin/settings/permalinks', '
                <div class="kp-choice-list">' . $choices . '
                    <label class="kp-radio-row"><input type="radio" name="permalink_preset" value="custom" ' . ($customChecked ? 'checked' : '') . '> <span><strong>Custom structure</strong><input name="permalink_custom" value="' . \e($current) . '" placeholder="/articles/%postname%/"></span></label>
                </div>
                <p class="kp-field-help">Available tags: <code>%postname%</code>, <code>%year%</code>, <code>%monthnum%</code>, <code>%day%</code>.</p>
                <button>Save Permalinks</button>
            ') . '
        </section>';

        return $this->view->layout('Permalinks', $html);
    }

    public function save(): Response
    {
        if ($redirect = $this->guardPostCapability('manage_settings')) {
            return $redirect;
        }

        $previousFrontPageId = (int) option('front_page_id', 0);
        $frontPageId = $this->validatedFrontPageId($_POST['front_page_id'] ?? 0);

        if ($frontPageId < 0) {
            $this->auth->flash('error', 'Choose a published page for the front page, or use latest posts.');

            return Response::redirect('/admin/settings');
        }

        set_option('site_name', trim((string) ($_POST['site_name'] ?? 'Kivopress')) ?: 'Kivopress');
        set_option('front_page_id', $frontPageId);
        do_action('settings.front_page_saved', $frontPageId, $previousFrontPageId);
        $this->auth->flash('notice', 'Settings saved.');

        return Response::redirect('/admin/settings');
    }

    public function savePermalinks(): Response
    {
        if ($redirect = $this->guardPostCapability('manage_settings')) {
            return $redirect;
        }

        $preset = (string) ($_POST['permalink_preset'] ?? '/%postname%/');
        $structure = $preset === 'custom' ? (string) ($_POST['permalink_custom'] ?? '') : $preset;
        $structure = '/' . trim($structure, '/') . '/';

        if (!str_contains($structure, '%postname%')) {
            $this->auth->flash('error', 'Permalink structure must include %postname%.');

            return Response::redirect('/admin/settings/permalinks');
        }

        set_option('permalink_structure', $structure);
        $this->auth->flash('notice', 'Permalink structure saved.');

        return Response::redirect('/admin/settings/permalinks');
    }

    public function createApiToken(): Response
    {
        if ($redirect = $this->guardPostCapability('manage_settings')) {
            return $redirect;
        }

        $user = $this->auth->user();
        $ttlDays = $this->tokenTtlDays($_POST['expires_in_days'] ?? null);
        $token = $this->auth->createApiToken($user['id'], $_POST['name'] ?? 'API token', $ttlDays);
        $this->auth->flash('api_token', $token['token']);
        $this->auth->flash('notice', 'API token created.');

        return Response::redirect('/admin/settings');
    }

    public function revokeApiToken(int $id): Response
    {
        if ($redirect = $this->guardPostCapability('manage_settings')) {
            return $redirect;
        }

        $user = $this->auth->user();
        $this->auth->revokeApiToken($user['id'], $id);
        $this->auth->flash('notice', 'API token revoked.');

        return Response::redirect('/admin/settings');
    }

    public function rotateApiToken(int $id): Response
    {
        if ($redirect = $this->guardPostCapability('manage_settings')) {
            return $redirect;
        }

        $user = $this->auth->user();
        $ttlDays = $this->tokenTtlDays($_POST['expires_in_days'] ?? null);
        $token = $this->auth->rotateApiToken($user['id'], $id, $ttlDays);

        if (!$token) {
            $this->auth->flash('error', 'Token could not be rotated.');

            return Response::redirect('/admin/settings');
        }

        $this->auth->flash('api_token', $token['token']);
        $this->auth->flash('notice', 'API token rotated. Copy the new token now.');

        return Response::redirect('/admin/settings');
    }

    private function apiTokenPanel(): string
    {
        $user = $this->auth->user();
        $created = $this->auth->flash('api_token');
        $createdHtml = $created ? '<div class="kp-token-reveal"><span>New token</span><code>' . \e($created) . '</code></div>' : '';
        $rows = '';

        foreach ($this->auth->apiTokens($user['id']) as $token) {
            $expired = (bool) ($token['expired'] ?? false);
            $status = $token['revoked_at'] ? 'Revoked' : ($expired ? 'Expired' : 'Active');
            $action = $token['revoked_at'] ? '<span class="kp-muted">No actions</span>' : '
                <form method="post" action="/admin/settings/api-tokens/' . $token['id'] . '/revoke" class="kp-inline-form">
                    ' . $this->view->csrfField() . '
                    <button class="kp-button kp-button-danger">Revoke</button>
                </form>
                <form method="post" action="/admin/settings/api-tokens/' . $token['id'] . '/rotate" class="kp-inline-form">
                    ' . $this->view->csrfField() . '
                    <input type="hidden" name="expires_in_days" value="' . \e((string) $this->defaultTokenTtl()) . '">
                    <button class="kp-button kp-button-secondary">Rotate</button>
                </form>';

            $rows .= '<tr>
                <td><strong>' . \e($token['name']) . '</strong></td>
                <td><span class="kp-pill ' . ($token['revoked_at'] || $expired ? 'kp-pill-muted' : 'kp-pill-live') . '">' . $status . '</span></td>
                <td>' . \e($token['created_at']) . '</td>
                <td>' . \e($token['expires_at'] ?: 'Never') . '</td>
                <td>' . \e($token['last_used_at'] ?: 'Never') . '</td>
                <td>' . \e($token['rotated_at'] ?: 'Never') . '</td>
                <td>' . $action . '</td>
            </tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="kp-empty">No API tokens yet.</td></tr>';
        }

        return $createdHtml . '<section class="kp-panel">
            <div class="kp-panel-head">
                <div><h2>API Access</h2><p>Create and revoke headless API tokens. Tokens are shown once.</p></div>
            </div>
            ' . $this->view->form('/admin/settings/api-tokens', '
                <label>Token Name<input name="name" value="Headless client" required></label>
                <label>Expires In Days<input type="number" min="1" max="3650" name="expires_in_days" value="' . \e((string) $this->defaultTokenTtl()) . '" required></label>
                <button>Create Token</button>
            ') . '
        </section>
        <section class="kp-panel">
            <div class="kp-table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>Status</th><th>Created</th><th>Expires</th><th>Last Used</th><th>Rotated</th><th></th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                </table>
            </div>
        </section>';
    }

    private function tokenTtlDays(mixed $value): int
    {
        return max(1, min(3650, (int) ($value ?: $this->defaultTokenTtl())));
    }

    private function defaultTokenTtl(): int
    {
        return max(1, (int) $this->app->config('api.token_ttl_days', 90));
    }

    private function frontPageField(): string
    {
        $pages = (array) apply_filters('settings.front_page_pages', $this->content->all('page', [
            'limit' => 100,
            'orderby' => 'title',
            'order' => 'asc',
        ]));
        $stored = option('front_page_id', null);
        $legacyHome = $stored === null ? $this->content->find('page', 'home') : null;
        $selected = $stored === null ? (int) ($legacyHome['id'] ?? 0) : (int) $stored;
        $selectedPage = $selected > 0 ? $this->content->find('page', $selected) : null;
        $pages = array_values(array_filter($pages, 'is_array'));

        if ($selectedPage && !in_array((int) $selectedPage['id'], array_map(fn (array $page): int => (int) ($page['id'] ?? 0), $pages), true)) {
            $pages[] = $selectedPage;
        }

        $options = '<option value="0"' . ($selected === 0 ? ' selected' : '') . '>Latest posts</option>';

        foreach ($pages as $page) {
            $id = (int) ($page['id'] ?? 0);
            $label = trim((string) ($page['title'] ?? ''));

            if ($id <= 0) {
                continue;
            }

            $options .= '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>' . \e($label ?: 'Untitled') . '</option>';
        }

        $help = $legacyHome
            ? 'Currently using the published page with slug <code>home</code>. Save settings to make the selection explicit.'
            : 'Choose a published page to render at the site root, or keep latest posts.';

        if ($pages === []) {
            $help .= ' <a href="/admin/content/page/new">Create a page</a>.';
        }

        return '<label>Front Page
            <select name="front_page_id">' . $options . '</select>
            <span class="kp-field-help">' . $help . '</span>
        </label>';
    }

    private function validatedFrontPageId(mixed $value): int
    {
        $id = max(0, (int) $value);

        if ($id === 0) {
            return 0;
        }

        $page = $this->content->find('page', $id, true);

        return $page && ($page['status'] ?? '') === 'published' ? $id : -1;
    }

    private function tabs(string $active): string
    {
        $tabs = [
            'general' => ['href' => '/admin/settings', 'label' => 'General'],
            'permalinks' => ['href' => '/admin/settings/permalinks', 'label' => 'Permalinks'],
        ];
        $html = '<nav class="kp-tabs" aria-label="Settings sections">';

        foreach ($tabs as $key => $tab) {
            $html .= '<a href="' . \e($tab['href']) . '" class="' . ($active === $key ? 'is-active' : '') . '">' . \e($tab['label']) . '</a>';
        }

        return $html . '</nav>';
    }
}
