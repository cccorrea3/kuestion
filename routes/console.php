<?php

use App\Jobs\CheckContributionStatusJob;
use App\Jobs\CheckQuestionUpdatesJob;
use App\Jobs\CleanupContributionDraftsJob;
use App\Jobs\CleanupOldVersionsJob;
use App\Jobs\NotifyPendingReviewJob;
use App\Jobs\NotifyReconfirmationDueJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new CheckQuestionUpdatesJob)->hourly();
Schedule::job(new CleanupOldVersionsJob)->daily()->at('03:00');
Schedule::job(new CleanupContributionDraftsJob)->daily()->at('03:30');
Schedule::command('db:backup --compress')->daily()->at('02:00');
Schedule::command('metrics:collect')->dailyAt('00:30');

// Ola 2, Punto 5 — Fases C/D/E (polling pragmático del spec §6-3).
Schedule::job(new CheckContributionStatusJob)->hourly();
Schedule::job(new NotifyPendingReviewJob)->everyFifteenMinutes();
Schedule::job(new NotifyReconfirmationDueJob)->daily()->at('09:00');
