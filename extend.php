<?php

/*
 * This file is part of mattoid/daily-check-in-history.
 *
 * Copyright (c) 2023 mattoid.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Api\Serializer\UserSerializer;
use Flarum\Extend;
use Mattoid\CheckinHistory\Api\Controller\ListCheckinHistoryController;
use Mattoid\CheckinHistory\Api\Controller\PostCheckinHistoryController;
use Mattoid\CheckinHistory\Api\Controller\PostGiveCheckinCardController;
use Mattoid\CheckinHistory\Api\Serializer\AddForumAttributes;
use Mattoid\CheckinHistory\Api\Serializer\AddUserAttributes;
use Mattoid\CheckinHistory\Listeners\DoCheckinHistory;
use Ziven\DailyCheckin\Event\CheckinUpdated;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        ->route('/u/{username}/checkin/history', 'mattoid-daily-check-in-history.forum.page.link-name'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Event())
        ->listen(CheckinUpdated::class, [DoCheckinHistory::class, 'checkinHistory']),

    (new Extend\ApiSerializer(ForumSerializer::class))
        ->attributes(AddForumAttributes::class),

    (new Extend\ApiSerializer(UserSerializer::class))
        ->attributes(AddUserAttributes::class),

    (new Extend\Routes('api'))
        ->get('/checkin/history', 'checkin.history', ListCheckinHistoryController::class)
        ->post('/supplement/checkin', 'supplement.checkin', PostCheckinHistoryController::class)
        ->post('/give/checkin/card', 'give.checkin-card', PostGiveCheckinCardController::class),
];
