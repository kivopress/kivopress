<?php

declare(strict_types=1);

namespace Kivopress\Admin\Controllers;

use Kivopress\Response;

final class RestController extends Controller
{
    public function index(): Response
    {
        if ($redirect = $this->guardCapability('manage_settings')) {
            return $redirect;
        }

        $routes = $this->app->rest()->routes();
        $rows = '';

        foreach ($routes as $route) {
            $rows .= '<tr>
                <td><span class="kp-api-method">' . \e($route['method']) . '</span></td>
                <td><code>' . \e($route['path']) . '</code></td>
                <td>' . \e($route['namespace'] ?: 'short alias') . '</td>
                <td>' . \e($route['auth_required'] ? 'API token' : 'Public') . '</td>
                <td>' . \e($route['description'] ?: 'Registered REST endpoint.') . '</td>
            </tr>';
        }

        $code = "add_action('rest_api_init', function () {\n"
            . "    register_rest_route('my-plugin/v1', '/reports/{id}', [\n"
            . "    'methods' => 'GET',\n"
            . "    'description' => 'Return a report by ID.',\n"
            . "    'args' => ['id' => ['required' => true]],\n"
            . "    'permission_callback' => fn (array \$request): bool => true,\n"
            . "    'callback' => fn (array \$request) => [\n"
            . "        'id' => (int) \$request['params']['id'],\n"
            . "        'status' => 'ready',\n"
            . "    ],\n"
            . "    ]);\n"
            . "});";

        $html = '<section class="kp-api-hero">
            <div><p>REST API</p><h2>Fast endpoints, familiar developer flow</h2><span>Kivopress exposes a discoverable REST registry without loading a heavy REST stack.</span></div>
            <a class="kp-button" href="/api" target="_blank" rel="noopener">' . $this->view->icon('open_in_new') . 'Open Index</a>
        </section>
        <section class="kp-metric-grid">
            <div class="kp-metric-card"><span>Registered endpoints</span><strong>' . count($routes) . '</strong></div>
            <div class="kp-metric-card"><span>Discovery</span><strong class="kp-api-stat">/api</strong></div>
            <div class="kp-metric-card"><span>Namespace</span><strong class="kp-api-stat">kp/v1</strong></div>
        </section>
        <section class="kp-panel">
            <div class="kp-panel-head"><div><h2>Available Endpoints</h2><p>Generated from the live REST registry, including plugin routes registered with <code>register_rest_route()</code>.</p></div></div>
            <div class="kp-table-wrap"><table><thead><tr><th>Method</th><th>Endpoint</th><th>Namespace</th><th>Auth</th><th>Description</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5" class="kp-empty">No REST endpoints registered.</td></tr>') . '</tbody></table></div>
        </section>
        <section class="kp-api-grid">
            <div class="kp-panel"><div class="kp-panel-head"><div><h2>Pagination</h2><p>Collection endpoints support lightweight query controls.</p></div></div>
                <dl class="kp-api-list">
                    <dt><code>page</code></dt><dd>1-based page number. Default: <code>1</code>.</dd>
                    <dt><code>per_page</code></dt><dd>Items per page, capped at <code>100</code>.</dd>
                    <dt><code>offset</code></dt><dd>Manual offset when you need cursor-like control.</dd>
                    <dt><code>search</code></dt><dd>Searches title and body on content collections.</dd>
                    <dt><code>status</code></dt><dd>Published content is public. Draft/private require an API token.</dd>
                </dl>
                <p class="kp-field-help">Responses include <code>pagination</code>, <code>X-KP-Total</code>, and <code>X-KP-TotalPages</code>.</p>
            </div>
            <div class="kp-panel"><div class="kp-panel-head"><div><h2>Create Custom REST Routes</h2><p>Plugins can add endpoints with the native REST helper.</p></div></div>
                <pre class="kp-code"><code>' . \e($code) . '</code></pre>
            </div>
        </section>
        <section class="kp-panel"><div class="kp-panel-head"><div><h2>Authentication</h2><p>Create API tokens in Settings. Mutating routes accept <code>Authorization: Bearer TOKEN</code> or <code>X-Kivopress-Token</code>.</p></div></div>
            <div class="kp-api-links"><a class="kp-button kp-button-secondary" href="/admin/settings">Manage API Tokens</a><a class="kp-button kp-button-secondary" href="/api/kp/v1/posts?page=1&per_page=10" target="_blank" rel="noopener">Try Posts Endpoint</a></div>
        </section>';

        return $this->view->layout('REST API', $html);
    }
}
