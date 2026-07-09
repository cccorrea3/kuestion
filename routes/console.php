<?php

use App\Jobs\CheckQuestionUpdatesJob;
use App\Jobs\CleanupOldVersionsJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new CheckQuestionUpdatesJob)->hourly();
Schedule::job(new CleanupOldVersionsJob)->daily()->at('03:00');
