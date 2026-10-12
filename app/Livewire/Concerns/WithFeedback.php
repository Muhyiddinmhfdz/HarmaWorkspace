<?php

namespace App\Livewire\Concerns;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\InputSanitizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Standard feedback for Livewire pages:
 *
 * - every validated input is sanitized first (see InputSanitizer);
 * - every outcome is reported to the user as a SweetAlert2 toast;
 * - every exception is caught: business rules and validation show their
 *   message, unexpected errors are logged and shown as a friendly message.
 *
 * Authorization (403) and not-found (404) errors keep their HTTP status and
 * are turned into toasts by the global Livewire request interceptor in
 * resources/js/app.js, so no technical page is ever shown.
 */
trait WithFeedback
{
    /**
     * Show a toast in the browser.
     */
    public function notify(string $type, string $message, ?string $title = null): void
    {
        $this->dispatch('notify', type: $type, message: $message, title: $title);
    }

    /**
     * Show a toast on the next page, after a redirect.
     */
    public function notifyAfterRedirect(string $type, string $message): void
    {
        session()->flash('notify', ['type' => $type, 'message' => $message]);
    }

    /**
     * Sanitize the properties covered by the rules, then validate.
     *
     * @param  array<string, mixed>|null  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     */
    public function validate($rules = null, $messages = [], $attributes = [])
    {
        $sanitizer = app(InputSanitizer::class);
        $properties = collect(array_keys($rules ?? $this->getRules()))
            ->map(fn (string $key): string => explode('.', $key)[0])
            ->unique();

        foreach ($properties as $property) {
            if (property_exists($this, $property)) {
                $this->{$property} = $sanitizer->clean($this->{$property}, $property);
            }
        }

        return parent::validate($rules, $messages, $attributes);
    }

    /**
     * Livewire exception hook: runs before Livewire's own handlers.
     */
    public function exception(Throwable $e, callable $stopPropagation): void
    {
        if ($e instanceof ValidationException) {
            $messages = collect($e->validator->errors()->all())->unique();
            $summary = $messages->take(3)->implode(' ');

            if ($messages->count() > 3) {
                $summary .= ' (+'.($messages->count() - 3).' lainnya)';
            }

            $this->notify('error', $summary, 'Periksa kembali isian');

            return; // Livewire still shows the errors next to each field.
        }

        if ($e instanceof BusinessRuleException) {
            $this->notify('error', $e->getMessage());
            $stopPropagation();

            return;
        }

        if ($e instanceof AuthorizationException || $e instanceof ModelNotFoundException || $e instanceof HttpExceptionInterface) {
            return; // Keeps its HTTP status; the browser interceptor shows the toast.
        }

        report($e);
        $this->notify('error', 'Terjadi kesalahan pada sistem. Silakan coba lagi atau hubungi administrator.');
        $stopPropagation();
    }
}
