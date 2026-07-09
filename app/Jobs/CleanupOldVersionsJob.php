<?php

namespace App\Jobs;

use App\Models\Question;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// ponytail: hard-deletes old versions of archived questions.
// Upgrade to configurable retention if users complain.
class CleanupOldVersionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Question::onlyTrashed()->chunk(100, function ($questions) {
            foreach ($questions as $question) {
                $keepIds = $question->versions()
                    ->orderByDesc('version_number')
                    ->take(5)
                    ->pluck('id');

                $question->versions()
                    ->where('is_current', false)
                    ->whereNotIn('id', $keepIds)
                    ->delete();
            }
        });
    }
}
