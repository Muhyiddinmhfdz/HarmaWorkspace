<?php

namespace App\Livewire\Concerns;

use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Workflow\Actions\ExecuteWorkflowTransition;
use App\Domain\Workflow\Support\TransitionOutcome;
use App\Domain\Workflow\Support\TransitionRules;
use App\Domain\Workflow\Support\WorkflowRegistry;
use App\Models\WorkflowTransition;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Reusable workflow actions for record pages (task, ticket, project):
 * available actions come from the record's workflow version, a generic
 * modal collects the text the transition requires (reason / comment), and
 * every run goes through ExecuteWorkflowTransition. Pages keep their own
 * domain modals (e.g. ticket resolution) and call runTransition().
 *
 * Requires WithFeedback on the component.
 */
trait WithWorkflowTransitions
{
    #[Locked]
    public ?int $workflowTransitionId = null;

    /** @var array{comment: string, reason: string} */
    public array $transitionInput = ['comment' => '', 'reason' => ''];

    abstract protected function workflowRecord(): Model;

    abstract protected function afterWorkflowTransition(): void;

    /**
     * Transitions the current user can run now.
     *
     * @return list<WorkflowTransition>
     */
    protected function availableTransitions(): array
    {
        return app(ExecuteWorkflowTransition::class)->available($this->workflowRecord(), auth()->user());
    }

    /**
     * Any transition of the record's version leaving its current status
     * (authorized or not) — used to tell "not allowed" from "invalid".
     */
    protected function outgoingTransition(callable $match): ?WorkflowTransition
    {
        $record = $this->workflowRecord();
        $registry = app(WorkflowRegistry::class);

        return collect($registry->outgoing($registry->versionOf($record), (int) $record->getAttribute('status_id')))->first($match);
    }

    /**
     * @return list<array{name: string, label: string, required: bool}>
     */
    public function transitionFields(?WorkflowTransition $transition): array
    {
        return $transition === null ? [] : app(TransitionRules::class)->textFields($transition);
    }

    #[Computed]
    public function pendingTransition(): ?WorkflowTransition
    {
        if ($this->workflowTransitionId === null) {
            return null;
        }

        $record = $this->workflowRecord();
        $registry = app(WorkflowRegistry::class);

        return $registry->versionOf($record)->transitions->firstWhere('id', $this->workflowTransitionId);
    }

    /**
     * Open the generic modal when the transition needs text, else run it.
     */
    public function startTransition(int $transitionId): void
    {
        $transition = collect($this->availableTransitions())->firstWhere('id', $transitionId);

        if ($transition === null) {
            // Unknown, stale or not allowed: let the engine explain (403 / message).
            $this->runTransition($transitionId);

            return;
        }

        if ($this->transitionFields($transition) === [] && ! $transition->requiresApproval()) {
            $this->runTransition($transitionId);

            return;
        }

        $this->workflowTransitionId = $transitionId;
        $this->transitionInput = ['comment' => '', 'reason' => ''];
        unset($this->pendingTransition);
        $this->resetValidation();
        Flux::modal('workflow-transition')->show();
    }

    public function submitTransition(): void
    {
        $transition = $this->pendingTransition ?? throw new BusinessRuleException('Aksi tidak ditemukan. Muat ulang data terbaru.');
        $rules = [];

        foreach ($this->transitionFields($transition) as $field) {
            $rules['transitionInput.'.$field['name']] = [$field['required'] ? 'required' : 'nullable', 'string', 'max:5000'];
        }

        $rules['transitionInput.comment'] ??= ['nullable', 'string', 'max:5000'];
        $this->validate($rules, ['transitionInput.*.required' => 'Bagian ini wajib diisi.'], ['transitionInput.reason' => 'Reason', 'transitionInput.comment' => 'Comment']);

        $this->runTransition($transition->id, array_filter($this->transitionInput, fn ($value) => $value !== ''));

        Flux::modal('workflow-transition')->close();
        $this->workflowTransitionId = null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function runTransition(int $transitionId, array $input = []): TransitionOutcome
    {
        $outcome = app(ExecuteWorkflowTransition::class)->handle($this->workflowRecord(), $transitionId, auth()->user(), $input);

        $this->notify(
            $outcome->awaitsApproval() ? 'info' : 'success',
            $outcome->awaitsApproval()
                ? 'Permintaan approval dikirim. Status berubah setelah semua approver menyetujui.'
                : 'Status diperbarui.',
        );

        $this->afterWorkflowTransition();

        return $outcome;
    }
}
