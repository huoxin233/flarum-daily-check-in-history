<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Clean orphan records where user_id does not exist in users table to prevent FK constraint failures
        $schema->getConnection()
            ->table('user_checkin_history')
            ->whereNotIn('user_id', function ($query) {
                $query->select('id')->from('users');
            })
            ->delete();

        $schema->table('user_checkin_history', function (Blueprint $table) {
            $table->unique(['user_id', 'last_checkin_date']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('user_checkin_history', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id', 'last_checkin_date']);
        });
    },
];
