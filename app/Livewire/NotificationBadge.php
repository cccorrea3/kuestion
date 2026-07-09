<?php

namespace App\Livewire;

use Illuminate\Support\Facades\DB;
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
        $this->count = DB::table('notifications')
            ->where('user_id', config('app.user_id'))
            ->whereNull('read_at')
            ->count();
    }

    public function markReadAndGo(): void
    {
        if ($this->count === 0) return;

        $notification = DB::table('notifications')
            ->where('user_id', config('app.user_id'))
            ->whereNull('read_at')
            ->latest('created_at')
            ->first();

        if (!$notification) return;

        DB::table('notifications')
            ->where('id', $notification->id)
            ->update(['read_at' => now()]);

        $this->refreshCount();

        $data = json_decode($notification->data);
        if (isset($data->question_id)) {
            $this->redirect(route('questions.show', $data->question_id), navigate: true);
        } else {
            $this->redirect(route('questions.index', ['filter' => 'changes']), navigate: true);
        }
    }

    public function render()
    {
        return view('livewire.notification-badge');
    }
}
