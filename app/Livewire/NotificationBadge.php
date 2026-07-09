<?php

namespace App\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Component;

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
        DB::table('notifications')
            ->where('user_id', config('app.user_id'))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $this->refreshCount();
        $this->redirect(route('questions.index', ['filter' => 'changes']), navigate: true);
    }

    public function render()
    {
        return view('livewire.notification-badge');
    }
}
