<?php

namespace App\Livewire;

use Livewire\Component;

// ponytail: single badge component handles both count display and per-notification navigation.
// Upgrade to full dropdown if notification types proliferate beyond answer_changed.
class NotificationBadge extends Component
{
    public int $count = 0;

    public function mount(): void
    {
        $this->refreshCount();
    }

    public function refreshCount(): void
    {
        $this->count = auth()->user()->notifications()
            ->whereNull('read_at')
            ->count();
    }

    public function markReadAndGo(): void
    {
        if ($this->count === 0) {
            return;
        }

        $notification = auth()->user()->notifications()
            ->whereNull('read_at')
            ->latest('created_at')
            ->first();

        if (! $notification) {
            return;
        }

        $notification->markAsRead();

        $this->refreshCount();

        $data = $notification->data;
        if (isset($data['question_id'])) {
            $this->redirect(route('questions.show', $data['question_id']), navigate: true);
        } else {
            $this->redirect(route('questions.index', ['filter' => 'changes']), navigate: true);
        }
    }

    public function render()
    {
        return view('livewire.notification-badge');
    }
}
