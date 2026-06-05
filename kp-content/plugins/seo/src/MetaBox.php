<?php

declare(strict_types=1);

namespace KivopressSeo;

final class MetaBox
{
    public function __construct(private Settings $settings, private Analyzer $analyzer, private Frontend $frontend)
    {
    }

    public function field(string $html, string $name, array $field, mixed $value, string $fieldName): string
    {
        if (!str_starts_with($name, 'seo_')) {
            return $html;
        }

        $value = (string) $value;

        return match ($name) {
            'seo_title' => '<label class="kp-field kp-seo-field"><span class="kp-field-label">SEO Title</span><input name="' . \e($fieldName) . '" value="' . \e($value) . '" maxlength="70" data-kp-seo-title><span class="kp-seo-counter"><strong data-kp-seo-title-count>0</strong>/60 characters</span></label>',
            'seo_description' => '<label class="kp-field kp-seo-field"><span class="kp-field-label">Meta Description</span><textarea name="' . \e($fieldName) . '" rows="4" maxlength="320" data-kp-seo-description>' . \e($value) . '</textarea><span class="kp-seo-counter"><strong data-kp-seo-description-count>0</strong>/160 characters</span></label>',
            'seo_canonical' => '<label class="kp-field kp-seo-field"><span class="kp-field-label">Canonical URL</span><input type="url" name="' . \e($fieldName) . '" value="' . \e($value) . '" placeholder="https://example.com/canonical-page" data-kp-seo-canonical><span class="kp-field-description" data-kp-seo-canonical-message>Leave empty to use this content URL.</span></label>',
            'seo_noindex' => '<label class="kp-seo-toggle"><input type="checkbox" name="' . \e($fieldName) . '" value="1" ' . ($value ? 'checked' : '') . '><span></span><strong>Hide from search engines</strong><small>Add noindex,nofollow robots meta for this content.</small></label>',
            default => '',
        };
    }

    public function decorate(array $boxes, array $schema, ?array $item): array
    {
        foreach ($boxes as &$box) {
            if (($box['id'] ?? '') !== 'seo') {
                continue;
            }

            $box['title'] = 'Kivopress SEO';
            $box['class'] = trim(($box['class'] ?? '') . ' kp-seo-box');
            $box['html'] = $this->preview($item) . '<div class="kp-seo-fields">' . $box['html'] . '</div>';
        }

        return $boxes;
    }

    public function script(): string
    {
        return <<<'HTML'
<script>
document.querySelectorAll("[data-kp-seo-preview]").forEach(function (preview) {
    var box = preview.closest(".kp-seo-box");
    if (!box) return;

    var titleInput = box.querySelector("[data-kp-seo-title]");
    var descriptionInput = box.querySelector("[data-kp-seo-description]");
    var canonicalInput = box.querySelector("[data-kp-seo-canonical]");
    var canonicalMessage = box.querySelector("[data-kp-seo-canonical-message]");
    var titlePreview = box.querySelector("[data-kp-seo-preview-title]");
    var descriptionPreview = box.querySelector("[data-kp-seo-preview-description]");
    var urlPreview = box.querySelector("[data-kp-seo-preview-url]");
    var titleCount = box.querySelector("[data-kp-seo-title-count]");
    var descriptionCount = box.querySelector("[data-kp-seo-description-count]");
    var score = box.querySelector("[data-kp-seo-score]");
    var checks = box.querySelector("[data-kp-seo-checks]");

    var setCheck = function (label, passed, message) {
        return '<li data-state="' + (passed ? 'good' : 'warn') + '"><strong>' + label + '</strong><span>' + message + '</span></li>';
    };

    var update = function () {
        var title = titleInput && titleInput.value.trim() ? titleInput.value.trim() : titlePreview.dataset.fallback || titlePreview.textContent;
        var description = descriptionInput && descriptionInput.value.trim() ? descriptionInput.value.trim() : descriptionPreview.dataset.fallback || descriptionPreview.textContent;
        var canonical = canonicalInput ? canonicalInput.value.trim() : "";
        var titleOk = title.length >= 30 && title.length <= 60;
        var descriptionOk = description.length >= 120 && description.length <= 160;
        var canonicalOk = !canonical || /^https?:\/\/.+\..+/.test(canonical);
        var passed = [titleOk, descriptionOk, canonicalOk].filter(Boolean).length;
        var percent = Math.round((passed / 3) * 100);

        if (titlePreview) titlePreview.textContent = title || "SEO title preview";
        if (descriptionPreview) descriptionPreview.textContent = description || "Write a meta description to control the search snippet.";
        if (canonical && urlPreview) urlPreview.textContent = canonical;
        if (titleCount && titleInput) titleCount.textContent = titleInput.value.length;
        if (descriptionCount && descriptionInput) descriptionCount.textContent = descriptionInput.value.length;
        if (canonicalMessage) {
            canonicalMessage.textContent = canonicalOk ? "Valid canonical URL." : "Use a full http:// or https:// URL.";
            canonicalMessage.dataset.state = canonicalOk ? "good" : "warn";
        }
        if (score) {
            score.textContent = percent >= 80 ? "SEO score: Good" : (percent >= 50 ? "SEO score: Needs work" : "SEO score: Not available");
            score.dataset.state = percent >= 80 ? "good" : (percent >= 50 ? "ok" : "warn");
        }
        if (checks) {
            checks.innerHTML = [
                setCheck("SEO title", titleOk, "30-60 characters"),
                setCheck("Meta description", descriptionOk, "120-160 characters"),
                setCheck("Canonical URL", canonicalOk, "Valid or empty")
            ].join("");
        }
    };

    if (titlePreview) titlePreview.dataset.fallback = titlePreview.textContent;
    if (descriptionPreview) descriptionPreview.dataset.fallback = descriptionPreview.textContent;
    [titleInput, descriptionInput, canonicalInput].forEach(function (input) {
        if (input) input.addEventListener("input", update);
    });
    update();
});
</script>
HTML;
    }

    private function preview(?array $item): string
    {
        $settings = $this->settings->all();
        $title = trim((string) ($item['fields']['seo_title'] ?? '')) ?: (string) ($item['title'] ?? 'SEO title preview');
        $description = $item ? $this->frontend->description($item, $settings) : 'Write a meta description to control how this page is summarized in search results.';
        $url = $item ? $this->frontend->absoluteContentUrl($item) : $this->settings->baseUrl() . '/sample-page';
        $analysis = $item ? $this->analyzer->analyze($item) : ['state' => 'warn', 'label' => 'Not available', 'checks' => []];
        $checks = '';

        foreach ($analysis['checks'] as $check) {
            $checks .= '<li data-state="' . ($check['passed'] ? 'good' : 'warn') . '"><strong>' . \e($check['label']) . '</strong><span>' . \e($check['message']) . '</span></li>';
        }

        return '<div class="kp-seo-preview" data-kp-seo-preview>
            <div class="kp-seo-preview-label">Snippet Preview</div>
            <div class="kp-seo-preview-title" data-kp-seo-preview-title>' . \e($title) . '</div>
            <div class="kp-seo-preview-url" data-kp-seo-preview-url>' . \e($url) . '</div>
            <div class="kp-seo-preview-description" data-kp-seo-preview-description>' . \e($description) . '</div>
            <div class="kp-seo-score" data-kp-seo-score data-state="' . \e($analysis['state']) . '">SEO score: ' . \e($analysis['label']) . '</div>
            <ul class="kp-seo-checks" data-kp-seo-checks>' . $checks . '</ul>
        </div>';
    }
}
