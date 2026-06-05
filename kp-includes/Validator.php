<?php

declare(strict_types=1);

namespace Kivopress;

final class Validator
{
    public function validate(array $data, array $rules): array
    {
        $validated = [];
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $fieldRules = is_array($fieldRules) ? $fieldRules : explode('|', (string) $fieldRules);
            $value = $data[$field] ?? null;
            $nullable = in_array('nullable', $fieldRules, true);

            if (($value === null || $value === '') && $nullable) {
                $validated[$field] = null;
                continue;
            }

            foreach ($fieldRules as $rule) {
                $message = $this->check((string) $field, $value, (string) $rule);

                if ($message !== null) {
                    $errors[$field][] = $message;
                }
            }

            if (!isset($errors[$field]) && array_key_exists($field, $data)) {
                $validated[$field] = $value;
            }
        }

        if ($errors) {
            throw new \InvalidArgumentException($this->firstError($errors));
        }

        return $validated;
    }

    private function check(string $field, mixed $value, string $rule): ?string
    {
        [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);
        $label = ucfirst(str_replace('_', ' ', $field));

        if ($name === 'required' && ($value === null || $value === '')) {
            return "{$label} is required.";
        }

        if ($value === null || $value === '') {
            return null;
        }

        return match ($name) {
            'string' => is_scalar($value) ? null : "{$label} must be text.",
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : "{$label} must be a valid email address.",
            'url' => filter_var($value, FILTER_VALIDATE_URL) ? null : "{$label} must be a valid URL.",
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : "{$label} must be an integer.",
            'numeric' => is_numeric($value) ? null : "{$label} must be a number.",
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true) ? null : "{$label} must be true or false.",
            'array' => is_array($value) ? null : "{$label} must be an array.",
            'slug' => preg_match('/^[a-z0-9][a-z0-9_-]*$/i', (string) $value) ? null : "{$label} must be a valid slug.",
            'min' => $this->length($value) >= (int) $parameter ? null : "{$label} must be at least {$parameter} characters.",
            'max' => $this->length($value) <= (int) $parameter ? null : "{$label} must be at most {$parameter} characters.",
            'in' => in_array((string) $value, explode(',', (string) $parameter), true) ? null : "{$label} has an invalid value.",
            'nullable', 'required' => null,
            default => null,
        };
    }

    private function length(mixed $value): int
    {
        return is_array($value) ? count($value) : strlen((string) $value);
    }

    private function firstError(array $errors): string
    {
        foreach ($errors as $messages) {
            return (string) ($messages[0] ?? 'Validation failed.');
        }

        return 'Validation failed.';
    }
}
