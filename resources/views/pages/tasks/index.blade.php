<?php

use App\Models\Task;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tasks')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewAny', Task::class);
    }
}; ?>

<div class="mx-auto w-full max-w-7xl">
    <x-ui.page-header title="Tasks" description="Seluruh task yang dapat Anda lihat, baik di dalam project maupun task operasional." />

    <livewire:tasks.table mode="all" />
</div>
