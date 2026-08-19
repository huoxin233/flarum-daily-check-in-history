<?php

namespace Mattoid\CheckinHistory;

use Carbon\Carbon;
use DateTime;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Huoxin\MoneyWithHistory\Service\BalanceManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Mattoid\CheckinHistory\Event\SupplementaryCheckinEvent;
use Mattoid\CheckinHistory\Model\UserCheckinHistory;

class CheckinHistoryManager
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Translator $translator,
        protected Dispatcher $events,
        protected ConnectionInterface $connection,
        protected Container $container,
        protected ExtensionManager $extensions
    ) {
    }

    /**
     * Get today's date formatted as YYYY-MM-DD according to configured timezone.
     */
    public function getTodayDate(): string
    {
        $timezone = (int) $this->settings->get('ziven-forum-checkin.checkinTimeZone', 0);

        return gmdate('Y-m-d', time() + ($timezone * 3600));
    }

    /**
     * Perform supplementary check-in for a user on a given date.
     */
    public function supplementCheckin(User $actor, string $dateStr): UserCheckinHistory
    {
        if ($actor->isGuest() || ! $actor->can('checkin.allowSupplementaryCheckIn')) {
            throw new PermissionDeniedException();
        }

        // Validate date format YYYY-MM-DD
        $dateObj = DateTime::createFromFormat('Y-m-d', $dateStr);
        if (! $dateObj || $dateObj->format('Y-m-d') !== $dateStr) {
            throw new ValidationException([
                'message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.invalid-date'),
            ]);
        }

        $todayStr = $this->getTodayDate();
        if ($dateStr > $todayStr) {
            throw new ValidationException([
                'message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.greater-than-today'),
            ]);
        }

        // Check earliest allowed date
        $minDate = (string) $this->settings->get('mattoid-forum-checkin.min-supplementary-date', '');
        if (! empty($minDate) && $dateStr < $minDate) {
            throw new ValidationException([
                'message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.min-supplementary-date', ['day' => $minDate]),
            ]);
        }

        // Check allowable date range
        $checkinRange = (int) $this->settings->get('mattoid-forum-checkin.checkin-range', 0);
        $diffDays = (new DateTime($todayStr))->diff(new DateTime($dateStr))->days;
        if ($checkinRange > 0 && ($diffDays - 1) >= $checkinRange) {
            throw new ValidationException([
                'message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.checkin-range', ['day' => $checkinRange]),
            ]);
        }

        $rewardMoney = (float) ($this->settings->get('mattoid-forum-checkin.reward-money') ?? 0);
        $consumption = (float) ($this->settings->get('mattoid-forum-checkin.consumption') ?? 0);
        $onlyCheckinCard = (bool) $this->settings->get('mattoid-forum-checkin.checkin-card', false);
        $checkinIncrease = (float) ($this->settings->get('mattoid-forum-checkin.checkin-increase') ?? 0);
        $maxSupplementaryCheckin = (int) $this->settings->get('mattoid-forum-checkin.max-supplementary-checkin', 0);
        $spanDayCheckin = (bool) $this->settings->get('mattoid-forum-checkin.span-day-checkin', false);

        /** @var UserCheckinHistory $history */
        $history = $this->connection->transaction(function () use (
            $actor,
            $dateStr,
            $todayStr,
            $diffDays,
            $rewardMoney,
            $consumption,
            $onlyCheckinCard,
            $checkinIncrease,
            $maxSupplementaryCheckin,
            $spanDayCheckin
        ) {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            if (! $lockedUser) {
                throw new PermissionDeniedException();
            }

            // Check if already checked in on this date
            $existing = UserCheckinHistory::query()
                ->where('user_id', $lockedUser->id)
                ->where('last_checkin_date', $dateStr)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new ValidationException([
                    'message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.already-checked-in', ['date' => $dateStr]),
                ]);
            }

            // Check span-day constraint if disabled
            if (! $spanDayCheckin) {
                $tomorrow = date('Y-m-d', strtotime('+1 day', strtotime($dateStr)));
                $signedCountBetween = UserCheckinHistory::query()
                    ->where('user_id', $lockedUser->id)
                    ->where('last_checkin_date', '>=', $tomorrow)
                    ->where('last_checkin_date', '<=', $todayStr)
                    ->count();

                if ($signedCountBetween < $diffDays) {
                    throw new ValidationException([
                        'message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.span-day-checkin', ['date' => $dateStr]),
                    ]);
                }
            }

            // Calculate consecutive supplementary checkins surrounding this date
            $consecutiveSupplementCount = $this->calculateConsecutiveSupplementCount($lockedUser->id, $dateStr);
            if ($maxSupplementaryCheckin > 0 && ($consecutiveSupplementCount + 1) > $maxSupplementaryCheckin) {
                throw new ValidationException([
                    'message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.max-supplementary-checkin', ['day' => $maxSupplementaryCheckin]),
                ]);
            }

            // Check check-in card or money deductions
            $consumptionMoney = $consumption * ($checkinIncrease * $consecutiveSupplementCount / 100 + 1);

            if ($onlyCheckinCard && (int) $lockedUser->checkin_card <= 0) {
                throw new ValidationException([
                    'message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.insufficient-checkin-card'),
                ]);
            }

            $netBalanceDelta = $rewardMoney;
            if ((int) $lockedUser->checkin_card > 0) {
                $lockedUser->checkin_card = (int) $lockedUser->checkin_card - 1;
            } else {
                $netBalanceDelta -= $consumptionMoney;
            }

            if ($netBalanceDelta !== 0.0) {
                $this->applyBalanceChange($lockedUser, $netBalanceDelta);
            }

            // Calculate continuous check-in count
            $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($dateStr)));
            $yesterdayRecord = UserCheckinHistory::query()
                ->where('user_id', $lockedUser->id)
                ->where('last_checkin_date', $yesterday)
                ->first();

            $continuousCount = $yesterdayRecord ? ((int) $yesterdayRecord->total_continuous_checkin_count + 1) : 1;

            $lockedUser->total_checkin_count = (int) $lockedUser->total_checkin_count + 1;
            if ($continuousCount > (int) $lockedUser->total_continuous_checkin_count) {
                $lockedUser->total_continuous_checkin_count = $continuousCount;
            }

            // Record history entry
            $record = new UserCheckinHistory();
            $record->type = 1; // 1 = Supplementary check-in
            $record->user_id = $lockedUser->id;
            $record->last_checkin_date = $dateStr;
            $record->last_checkin_time = Carbon::now();
            $record->total_checkin_count = $lockedUser->total_checkin_count;
            $record->total_continuous_checkin_count = $continuousCount;
            $record->save();

            // Re-chain forward continuous count for immediately following dates if any
            $forwardContinuous = $continuousCount;
            $nextDate = date('Y-m-d', strtotime('+1 day', strtotime($dateStr)));
            while (true) {
                /** @var UserCheckinHistory|null $nextRecord */
                $nextRecord = UserCheckinHistory::query()
                    ->where('user_id', $lockedUser->id)
                    ->where('last_checkin_date', $nextDate)
                    ->first();

                if (! $nextRecord) {
                    break;
                }

                $forwardContinuous++;
                $nextRecord->total_continuous_checkin_count = $forwardContinuous;
                $nextRecord->save();

                if ($forwardContinuous > (int) $lockedUser->total_continuous_checkin_count) {
                    $lockedUser->total_continuous_checkin_count = $forwardContinuous;
                }

                $nextDate = date('Y-m-d', strtotime('+1 day', strtotime($nextDate)));
            }

            $lockedUser->save();

            $this->events->dispatch(new SupplementaryCheckinEvent($lockedUser, $dateStr, $continuousCount, $consecutiveSupplementCount + 1));

            return $record;
        });

        return $history;
    }

    /**
     * Calculate how many consecutive supplementary check-ins are linked to this date.
     */
    protected function calculateConsecutiveSupplementCount(int $userId, string $dateStr): int
    {
        $count = 0;

        // Check backwards
        $current = $dateStr;
        while (true) {
            $prev = date('Y-m-d', strtotime('-1 day', strtotime($current)));
            $hasSupplement = UserCheckinHistory::query()
                ->where('user_id', $userId)
                ->where('last_checkin_date', $prev)
                ->where('type', 1)
                ->exists();

            if (! $hasSupplement) {
                break;
            }
            $count++;
            $current = $prev;
        }

        // Check forwards
        $current = $dateStr;
        while (true) {
            $next = date('Y-m-d', strtotime('+1 day', strtotime($current)));
            $hasSupplement = UserCheckinHistory::query()
                ->where('user_id', $userId)
                ->where('last_checkin_date', $next)
                ->where('type', 1)
                ->exists();

            if (! $hasSupplement) {
                break;
            }
            $count++;
            $current = $next;
        }

        return $count;
    }

    /**
     * Safely apply money changes with support for huoxin-money-with-history and antoinefr-money.
     */
    protected function applyBalanceChange(User $user, float $amount): void
    {
        $isCost = $amount < 0;
        $source = $isCost ? 'SUPPLEMENTARY_CHECKIN_COST' : 'SUPPLEMENTARY_CHECKIN_REWARD';
        $sourceKey = $isCost
            ? 'mattoid-daily-check-in-history.forum.money-history.supplementary-checkin-cost'
            : 'mattoid-daily-check-in-history.forum.money-history.supplementary-checkin-reward';

        if ($this->extensions->isEnabled('huoxin-money-with-history')) {
            /** @var BalanceManager $balanceManager */
            $balanceManager = $this->container->make(BalanceManager::class);
            $applied = $balanceManager->applyBalanceChange(
                $user,
                $amount,
                $source,
                $sourceKey,
                [],
                $user,
                preventOverdraft: true
            );

            if (! $applied) {
                throw new ValidationException([
                    'message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.insufficient-balance'),
                ]);
            }
            return;
        }

        // Fallback: direct money column modification if supported
        $currentMoney = (float) ($user->money ?? 0);
        if ($amount < 0 && ($currentMoney + $amount) < 0) {
            throw new ValidationException([
                'message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.insufficient-balance'),
            ]);
        }

        $user->money = round($currentMoney + $amount, 6);
    }

    /**
     * Give supplementary check-in cards to users.
     */
    public function giveCheckinCards(User $actor, ?string $usernamesInput, int $amount, bool $allUsers = false): int
    {
        if (! $actor->can('checkin.issuanceOfSupplementaryCards')) {
            throw new PermissionDeniedException();
        }

        if ($amount <= 0) {
            throw new ValidationException([
                'message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.invalid-amount'),
            ]);
        }

        if ($allUsers) {
            return User::query()->increment('checkin_card', $amount);
        }

        if (empty($usernamesInput)) {
            throw new ValidationException([
                'message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.empty-usernames'),
            ]);
        }

        $separators = [',', '，', '|', ' ', "\n", "\r", "\t"];
        $normalized = str_replace($separators, ',', $usernamesInput);
        $usernames = array_values(array_filter(array_map('trim', explode(',', $normalized))));

        if (empty($usernames)) {
            throw new ValidationException([
                'message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.invalid-usernames'),
            ]);
        }

        return User::query()->whereIn('username', $usernames)->increment('checkin_card', $amount);
    }
}
