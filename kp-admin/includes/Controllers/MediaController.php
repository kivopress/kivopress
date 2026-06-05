<?php

declare(strict_types=1);

namespace Kivopress\Admin\Controllers;

use Kivopress\Response;

final class MediaController extends Controller
{
    public function index(): Response
    {
        if ($redirect = $this->guardMedia()) {
            return $redirect;
        }

        $type = (string) ($_GET['type'] ?? '');
        $items = $this->app->media()->all(['type' => $type, 'limit' => 120]);
        $html = $this->uploadPanel() . $this->filters($type) . $this->library($items);

        return $this->view->layout('Media', $html);
    }

    public function upload(): Response
    {
        if ($redirect = $this->guardPostCapability('upload_media')) {
            return $redirect;
        }

        $result = $this->app->media()->uploadMany($_FILES['media'] ?? [], $this->auth->user()['id'] ?? null);
        $count = count($result['created']);

        if ($count > 0) {
            $this->auth->flash('notice', $count . ' media file' . ($count === 1 ? '' : 's') . ' uploaded.');
        }

        if ($result['errors'] !== []) {
            $this->auth->flash('error', implode(' ', $result['errors']));
        }

        return Response::redirect('/admin/media');
    }

    public function edit(int $id): Response
    {
        if ($redirect = $this->guardCapability('manage_media')) {
            return $redirect;
        }

        $item = $this->app->media()->find($id);

        if (!$item) {
            return $this->view->layout('Not Found', '<p>Media item not found.</p>', true, 404);
        }

        return $this->view->layout('Edit Media', $this->detail($item));
    }

    public function update(int $id): Response
    {
        if ($redirect = $this->guardPostCapability('manage_media')) {
            return $redirect;
        }

        if (!$this->app->media()->update($id, $_POST)) {
            return $this->view->layout('Not Found', '<p>Media item not found.</p>', true, 404);
        }

        $this->auth->flash('notice', 'Media details saved.');

        return Response::redirect('/admin/media/' . $id . '/edit');
    }

    public function delete(int $id): Response
    {
        if ($redirect = $this->guardPostCapability('manage_media')) {
            return $redirect;
        }

        $this->app->media()->delete($id);
        $this->auth->flash('notice', 'Media deleted.');

        return Response::redirect('/admin/media');
    }

    private function uploadPanel(): string
    {
        return '<section class="kp-panel">
            <div class="kp-panel-head">
                <div><h2>Upload Media</h2><p>Images, PDFs, audio, and video are stored in dated folders with database metadata.</p></div>
            </div>
            <form method="post" action="/admin/media/upload" enctype="multipart/form-data" class="kp-upload-form">
                ' . $this->view->csrfField() . '
                <input type="file" name="media[]" multiple required accept="image/*,application/pdf,text/plain,text/csv,audio/*,video/*">
                <button>' . $this->view->icon('upload') . 'Upload</button>
            </form>
        </section>';
    }

    private function guardMedia(): ?Response
    {
        if ($redirect = $this->auth->requireAdmin()) {
            return $redirect;
        }

        return ($this->auth->can('upload_media') || $this->auth->can('manage_media')) ? null : $this->forbidden();
    }

    private function filters(string $active): string
    {
        $filters = [
            '' => 'All',
            'image' => 'Images',
            'document' => 'Documents',
            'audio' => 'Audio',
            'video' => 'Video',
        ];
        $html = '<div class="kp-media-filters">';

        foreach ($filters as $value => $label) {
            $href = $value === '' ? '/admin/media' : '/admin/media?type=' . $value;
            $html .= '<a class="' . ($active === $value ? 'is-active' : '') . '" href="' . $href . '">' . \e($label) . '</a>';
        }

        return $html . '</div>';
    }

    private function library(array $items): string
    {
        if ($items === []) {
            return '<section class="kp-panel kp-empty">No media files yet.</section>';
        }

        $cards = '';

        foreach ($items as $item) {
            $thumb = $item['is_image']
                ? '<img src="' . \e($item['url']) . '" alt="' . \e($item['alt'] ?: $item['title']) . '">'
                : $this->view->icon($this->iconName($item['kind']));

            $meta = $item['is_image'] && $item['width'] && $item['height']
                ? $item['width'] . ' x ' . $item['height'] . ' px'
                : strtoupper($item['extension']);

            $cards .= '<article class="kp-media-card">
                <a class="kp-media-thumb" href="/admin/media/' . $item['id'] . '/edit">' . $thumb . '</a>
                <div class="kp-media-body">
                    <a class="kp-link-strong" href="/admin/media/' . $item['id'] . '/edit">' . \e($item['title']) . '</a>
                    <div class="kp-muted">' . \e($meta) . ' &middot; ' . \e($this->humanSize($item['size'])) . '</div>
                    <div class="kp-row-actions">
                        <a class="kp-row-action" href="/admin/media/' . $item['id'] . '/edit">' . $this->view->icon('edit') . 'Edit</a>
                        <button type="button" class="kp-row-action" data-kp-copy="' . \e($item['url']) . '">' . $this->view->icon('content_copy') . 'Copy</button>
                    </div>
                </div>
            </article>';
        }

        return '<section class="kp-media-grid">' . $cards . '</section>' . $this->clipboardScript();
    }

    private function detail(array $item): string
    {
        $preview = $item['is_image']
            ? '<img src="' . \e($item['url']) . '" alt="' . \e($item['alt'] ?: $item['title']) . '">'
            : $this->view->icon($this->iconName($item['kind']));

        $facts = [
            'File' => $item['original_name'],
            'Type' => $item['mime'],
            'Size' => $this->humanSize($item['size']),
            'Uploaded' => $item['created_at'],
            'URL' => $item['url'],
        ];

        if ($item['width'] && $item['height']) {
            $facts['Dimensions'] = $item['width'] . ' x ' . $item['height'] . ' px';
        }

        $factHtml = '';

        foreach ($facts as $label => $value) {
            $factHtml .= '<dt>' . \e($label) . '</dt><dd>' . \e($value) . '</dd>';
        }

        return '<div class="kp-media-detail">
            <section class="kp-panel">
                <div class="kp-media-preview">' . $preview . '</div>
                <div class="kp-media-detail-actions">
                    <a class="kp-button kp-button-secondary" href="' . \e($item['url']) . '" target="_blank" rel="noopener">' . $this->view->icon('open_in_new') . 'Open</a>
                    <button type="button" class="kp-button kp-button-secondary" data-kp-copy="' . \e($item['url']) . '">' . $this->view->icon('content_copy') . 'Copy URL</button>
                </div>
                <dl class="kp-media-facts">' . $factHtml . '</dl>
            </section>
            <section class="kp-panel">
                ' . $this->view->form('/admin/media/' . $item['id'], '
                    <label>Title<input name="title" value="' . \e($item['title']) . '" required></label>
                    <label>Alt Text<input name="alt" value="' . \e($item['alt']) . '"></label>
                    <label>Caption<textarea name="caption" rows="4">' . \e($item['caption']) . '</textarea></label>
                    <button>Save Details</button>
                ') . '
                <form method="post" action="/admin/media/' . $item['id'] . '/delete" class="kp-delete-form" onsubmit="return confirm(\'Delete this media file permanently?\')">
                    ' . $this->view->csrfField() . '
                    <button class="kp-button kp-button-danger">' . $this->view->icon('delete') . 'Delete Permanently</button>
                </form>
            </section>
        </div>' . $this->clipboardScript();
    }

    private function clipboardScript(): string
    {
        return '<script>
document.querySelectorAll("[data-kp-copy]").forEach(function (button) {
    button.addEventListener("click", function () {
        var value = button.dataset.kpCopy || "";
        if (navigator.clipboard) navigator.clipboard.writeText(value);
        button.dataset.copied = "1";
        window.setTimeout(function () { delete button.dataset.copied; }, 1300);
    });
});
</script>';
    }

    private function iconName(string $kind): string
    {
        return match ($kind) {
            'audio' => 'audio_file',
            'video' => 'movie',
            default => 'description',
        };
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1024, 1) . ' KB';
    }
}
