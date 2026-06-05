<?php

declare(strict_types=1);

namespace KivopressSeo;

final class Analyzer
{
    public function analyze(array $content): array
    {
        $fields = $content['fields'] ?? [];
        $title = trim((string) ($fields['seo_title'] ?? $content['title'] ?? ''));
        $description = trim((string) ($fields['seo_description'] ?? $content['excerpt'] ?? ''));
        $canonical = trim((string) ($fields['seo_canonical'] ?? ''));
        $body = trim(strip_tags((string) ($content['body'] ?? '')));
        $checks = [
            $this->check('SEO title', mb_strlen($title) >= 30 && mb_strlen($title) <= 60, 'Aim for 30-60 characters.'),
            $this->check('Meta description', mb_strlen($description) >= 120 && mb_strlen($description) <= 160, 'Aim for 120-160 characters.'),
            $this->check('Canonical URL', $canonical === '' || (bool) filter_var($canonical, FILTER_VALIDATE_URL), 'Use a full https:// URL or leave it empty.'),
            $this->check('Readable content', str_word_count($body) >= 80, 'Add enough useful body content for readers.'),
        ];
        $passed = count(array_filter($checks, fn (array $check): bool => $check['passed']));
        $score = (int) round(($passed / max(1, count($checks))) * 100);

        return [
            'score' => $score,
            'state' => $score >= 80 ? 'good' : ($score >= 50 ? 'ok' : 'warn'),
            'label' => $score >= 80 ? 'Good' : ($score >= 50 ? 'Needs work' : 'Not available'),
            'checks' => $checks,
        ];
    }

    public function validatePayload(array $errors, array $schema, array $payload): array
    {
        $fields = $payload['fields'] ?? [];
        $title = (string) ($fields['seo_title'] ?? '');
        $description = (string) ($fields['seo_description'] ?? '');
        $canonical = trim((string) ($fields['seo_canonical'] ?? ''));
        $imageId = (int) ($fields['seo_sitemap_image'] ?? 0);

        if ($title !== '' && mb_strlen($title) > 70) {
            $errors[] = 'SEO title must be 70 characters or fewer.';
        }

        if ($description !== '' && mb_strlen($description) > 320) {
            $errors[] = 'Meta description must be 320 characters or fewer.';
        }

        if ($canonical !== '' && !filter_var($canonical, FILTER_VALIDATE_URL)) {
            $errors[] = 'Canonical URL must be a valid absolute URL.';
        }

        if ($imageId > 0 && !\app()->media()->find($imageId)) {
            $errors[] = 'Selected SEO sitemap image does not exist.';
        }

        return $errors;
    }

    public function publishSummary(array $rows, array $schema, ?array $item): array
    {
        $analysis = $item ? $this->analyze($item) : ['label' => 'Not available', 'state' => 'warn'];
        $rows[] = [
            'icon' => 'search',
            'label' => 'SEO analysis',
            'value' => '<a class="kp-seo-analysis-link" href="#kp-box-seo">' . \e($analysis['label']) . '</a>',
        ];
        $rows[] = [
            'icon' => 'visibility',
            'label' => 'Readability',
            'value' => '<span class="kp-seo-state-' . \e($analysis['state']) . '">' . \e($analysis['label']) . '</span>',
        ];

        return $rows;
    }

    private function check(string $label, bool $passed, string $message): array
    {
        return ['label' => $label, 'passed' => $passed, 'message' => $message];
    }
}
