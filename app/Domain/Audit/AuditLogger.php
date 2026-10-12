<?php

namespace App\Domain\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Writes the technical audit trail (Spatie Activitylog, log name "audit").
 *
 * Domain actions call this explicitly with a semantic event code such as
 * `user.deactivated`, so the log reflects business meaning instead of raw
 * Eloquent events. Sensitive attributes are always stripped.
 */
class AuditLogger
{
    public const string LOG_NAME = 'audit';

    /**
     * Attributes that must never reach the audit log.
     *
     * @var list<string>
     */
    public const array SENSITIVE_ATTRIBUTES = [
        'password',
        'password_confirmation',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    /**
     * Attributes that carry no audit value.
     *
     * @var list<string>
     */
    private const array IGNORED_ATTRIBUTES = ['created_at', 'updated_at'];

    /**
     * Record an audit event.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $event,
        string $description,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $properties = [],
    ): void {
        $logger = activity(self::LOG_NAME)->event($event);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        $changes = array_filter([
            'old' => $this->clean($old),
            'attributes' => $this->clean($new),
        ]);

        if ($changes !== []) {
            $logger->withChanges($changes);
        }

        if ($properties !== []) {
            $logger->withProperties($this->clean($properties));
        }

        $logger->log($description);
    }

    /**
     * Record the creation of a model with its initial attributes.
     *
     * @param  array<string, mixed>  $extra  Additional attributes (e.g. role, departments).
     */
    public function recordCreated(string $event, string $description, Model $subject, array $extra = []): void
    {
        $this->record($event, $description, $subject, new: [...$subject->attributesToArray(), ...$extra]);
    }

    /**
     * Record the attributes changed by the model's last save. Nothing is written
     * when no auditable attribute changed.
     */
    public function recordChanges(string $event, string $description, Model $subject): void
    {
        $changes = $this->clean($subject->getChanges());

        if ($changes === []) {
            return;
        }

        $this->record(
            $event,
            $description,
            $subject,
            old: Arr::only($subject->getPrevious(), array_keys($changes)),
            new: $changes,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function clean(array $attributes): array
    {
        return Arr::except($attributes, [...self::SENSITIVE_ATTRIBUTES, ...self::IGNORED_ATTRIBUTES, 'id']);
    }
}
