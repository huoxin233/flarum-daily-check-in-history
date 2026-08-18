<?php

namespace Ziven\DailyCheckin\Event;

use Flarum\User\User;

if (! class_exists(CheckinUpdated::class, false)) {
    class CheckinUpdated
    {
        public ?User $user;

        public function __construct(?User $user = null)
        {
            $this->user = $user;
        }
    }
}
