<?php

namespace App\Domain\Ticket\Rules;

/**
 * Upload rules shared by Livewire forms and the attachment action.
 *
 * - `mimetypes`: the MIME type detected from the file content;
 * - `mimes`: the extension guessed from that content;
 * - `extensions`: the extension of the uploaded file name;
 * all three must be on the whitelist in config/harma.php, and the size is
 * capped, so executables or renamed files are rejected.
 */
class AttachmentRules
{
    /**
     * @return list<string>
     */
    public static function file(): array
    {
        $types = self::types();
        $extensions = implode(',', array_keys($types));
        $mimeTypes = implode(',', array_unique(array_merge(...array_values($types))));

        return [
            'file',
            'max:'.self::maxKilobytes(),
            'mimetypes:'.$mimeTypes,
            'mimes:'.$extensions,
            'extensions:'.$extensions,
        ];
    }

    public static function maxKilobytes(): int
    {
        return (int) config('harma.attachments.max_kb', 10240);
    }

    public static function maxPerUpload(): int
    {
        return (int) config('harma.attachments.max_per_upload', 5);
    }

    /**
     * Human list for hints, e.g. "JPG, PNG, PDF".
     */
    public static function label(): string
    {
        return strtoupper(implode(', ', array_keys(self::types())));
    }

    /**
     * @return array<string, list<string>>
     */
    public static function types(): array
    {
        /** @var array<string, list<string>> $types */
        $types = config('harma.attachments.types', []);

        return $types;
    }
}
