<?php

declare(strict_types=1);

namespace Kivopress\Admin\Controllers;

use Kivopress\Response;

final class ErrorsController extends Controller
{
    public function index(): Response
    {
        if ($redirect = $this->guardCapability('manage_settings')) {
            return $redirect;
        }

        $rows = '';

        foreach ($this->app->logger()->recent(100) as $entry) {
            $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
            $file = trim((string) ($context['file'] ?? '') . ':' . (string) ($context['line'] ?? ''), ':');
            $rows .= '<tr>
                <td><span class="kp-pill ' . $this->levelClass((string) ($entry['level'] ?? 'info')) . '">' . \e((string) ($entry['level'] ?? 'info')) . '</span></td>
                <td><code>' . \e((string) ($entry['id'] ?? '')) . '</code></td>
                <td>' . \e((string) ($entry['message'] ?? '')) . '<div class="kp-muted">' . \e($file) . '</div></td>
                <td>' . \e((string) ($context['method'] ?? '')) . ' <code>' . \e((string) ($context['path'] ?? '')) . '</code></td>
                <td>' . \e((string) ($entry['time'] ?? '')) . '</td>
            </tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="kp-empty">No logged incidents yet.</td></tr>';
        }

        $html = '<section class="kp-panel">
            <div class="kp-panel-head">
                <div><h2>Error Catcher</h2><p>Fatal errors, uncaught exceptions, and PHP warnings are logged with an event ID so bugs do not disappear quietly.</p></div>
                <form method="post" action="/admin/tools/errors/clear" onsubmit="return confirm(\'Clear the current Kivopress log?\')">
                    ' . $this->view->csrfField() . '
                    <button class="kp-button kp-button-secondary">' . $this->view->icon('delete_sweep') . 'Clear Log</button>
                </form>
            </div>
            <div class="kp-table-wrap"><table><thead><tr><th>Level</th><th>Event ID</th><th>Message</th><th>Request</th><th>Time</th></tr></thead><tbody>' . $rows . '</tbody></table></div>
        </section>';

        return $this->view->layout('Error Logs', $html);
    }

    public function clear(): Response
    {
        if ($redirect = $this->guardPostCapability('manage_settings')) {
            return $redirect;
        }

        $this->app->logger()->clear();
        $this->auth->flash('notice', 'Error log cleared.');

        return Response::redirect('/admin/tools/errors');
    }

    private function levelClass(string $level): string
    {
        return match ($level) {
            'error' => 'kp-pill-danger',
            'warning' => 'kp-pill-muted',
            default => 'kp-pill-live',
        };
    }
}
