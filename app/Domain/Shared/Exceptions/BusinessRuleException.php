<?php

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * Thrown by domain actions when an operation violates a business rule.
 *
 * The message is user-facing (Bahasa Indonesia) and safe to display. Livewire
 * pages show it as a toast; JSON requests receive it as a 422 response.
 */
class BusinessRuleException extends RuntimeException {}
