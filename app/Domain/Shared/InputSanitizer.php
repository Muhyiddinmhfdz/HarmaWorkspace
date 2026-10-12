<?php

namespace App\Domain\Shared;

/**
 * Normalises user input before validation: trims whitespace, removes HTML tags
 * and invisible control characters. Secrets (passwords) are left untouched.
 *
 * Output is still escaped by Blade; this is an additional layer so stored
 * data stays plain text.
 */
class InputSanitizer
{
    /**
     * Keys whose values must be kept exactly as typed.
     *
     * @var list<string>
     */
    public const array RAW_KEYS = ['password', 'password_confirmation', 'current_password'];

    public function clean(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $cleaned = [];

            foreach ($value as $childKey => $childValue) {
                $cleaned[$childKey] = $this->clean($childValue, is_string($childKey) ? $childKey : $key);
            }

            return $cleaned;
        }

        if (! is_string($value) || in_array($key, self::RAW_KEYS, true)) {
            return $value;
        }

        $value = strip_tags($value);
        $value = (string) preg_replace('/[^\P{C}\n\t]/u', '', $value);

        return trim($value);
    }
}
