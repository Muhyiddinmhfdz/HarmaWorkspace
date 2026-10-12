<?php

namespace App\Domain\System\Settings;

/**
 * Every configurable system setting, with its group, default and validation.
 *
 * A technical enum: the set of settings is defined by code; administrators
 * change only their values.
 */
enum SettingKey: string
{
    case OrganizationName = 'general.organization_name';
    case AppSubtitle = 'branding.app_subtitle';
    case ProjectNumberPrefix = 'numbering.project_prefix';
    case TaskNumberPrefix = 'numbering.task_prefix';
    case TicketNumberPrefix = 'numbering.ticket_prefix';
    case NumberPadding = 'numbering.padding';
    case NumberResetsYearly = 'numbering.reset_yearly';

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }

    public function label(): string
    {
        return match ($this) {
            self::OrganizationName => 'Organization name',
            self::AppSubtitle => 'Application subtitle',
            self::ProjectNumberPrefix => 'Project prefix',
            self::TaskNumberPrefix => 'Task prefix',
            self::TicketNumberPrefix => 'Ticket prefix',
            self::NumberPadding => 'Sequence digits',
            self::NumberResetsYearly => 'Reset sequence every year',
        };
    }

    public function default(): string|int|bool
    {
        return match ($this) {
            self::OrganizationName => 'HARMA DIGITAL',
            self::AppSubtitle => 'Work Management by HARMA DIGITAL',
            self::ProjectNumberPrefix => 'PRJ',
            self::TaskNumberPrefix => 'TSK',
            self::TicketNumberPrefix => 'TCK',
            self::NumberPadding => 4,
            self::NumberResetsYearly => true,
        };
    }

    /**
     * @return list<string>
     */
    public function rules(): array
    {
        return match ($this) {
            self::OrganizationName => ['required', 'string', 'max:100'],
            self::AppSubtitle => ['required', 'string', 'max:120'],
            self::ProjectNumberPrefix,
            self::TaskNumberPrefix,
            self::TicketNumberPrefix => ['required', 'string', 'max:10', 'regex:/^[A-Z][A-Z0-9]*$/'],
            self::NumberPadding => ['required', 'integer', 'min:3', 'max:8'],
            self::NumberResetsYearly => ['boolean'],
        };
    }

    public function cast(mixed $value): string|int|bool
    {
        return match (gettype($this->default())) {
            'integer' => (int) $value,
            'boolean' => (bool) $value,
            default => (string) $value,
        };
    }

    /**
     * @return list<self>
     */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(self::cases(), fn (self $key): bool => $key->group() === $group));
    }
}
