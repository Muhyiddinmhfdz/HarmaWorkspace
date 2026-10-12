<?php

use App\Models\Task;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My Tasks')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewAny', Task::class);
    }
}; ?>

<div class="mx-auto w-full max-w-7xl">
    <x-ui.page-header title="My Tasks" description="Task yang Anda pegang sebagai PIC atau kolaborator, diurutkan dari yang paling mendesak." />

    <livewire:tasks.table mode="mine" />
</div>
