<?php

namespace Mattoid\CheckinHistory\Listeners;

use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Mattoid\CheckinHistory\Event\SupplementaryCheckinEvent;
use Mattoid\CheckinHistory\Model\UserCheckinHistory;
use AntoineFr\Money\Service\BalanceManager;

class SupplementaryCheckin
{
    protected $settings;
    protected $translator;
    protected $events;
    protected $connection;
    protected $container;
    protected $extensions;

    public function __construct(
        SettingsRepositoryInterface $settings,
        Translator $translator,
        Dispatcher $events,
        ConnectionInterface $connection,
        Container $container,
        ExtensionManager $extensions
    ) {
        $this->settings = $settings;
        $this->translator = $translator;
        $this->events = $events;
        $this->connection = $connection;
        $this->container = $container;
        $this->extensions = $extensions;
    }

    public function supplementCheckin(SupplementaryCheckinEvent $event): UserCheckinHistory
    {

        $user = $event->user;
        $checkinDate = $event->checkinDate;
        $checkinCount = $event->checkinCount;
        $totalContinuousCheckinCountHistory = $event->totalContinuousCheckinCountHistory;

        $rewardMoney = (double) $this->settings->get('mattoid-forum-checkin.reward-money') ?? 0;
        $consumption = (double) $this->settings->get('mattoid-forum-checkin.consumption') ?? 0;
        $checkinCard = (double) $this->settings->get('mattoid-forum-checkin.checkin-card') ?? 0;
        $checkinIncrease = (double) $this->settings->get('mattoid-forum-checkin.checkin-increase') ?? 0;

        $consumptionMoney = $consumption * ($checkinIncrease * $checkinCount / 100 + 1);

        /** @var UserCheckinHistory $history */
        $history = $this->connection->transaction(function () use ($user, $checkinDate, $checkinCount, $totalContinuousCheckinCountHistory, $rewardMoney, $consumption, $checkinCard, $checkinIncrease, $consumptionMoney) {

            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($checkinCard > 0 && $lockedUser->checkin_card <= 0) {
                throw new ValidationException(['message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.insufficient-checkin-card')]);
            }

            $deductCheckinCard = false;
            $netBalanceDelta = $rewardMoney;

            if ($lockedUser->checkin_card > 0) {
                // 有签到卡则扣除签到卡
                $deductCheckinCard = true;
                $lockedUser->checkin_card -= 1;
            } else {
                // 没有签到卡直接扣除金额
                $netBalanceDelta -= $consumptionMoney;
            }

            if ($netBalanceDelta !== 0.0 && $this->extensions->isEnabled('antoinefr-money')) {
                $balanceManager = $this->container->make(BalanceManager::class);

                if (method_exists($balanceManager, 'applyBalanceChange')) {
                    $applied = $balanceManager->applyBalanceChange(
                        $lockedUser,
                        $netBalanceDelta,
                        'SUPPLEMENTARY_CHECKIN_REWARD',
                        'mattoid-daily-check-in-history.forum.supplementary-checkin-reward',
                        [],
                        $lockedUser,
                        preventOverdraft: true
                    );

                    if (! $applied) {
                        throw new ValidationException(['message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.insufficient-balance')]);
                    }
                }
            } elseif ($netBalanceDelta < 0 && ! $this->extensions->isEnabled('antoinefr-money')) {
                // Fallback simulation if Money extension is disabled but balance tracking is somehow manual.
                if ((float) $lockedUser->money + $netBalanceDelta < 0) {
                    throw new ValidationException(['message' => $this->translator->trans('mattoid-daily-check-in-history.api.error.insufficient-balance')]);
                }
                $lockedUser->money = (float) $lockedUser->money + $netBalanceDelta;
            }

            $lockedUser->total_checkin_count = (int) $lockedUser->total_checkin_count + 1;
            $lockedUser->total_continuous_checkin_count = (int) $lockedUser->total_continuous_checkin_count + $totalContinuousCheckinCountHistory + 1;
            $lockedUser->save();

            // 记录补签数据
            $historyRecord = new UserCheckinHistory();
            $historyRecord->type = 1;
            $historyRecord->user_id = $lockedUser->id;
            $historyRecord->last_checkin_date = $checkinDate;
            $historyRecord->last_checkin_time = date('Y-m-d H:i:s');
            $historyRecord->total_checkin_count = $lockedUser->total_checkin_count;
            $historyRecord->total_continuous_checkin_count = $totalContinuousCheckinCountHistory + 1;
            $historyRecord->save();

            // Sync lockedUser values to original object for event caller
            $user->total_checkin_count = $lockedUser->total_checkin_count;
            $user->total_continuous_checkin_count = $lockedUser->total_continuous_checkin_count;
            $user->money = $lockedUser->money;
            $user->checkin_card = $lockedUser->checkin_card;

            return $historyRecord;
        });

        return $history;
    }
}
