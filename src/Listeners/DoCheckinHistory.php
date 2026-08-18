<?php

namespace Mattoid\CheckinHistory\Listeners;

use Carbon\Carbon;
use Mattoid\CheckinHistory\CheckinHistoryManager;
use Mattoid\CheckinHistory\Model\UserCheckinHistory;
use Ziven\DailyCheckin\Event\CheckinUpdated;

class DoCheckinHistory
{
    public function __construct(
        protected CheckinHistoryManager $historyManager
    ) {
    }

    public function checkinHistory(CheckinUpdated $event): void
    {
        $user = $event->user;
        if (! $user) {
            return;
        }

        $checkinDate = $this->historyManager->getTodayDate();

        // Prevent duplicate records for the same day
        $history = UserCheckinHistory::query()->firstOrNew([
            'user_id' => $user->id,
            'last_checkin_date' => $checkinDate,
        ]);

        $history->type = 0; // 0 = Regular check-in
        $history->total_checkin_count = (int) $user->total_checkin_count;
        $history->total_continuous_checkin_count = (int) $user->total_continuous_checkin_count;
        $history->last_checkin_time = $user->last_checkin_time ?: Carbon::now();
        $history->save();
    }
}
