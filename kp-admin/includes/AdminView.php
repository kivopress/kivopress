<?php

declare(strict_types=1);

namespace Kivopress\Admin;

use Kivopress\App;
use Kivopress\Auth;
use Kivopress\Content;
use Kivopress\Response;

final class AdminView
{
    private AdminTemplates $templates;

    public function __construct(private App $app, private Auth $auth, private Content $content)
    {
        $this->templates = new AdminTemplates($app);
    }

    public function layout(string $title, string $body, bool $nav = true, int $status = 200): Response
    {
        \do_action('admin.enqueue_assets', $title, $nav);

        $notice = $this->auth->flash('notice');
        $error = $this->auth->flash('error');
        $noticeHtml = $notice ? '<div class="kp-notice">' . \e($notice) . '</div>' : '';
        $noticeHtml .= $error ? '<div class="kp-notice kp-notice-error">' . \e($error) . '</div>' : '';
        $class = $nav ? 'kp-admin' : 'kp-auth';
        $head = $nav ? apply_filters('admin.head', '') : '';
        $footer = $nav ? apply_filters('admin.footer', '') : '';
        $styles = $nav ? $this->adminStyles() : '';
        $inlineCss = $nav ? $this->adminInlineCss() : '';
        $scripts = $nav ? $this->adminScripts() : '';
        $themeClass = $nav ? 'kp-theme-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $this->app->theme()->active()) : '';
        $bodyClass = trim($class . ' ' . $themeClass . ' ' . ($nav ? (string) apply_filters('admin.body_class', '', $title) : ''));
        $screen = [
            'title' => $title,
            'nav' => $nav,
            'status' => $status,
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
        ];

        $html = $this->template('layout', compact(
            'title',
            'body',
            'nav',
            'noticeHtml',
            'head',
            'footer',
            'styles',
            'inlineCss',
            'scripts',
            'bodyClass',
            'screen'
        ));

        return Response::html($html, $status);
    }

    public function template(string $template, array $data = []): string
    {
        return $this->templates->render($template, $data + [
            'view' => $this,
            'app' => $this->app,
            'auth' => $this->auth,
            'contentRepository' => $this->content,
        ]);
    }

    public function form(string $action, string $fields, bool $csrf = true, string $class = 'kp-form', string $attributes = ''): string
    {
        return '<form method="post" action="' . \e($action) . '" class="' . \e($class) . '" ' . $attributes . '>' . ($csrf ? $this->csrfField() : '') . $fields . '</form>';
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . \e($this->auth->csrfToken()) . '">';
    }

    public function optionTags(array $options, string $selected): string
    {
        $html = '';

        foreach ($options as $option) {
            $html .= '<option value="' . \e($option) . '" ' . ($option === $selected ? 'selected' : '') . '>' . \e(ucfirst($option)) . '</option>';
        }

        return $html;
    }

    public function editorInput(string $name, string $value, string $label): string
    {
        static $index = 0;

        $index++;
        $id = 'kp-editor-' . $index;
        $inputId = $id . '-input';

        $labelHtml = trim($label) !== '' ? '<label class="kp-editor-label">' . \e($label) . '</label>' : '';

        return $labelHtml . '
            <textarea id="' . $inputId . '" name="' . \e($name) . '" class="kp-editor-input">' . \e($value) . '</textarea>
            ' . $this->editorMediaTools($id) . '
            <div id="' . $id . '" class="kp-rich-editor" data-kp-quill data-input="#' . $inputId . '"></div>';
    }

    public function queueEditorAssets(): void
    {
        add_filter('admin.head', fn (string $head): string => $head . '
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<link rel="stylesheet" href="/kp-admin/assets/kivopress-editor.css">');

        add_filter('admin.footer', fn (string $footer): string => $footer . $this->mediaModalHtml() . '
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
window.kpQuillEditors = window.kpQuillEditors || {};

function kpEscapeHtml(value) {
    return String(value || "").replace(/[&<>"\']/g, function (char) {
        return {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "\'": "&#039;"}[char];
    });
}

document.querySelectorAll("[data-kp-quill]").forEach(function (node) {
    var input = document.querySelector(node.dataset.input);
    if (!input || node.dataset.ready) return;
    node.dataset.ready = "1";

    var quill = new Quill(node, {
        theme: "snow",
        modules: {
            toolbar: [
                [{ header: [2, 3, false] }],
                ["bold", "italic", "underline"],
                ["blockquote", "code-block"],
                [{ list: "ordered" }, { list: "bullet" }],
                ["link", "clean"]
            ]
        }
    });

    quill.root.innerHTML = input.value || "";
    var sync = function () { input.value = quill.root.innerHTML; };
    quill.on("text-change", sync);
    if (node.closest("form")) node.closest("form").addEventListener("submit", sync);
    window.kpQuillEditors[node.id] = { editor: quill, sync: sync };
});

document.querySelectorAll("[data-kp-copy]").forEach(function (button) {
    button.addEventListener("click", function () {
        var value = button.dataset.kpCopy || "";
        if (navigator.clipboard) navigator.clipboard.writeText(value);
        button.dataset.copied = "1";
        window.setTimeout(function () { delete button.dataset.copied; }, 1300);
    });
});

(function () {
    var modal = document.querySelector("[data-kp-media-modal]");
    if (!modal) return;

    var active = {};
    var close = function () {
        modal.hidden = true;
        active = {};
    };

    document.querySelectorAll("[data-kp-media-open]").forEach(function (button) {
        button.addEventListener("click", function () {
            active = {
                editor: button.dataset.kpMediaEditor || "",
                input: button.dataset.kpMediaInput || ""
            };
            modal.hidden = false;
            var first = modal.querySelector("[data-kp-media-choose]");
            if (first) first.focus();
        });
    });

    modal.querySelectorAll("[data-kp-media-close]").forEach(function (button) {
        button.addEventListener("click", close);
    });

    modal.addEventListener("click", function (event) {
        if (event.target === modal) close();
    });

    modal.querySelectorAll("[data-kp-media-choose]").forEach(function (button) {
        button.addEventListener("click", function () {
            var data = button.dataset;

            if (active.editor && window.kpQuillEditors[active.editor.replace("#", "")]) {
                var entry = window.kpQuillEditors[active.editor.replace("#", "")];
                var quill = entry.editor;
                var range = quill.getSelection(true) || { index: quill.getLength(), length: 0 };
                var title = data.kpTitle || "Media";

                if ((data.kpMime || "").indexOf("image/") === 0) {
                    quill.clipboard.dangerouslyPasteHTML(range.index, "<img src=\\"" + kpEscapeHtml(data.kpUrl) + "\\" alt=\\"" + kpEscapeHtml(data.kpAlt || title) + "\\">");
                } else {
                    quill.insertText(range.index, title, "link", data.kpUrl);
                }
                entry.sync();
            }

            if (active.input) {
                var input = document.querySelector(active.input);
                var field = input ? input.closest(".kp-media-picker-field") : null;
                var preview = field ? field.querySelector("[data-kp-media-preview]") : null;
                if (input) input.value = data.kpId || "";
                if (preview) preview.innerHTML = (data.kpMime || "").indexOf("image/") === 0
                    ? "<img src=\\"" + kpEscapeHtml(data.kpUrl) + "\\" alt=\\"" + kpEscapeHtml(data.kpAlt || data.kpTitle || "Media") + "\\">"
                    : "<span>" + kpEscapeHtml(data.kpTitle || "Media selected") + "</span>";
            }

            close();
        });
    });

    document.querySelectorAll("[data-kp-media-clear]").forEach(function (button) {
        button.addEventListener("click", function () {
            var input = document.querySelector(button.dataset.kpMediaInput || "");
            var field = input ? input.closest(".kp-media-picker-field") : null;
            var preview = field ? field.querySelector("[data-kp-media-preview]") : null;
            if (input) input.value = "";
            if (preview) preview.innerHTML = "<span>No image selected</span>";
        });
    });
})();

document.querySelectorAll("[data-kp-meta-box]").forEach(function (box) {
    var button = box.querySelector(".kp-meta-box-toggle");
    var body = button ? document.getElementById(button.getAttribute("aria-controls")) : null;
    if (!button || !body) return;

    var storageKey = "kivopress.metabox." + box.dataset.kpMetaBox;
    var stored = null;

    try {
        stored = window.localStorage.getItem(storageKey);
    } catch (error) {}

    var setCollapsed = function (collapsed) {
        box.dataset.kpCollapsed = collapsed ? "1" : "0";
        body.hidden = collapsed;
        button.setAttribute("aria-expanded", collapsed ? "false" : "true");

        try {
            window.localStorage.setItem(storageKey, collapsed ? "1" : "0");
        } catch (error) {}
    };

    if (stored === "1" || stored === "0") {
        setCollapsed(stored === "1");
    } else if (box.dataset.kpCollapsed === "1") {
        setCollapsed(true);
    }

    button.addEventListener("click", function () {
        setCollapsed(box.dataset.kpCollapsed !== "1");
    });
});
</script>');
    }

    private function adminStyles(): string
    {
        $themeStyle = 'kp-content/themes/' . $this->app->theme()->active() . '/admin.css';
        $styles = apply_filters('admin.styles', []);

        if (!is_array($styles)) {
            $styles = [];
        }

        if (is_file($this->app->path($themeStyle))) {
            array_unshift($styles, '/' . $themeStyle);
        }

        $html = '';

        foreach (array_unique(array_filter($styles, 'is_string')) as $href) {
            $html .= '<link rel="stylesheet" href="' . \e($href) . '">' . "\n";
        }

        return $html;
    }

    private function adminInlineCss(): string
    {
        $css = trim((string) apply_filters('admin.inline_css', ''));

        return $css !== '' ? '<style>' . $css . '</style>' . "\n" : '';
    }

    private function adminScripts(): string
    {
        $scripts = apply_filters('admin.scripts', []);

        if (!is_array($scripts)) {
            return '';
        }

        $html = '';

        foreach ($scripts as $script) {
            if (is_string($script)) {
                $script = ['src' => $script];
            }

            if (!is_array($script) || !is_string($script['src'] ?? '') || trim((string) $script['src']) === '') {
                continue;
            }

            $attributes = is_array($script['attributes'] ?? null) ? $script['attributes'] : [];
            $attr = '';

            foreach ($attributes as $name => $value) {
                $name = preg_replace('/[^a-zA-Z0-9_:-]+/', '', (string) $name);

                if ($name === '') {
                    continue;
                }

                if ($value === true) {
                    $attr .= ' ' . $name;
                    continue;
                }

                if ($value === false || $value === null) {
                    continue;
                }

                $attr .= ' ' . $name . '="' . \e($value) . '"';
            }

            $html .= '<script src="' . \e($script['src']) . '"' . $attr . '></script>' . "\n";
        }

        return $html;
    }

    private function editorMediaTools(string $editorId): string
    {
        return '<div class="kp-editor-tools">
            <button type="button" class="kp-button kp-button-secondary" data-kp-media-open data-kp-media-editor="#' . \e($editorId) . '">' . $this->icon('add_photo_alternate') . 'Add Media</button>
            <a class="kp-button kp-button-secondary" href="/admin/media">' . $this->icon('perm_media') . 'Library</a>
        </div>';
    }

    private function mediaModalHtml(): string
    {
        $items = $this->app->media()->all(['limit' => 120]);
        $cards = '';

        foreach ($items as $item) {
            $label = $item['title'] ?: $item['original_name'];
            $thumb = $item['is_image']
                ? '<img src="' . \e($item['url']) . '" alt="' . \e($item['alt'] ?: $label) . '">'
                : $this->icon($item['kind'] === 'video' ? 'movie' : ($item['kind'] === 'audio' ? 'audio_file' : 'description'));
            $cards .= '<button type="button" class="kp-media-modal-card" data-kp-media-choose data-kp-id="' . $item['id'] . '" data-kp-url="' . \e($item['url']) . '" data-kp-title="' . \e($label) . '" data-kp-alt="' . \e($item['alt']) . '" data-kp-mime="' . \e($item['mime']) . '">
                <span>' . $thumb . '</span><strong>' . \e($label) . '</strong>
            </button>';
        }

        if ($cards === '') {
            $cards = '<p class="kp-empty">No media files yet.</p>';
        }

        return '<div class="kp-media-modal" data-kp-media-modal hidden>
            <section class="kp-media-modal-dialog" role="dialog" aria-modal="true" aria-label="Media Library">
                <header><div><h2>Media Library</h2><p>Select an asset for the editor or featured image.</p></div><button type="button" class="kp-row-action" data-kp-media-close>Close</button></header>
                <div class="kp-media-modal-grid">' . $cards . '</div>
                <footer><a class="kp-button kp-button-secondary" href="/admin/media">' . $this->icon('upload') . 'Upload New</a></footer>
            </section>
        </div>';
    }

    public function adminShell(string $title, string $body): string
    {
        return $this->template('admin-shell', compact('title', 'body'));
    }

    public function authShell(string $title, string $body): string
    {
        return $this->template('auth-shell', compact('title', 'body'));
    }

    public function sidebar(): string
    {
        $items = $this->arrangeNavItems($this->app->adminMenu()->items());
        $current = parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH) ?: '/admin';
        $links = '<aside class="kp-sidebar"><nav>';

        foreach ($items as $item) {
            $links .= $this->navItem($item, $current);
        }

        return $links . '</nav></aside>';
    }

    private function arrangeNavItems(array $items): array
    {
        $top = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $parent = (string) ($item['parent'] ?? '');

            if ($parent === '') {
                $top[] = $item;
                continue;
            }

            foreach ($top as &$candidate) {
                if ((string) ($candidate['href'] ?? '') === $parent) {
                    $candidate['children'] ??= [];
                    $candidate['children'][] = $item;
                    continue 2;
                }
            }

            $top[] = $item;
        }

        return $this->sortNavItems($top);
    }

    private function sortNavItems(array $items): array
    {
        usort($items, fn (array $a, array $b): int => ((int) ($a['position'] ?? 100)) <=> ((int) ($b['position'] ?? 100)) ?: strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));

        foreach ($items as &$item) {
            if (!empty($item['children']) && is_array($item['children'])) {
                $item['children'] = $this->sortNavItems($item['children']);
            }
        }

        return $items;
    }

    private function navItem(array $item, string $current): string
    {
        $href = (string) ($item['href'] ?? '#');
        $children = is_array($item['children'] ?? null) ? $item['children'] : [];
        $active = $this->navItemActive($item, $current);

        if ($children === []) {
            return '<a href="' . \e($href) . '" class="' . ($active ? 'is-active' : '') . '">' . $this->icon((string) ($item['icon'] ?? 'radio_button_unchecked')) . \e($item['label'] ?? '') . '</a>';
        }

        $panelId = 'kp-nav-' . substr(md5($href), 0, 10);
        $html = '<div class="kp-nav-group" data-kp-nav-group data-kp-open="' . ($active ? '1' : '0') . '">
            <a href="' . \e($href) . '" class="kp-nav-parent ' . ($active ? 'is-active' : '') . '" aria-haspopup="true" aria-expanded="' . ($active ? 'true' : 'false') . '" aria-controls="' . \e($panelId) . '">
                <span>' . $this->icon((string) ($item['icon'] ?? 'radio_button_unchecked')) . \e($item['label'] ?? '') . '</span>
                <span class="kp-nav-caret">' . $this->icon('expand_more') . '</span>
            </a>
            <div class="kp-sidebar-subnav" id="' . \e($panelId) . '">
                <a href="' . \e($href) . '" class="' . ($current === $href ? 'is-active' : '') . '">' . \e($this->allLabel((string) ($item['label'] ?? 'Items'))) . '</a>';

        foreach ($children as $child) {
            $childHref = (string) ($child['href'] ?? '#');
            $childActive = $current === $childHref || ($childHref !== '/admin' && str_starts_with($current, $childHref . '/'));
            $html .= '<a href="' . \e($childHref) . '" class="' . ($childActive ? 'is-active' : '') . '">' . \e($child['label'] ?? '') . '</a>';
        }

        return $html . '</div></div>';
    }

    private function allLabel(string $label): string
    {
        return str_starts_with($label, 'All ') ? $label : 'All ' . $label;
    }

    private function navItemActive(array $item, string $current): bool
    {
        $href = (string) ($item['href'] ?? '#');

        if ($current === $href || ($href !== '/admin' && str_starts_with($current, $href . '/'))) {
            return true;
        }

        foreach ((array) ($item['children'] ?? []) as $child) {
            $childHref = (string) ($child['href'] ?? '#');

            if ($current === $childHref || ($childHref !== '/admin' && str_starts_with($current, $childHref . '/'))) {
                return true;
            }
        }

        return false;
    }

    private function canManageTaxonomy(array $taxonomy): bool
    {
        foreach ($taxonomy['content_types'] ?? [] as $typeName) {
            $type = $this->content->type((string) $typeName);

            if ($type && $this->auth->can('edit_' . $type['api_slug'])) {
                return true;
            }
        }

        return $this->auth->can('manage_settings');
    }

    public function icon(string $name): string
    {
        $name = preg_replace('/[^a-z0-9_\-]+/i', '', $name) ?: 'radio_button_unchecked';
        $path = $this->iconPath($name);

        return '<span class="kp-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false">' . $path . '</svg></span>';
    }

    public function logoutForm(): string
    {
        if (!$this->auth->user()) {
            return '';
        }

        return '<form method="post" action="/admin/logout" class="kp-logout">' . $this->csrfField() . '<button>' . $this->icon('logout') . 'Sign Out</button></form>';
    }

    private function iconPath(string $name): string
    {
        return match ($name) {
            'dashboard' => '<path d="M4 4h6v6H4V4Zm10 0h6v6h-6V4ZM4 14h6v6H4v-6Zm10 0h6v6h-6v-6Z"/>',
            'article' => '<path d="M6 3h9l4 4v14H6V3Zm8 1.8V8h3.2L14 4.8ZM8 11v1.6h8V11H8Zm0 4v1.6h8V15H8Zm0-8v1.6h4V7H8Z"/>',
            'description' => '<path d="M6 3h9l4 4v14H6V3Zm8 1.8V8h3.2L14 4.8ZM8 12v1.6h8V12H8Zm0 4v1.6h6V16H8Z"/>',
            'category' => '<path d="M4 5h7l2 3h7v11H4V5Zm2 5v7h12v-7H6Z"/>',
            'tag' => '<path d="M4 4h9l7 7-9 9-7-7V4Zm3 2v6.2l4 4 6.2-6.2-4-4H7Zm2.2 3.5a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6Z"/>',
            'perm_media' => '<path d="M4 5h6l2 2h8v12H4V5Zm2 4v8h12V9H6Zm1.5 6 2.6-3.2 1.8 2.1 1.2-1.5 2.4 2.6h-8Z"/>',
            'palette' => '<path d="M12 3a9 9 0 0 0 0 18h1.2c1.3 0 2.1-1.4 1.4-2.5-.4-.7.1-1.5.9-1.5H17a4 4 0 0 0 4-4c0-5.5-4.1-10-9-10Zm-4 9.5a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8Zm3-4a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8Zm4 0a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8Zm2.2 4a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8Z"/>',
            'extension' => '<path d="M9 3h6v4h2a3 3 0 1 1 0 6h-2v2h4v6H5v-6h4v-3H6a3 3 0 0 1 0-6h3V3Zm2 2v4H6a1 1 0 1 0 0 2h5v6H7v2h10v-2h-4V11h4a1 1 0 1 0 0-2h-4V5h-2Z"/>',
            'build' => '<path d="M21 6.5a5.5 5.5 0 0 1-7.4 5.2l-6.7 6.7a2 2 0 0 1-2.8-2.8l6.7-6.7A5.5 5.5 0 0 1 17.5 2l-3 3 2.5 2.5 3-3c.6.5 1 1.2 1 2Z"/>',
            'api' => '<path d="M4 5h16v14H4V5Zm2 2v10h12V7H6Zm2 7.5 2-5h1.8l2 5h-1.5l-.3-.9H9.8l-.3.9H8Zm2.2-2.1h1.4l-.7-2-.7 2Zm4.2 2.1v-5H16c1.1 0 2 .8 2 1.9s-.9 1.9-2 1.9h-.2v1.2h-1.4Zm1.4-2.5h.2c.4 0 .6-.2.6-.6s-.2-.6-.6-.6h-.2V12Z"/>',
            'settings' => '<path d="M19.4 13.5c.1-.5.1-1 .1-1.5s0-1-.1-1.5l2-1.5-2-3.5-2.4 1a7.6 7.6 0 0 0-2.6-1.5L14 2h-4l-.4 2.5A7.6 7.6 0 0 0 7 6L4.6 5l-2 3.5 2 1.5c-.1.5-.1 1-.1 1.5s0 1 .1 1.5l-2 1.5 2 3.5 2.4-1a7.6 7.6 0 0 0 2.6 1.5L10 22h4l.4-2.5A7.6 7.6 0 0 0 17 18l2.4 1 2-3.5-2-1.5ZM12 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z"/>',
            'search' => '<path d="M10.5 4a6.5 6.5 0 0 1 5.1 10.5l4 4-1.4 1.4-4-4A6.5 6.5 0 1 1 10.5 4Zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Z"/>',
            'refresh' => '<path d="M17.7 6.3A8 8 0 1 0 20 12h-2a6 6 0 1 1-1.8-4.2L13 11h8V3l-3.3 3.3Z"/>',
            'sitemap' => '<path d="M11 3h2v5h6v5h-2v-3h-4v3h-2v-3H7v3H5V8h6V3ZM3 15h6v6H3v-6Zm2 2v2h2v-2H5Zm6-2h6v6h-6v-6Zm2 2v2h2v-2h-2Zm6-2h6v6h-6v-6Zm2 2v2h2v-2h-2Z"/>',
            'group' => '<path d="M8.5 11a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Zm7-1a3 3 0 1 1 0-6 3 3 0 0 1 0 6ZM2 20c.7-4.1 3-6.5 6.5-6.5S14.3 15.9 15 20H2Zm12.4-6.3c3 .3 5 2.4 5.6 6.3h-3.1a9.7 9.7 0 0 0-2.5-6.3Z"/>',
            'person_add' => '<path d="M9 12a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm0 2c3.5 0 6 2.4 6.7 6H2.3c.7-3.6 3.2-6 6.7-6Zm9-3V8h-3V6h3V3h2v3h3v2h-3v3h-2Z"/>',
            'open_in_new' => '<path d="M5 5h8v2H7v10h10v-6h2v8H5V5Zm10 0h4v4h-2V8.4l-6.3 6.3-1.4-1.4L15.6 7H15V5Z"/>',
            'link' => '<path d="M8.5 13.5 7.1 12l5-5a4 4 0 0 1 5.7 5.7l-1.6 1.6-1.4-1.4 1.6-1.6a2 2 0 1 0-2.8-2.8l-5 5Zm-2.3 3.3a2 2 0 0 0 2.8 0l5-5 1.4 1.4-5 5a4 4 0 0 1-5.7-5.7l1.6-1.6 1.4 1.4-1.6 1.6a2 2 0 0 0 0 2.9Z"/>',
            'visibility' => '<path d="M12 5c5 0 8.7 4.2 9.8 7-1.1 2.8-4.8 7-9.8 7s-8.7-4.2-9.8-7C3.3 9.2 7 5 12 5Zm0 2c-3.6 0-6.4 2.7-7.6 5 1.2 2.3 4 5 7.6 5s6.4-2.7 7.6-5C18.4 9.7 15.6 7 12 7Zm0 2a3 3 0 1 1 0 6 3 3 0 0 1 0-6Z"/>',
            'calendar' => '<path d="M7 2h2v3h6V2h2v3h3v16H4V5h3V2Zm11 8H6v9h12v-9ZM6 7v1h12V7H6Z"/>',
            'account_circle' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 3.5a3 3 0 1 1 0 6 3 3 0 0 1 0-6Zm0 14.2a7.8 7.8 0 0 1-5.8-2.6c1.3-2 3.4-3.1 5.8-3.1s4.5 1.1 5.8 3.1a7.8 7.8 0 0 1-5.8 2.6Z"/>',
            'logout' => '<path d="M5 4h8v2H7v12h6v2H5V4Zm10.6 4.4 1.4-1.4 5 5-5 5-1.4-1.4 2.6-2.6H11v-2h7.2l-2.6-2.6Z"/>',
            'add' => '<path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/>',
            'add_photo_alternate' => '<path d="M4 5h10v2H6v10h12v-6h2v8H4V5Zm13 0V2h2v3h3v2h-3v3h-2V7h-3V5h3ZM7.5 15l2.5-3 1.7 2 1.2-1.5 3.1 3.5H7.5Z"/>',
            'upload' => '<path d="M11 16V7.8l-3.3 3.3-1.4-1.4L12 4l5.7 5.7-1.4 1.4L13 7.8V16h-2ZM5 18h14v2H5v-2Z"/>',
            'audio_file' => '<path d="M6 3h9l4 4v14H6V3Zm8 1.8V8h3.2L14 4.8ZM11 17a2 2 0 1 1-1-1.7V10h5v2h-3v5Z"/>',
            'movie' => '<path d="M4 5h16v14H4V5Zm3 2H6v2h1V7Zm0 4H6v2h1v-2Zm0 4H6v2h1v-2Zm10-8h-6v10h6V7Zm1 0v2h1V7h-1Zm0 4v2h1v-2h-1Zm0 4v2h1v-2h-1Z"/>',
            'edit' => '<path d="M5 17.2V20h2.8l8.8-8.8-2.8-2.8L5 17.2ZM18.7 9.1 15.9 6.3l1.4-1.4a1 1 0 0 1 1.4 0l1.4 1.4a1 1 0 0 1 0 1.4l-1.4 1.4Z"/>',
            'content_copy' => '<path d="M8 7h11v14H8V7Zm2 2v10h7V9h-7ZM5 3h11v2H7v11H5V3Z"/>',
            'delete' => '<path d="M7 7h10l-.7 14H7.7L7 7Zm2-3h6l1 2H8l1-2Zm-3 2h12v2H6V6Z"/>',
            'save' => '<path d="M5 4h12l2 2v14H5V4Zm2 2v12h10V8.8L14.2 6H14v5H8V6H7Zm3 0v3h2V6h-2Zm-1 8h6v2H9v-2Z"/>',
            'expand_more' => '<path d="m7.4 8.6 4.6 4.6 4.6-4.6L18 10l-6 6-6-6 1.4-1.4Z"/>',
            'radio_button_unchecked' => '<path d="M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>',
            default => '<path d="M5 4h14v16H5V4Zm2 2v12h10V6H7Z"/>',
        };
    }
}
