<?php

declare(strict_types=1);

namespace Kivopress;

final class AdminBar
{
    public function __construct(private App $app)
    {
    }

    public function inject(string $html, array $data): string
    {
        $user = $this->app->auth()->user();

        if (!$user) {
            return $html;
        }

        $context = $this->context($user, $data);
        $bar = apply_filters('admin_bar.html', $this->html($context), $context);
        $css = '<style id="kp-front-adminbar-style">' . apply_filters('admin_bar.css', $this->css(), $context) . '</style>';

        if (stripos($html, '</head>') !== false) {
            $html = preg_replace('/<\/head>/i', $css . '</head>', $html, 1) ?? $html;
        } else {
            $html = $css . $html;
        }

        if (preg_match('/<body\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            $offset = $match[0][1] + strlen($match[0][0]);

            return substr($html, 0, $offset) . $bar . substr($html, $offset);
        }

        return $bar . $html;
    }

    public function items(array $context): array
    {
        $items = [
            ['id' => 'brand', 'label' => 'Kivopress', 'href' => '/admin', 'position' => 0, 'side' => 'left', 'brand' => true],
            ['id' => 'dashboard', 'label' => 'Dashboard', 'href' => '/admin', 'position' => 10, 'side' => 'left'],
            ['id' => 'new-post', 'label' => 'New Post', 'href' => '/admin/content/post/new', 'position' => 20, 'side' => 'left'],
        ];

        if (is_array($context['content'] ?? null) && isset($context['content']['id'], $context['content']['type'])) {
            $items[] = [
                'id' => 'edit-content',
                'label' => 'Edit Content',
                'href' => '/admin/content/' . $context['content']['type'] . '/' . $context['content']['id'] . '/edit',
                'position' => 30,
                'side' => 'left',
            ];
        }

        $items[] = ['id' => 'user', 'label' => (string) ($context['user']['name'] ?? 'Admin'), 'position' => 90, 'side' => 'right', 'class' => 'kp-front-adminbar-user'];
        $items[] = ['id' => 'logout', 'html' => $this->logoutForm(), 'position' => 100, 'side' => 'right'];

        $items = apply_filters('admin_bar.items', $items, $context);
        usort($items, fn (array $a, array $b): int => ((int) ($a['position'] ?? 100)) <=> ((int) ($b['position'] ?? 100)));

        return $items;
    }

    private function html(array $context): string
    {
        $left = '';
        $right = '';

        foreach ($this->items($context) as $item) {
            $side = ($item['side'] ?? 'left') === 'right' ? 'right' : 'left';
            $rendered = $this->item($item);

            if ($side === 'right') {
                $right .= $rendered;
            } else {
                $left .= $rendered;
            }
        }

        return '<div class="kp-front-adminbar" role="navigation" aria-label="Kivopress admin bar">
            <div class="kp-front-adminbar-section kp-front-adminbar-left">' . $left . '</div>
            <div class="kp-front-adminbar-section kp-front-adminbar-right">' . $right . '</div>
        </div>';
    }

    private function item(array $item): string
    {
        if (isset($item['html'])) {
            return (string) $item['html'];
        }

        $class = trim('kp-front-adminbar-item ' . (string) ($item['class'] ?? '') . (!empty($item['brand']) ? ' kp-front-adminbar-brand' : ''));
        $label = !empty($item['brand']) ? '<span>K</span>' . e($item['label'] ?? '') : e($item['label'] ?? '');
        $href = (string) ($item['href'] ?? '');

        return $href !== ''
            ? '<a class="' . e($class) . '" href="' . e($href) . '">' . $label . '</a>'
            : '<span class="' . e($class) . '">' . $label . '</span>';
    }

    private function logoutForm(): string
    {
        return '<form method="post" action="/admin/logout" class="kp-front-adminbar-form"><input type="hidden" name="_csrf" value="' . e($this->app->auth()->csrfToken()) . '"><button>Sign Out</button></form>';
    }

    private function context(array $user, array $data): array
    {
        return [
            'user' => $user,
            'content' => $data['content'] ?? $data['page'] ?? $data['post'] ?? null,
            'data' => $data,
        ];
    }

    private function css(): string
    {
        return 'html{margin-top:38px}
.kp-front-adminbar{align-items:center;background:#141b21;border-bottom:1px solid #0d1317;color:#f8fafc;display:flex;font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;height:38px;inset:0 0 auto;justify-content:space-between;padding:0 16px;position:fixed;z-index:99999}
.kp-front-adminbar-section{align-items:center;display:flex;gap:6px;min-width:0}.kp-front-adminbar-left{overflow-x:auto}.kp-front-adminbar-right{flex:0 0 auto;margin-left:16px}
.kp-front-adminbar-item,.kp-front-adminbar button{align-items:center;background:transparent;border:0;border-radius:3px;color:#e7eef2;display:inline-flex;font:inherit;font-weight:650;height:30px;padding:0 10px;text-decoration:none;white-space:nowrap}
.kp-front-adminbar-item:hover,.kp-front-adminbar button:hover{background:#21312c;color:#fff}.kp-front-adminbar-brand{font-weight:800;padding-left:4px}.kp-front-adminbar-brand span{align-items:center;background:#d7f36b;border-radius:3px;color:#152318;display:inline-flex;height:22px;justify-content:center;margin-right:8px;width:22px}.kp-front-adminbar-user{color:#b8c7c1}.kp-front-adminbar-form{margin:0}.kp-front-adminbar+header .admin{display:none}
@media(max-width:640px){html{margin-top:42px}.kp-front-adminbar{height:42px;padding:0 10px}.kp-front-adminbar-user{display:none}.kp-front-adminbar-item,.kp-front-adminbar button{height:34px;padding:0 9px}}';
    }
}
