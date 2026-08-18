<?php

namespace Mattoid\CheckinHistory\Tests\Integration\Listener;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Mattoid\CheckinHistory\Model\UserCheckinHistory;
use Ziven\DailyCheckin\Event\CheckinUpdated;

class DoCheckinHistoryTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ziiven-daily-check-in');
        $this->extension('mattoid-daily-check-in-history');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
            ],
        ]);
    }

    /**
     * @test
     */
    public function creates_history_record_on_checkin_updated_event()
    {
        $this->app();

        /** @var User $user */
        $user = User::query()->find(2);
        $user->total_checkin_count = 1;
        $user->total_continuous_checkin_count = 1;
        $user->save();

        /** @var Dispatcher $events */
        $events = $this->app()->getContainer()->make(Dispatcher::class);
        $events->dispatch(new CheckinUpdated($user));

        $count = UserCheckinHistory::query()->where('user_id', 2)->count();
        $this->assertEquals(1, $count);

        $record = UserCheckinHistory::query()->where('user_id', 2)->first();
        $this->assertEquals(0, $record->type);
        $this->assertEquals(1, $record->total_checkin_count);
    }

    /**
     * @test
     */
    public function does_not_duplicate_records_when_event_fires_multiple_times_on_same_day()
    {
        $this->app();

        /** @var User $user */
        $user = User::query()->find(2);
        $user->total_checkin_count = 1;
        $user->total_continuous_checkin_count = 1;
        $user->save();

        /** @var Dispatcher $events */
        $events = $this->app()->getContainer()->make(Dispatcher::class);

        // Fire twice
        $events->dispatch(new CheckinUpdated($user));

        $user->total_checkin_count = 2;
        $user->save();
        $events->dispatch(new CheckinUpdated($user));

        $count = UserCheckinHistory::query()->where('user_id', 2)->count();
        $this->assertEquals(1, $count);

        $record = UserCheckinHistory::query()->where('user_id', 2)->first();
        $this->assertEquals(2, $record->total_checkin_count);
    }
}
