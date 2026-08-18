<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $connection = $schema->getConnection();
        $prefix = $connection->getTablePrefix();
        $prefixedTable = $prefix . 'user_checkin_history';

        // Clean orphan records where user_id does not exist in users table to prevent FK constraint failures
        $connection
            ->table('user_checkin_history')
            ->whereNotIn('user_id', function ($query) {
                $query->select('id')->from('users');
            })
            ->delete();

        $doctrineSchema = $connection->getDoctrineSchemaManager();
        $doctrineTable = $doctrineSchema->listTableDetails($prefixedTable);

        // Ensure user_id is unsigned so that it is compatible with users.id (MySQL error 3780)
        if ($doctrineTable->hasColumn('user_id') && ! $doctrineTable->getColumn('user_id')->getUnsigned()) {
            $schema->table('user_checkin_history', function (Blueprint $table) {
                $table->unsignedInteger('user_id')->change();
            });
            // Refresh table details after column change
            $doctrineTable = $doctrineSchema->listTableDetails($prefixedTable);
        }

        $uniqueIndexName = $prefix . 'user_checkin_history_user_id_last_checkin_date_unique';
        $fkName = $prefix . 'user_checkin_history_user_id_foreign';

        $hasUnique = $doctrineTable->hasIndex($uniqueIndexName) || $doctrineTable->hasIndex('user_checkin_history_user_id_last_checkin_date_unique');
        $hasFk = $doctrineTable->hasForeignKey($fkName) || $doctrineTable->hasForeignKey('user_checkin_history_user_id_foreign');

        if (! $hasUnique || ! $hasFk) {
            $schema->table('user_checkin_history', function (Blueprint $table) use ($hasUnique, $hasFk) {
                if (! $hasUnique) {
                    $table->unique(['user_id', 'last_checkin_date']);
                }
                if (! $hasFk) {
                    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                }
            });
        }
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('user_checkin_history')) {
            return;
        }

        $connection = $schema->getConnection();
        $prefix = $connection->getTablePrefix();
        $prefixedTable = $prefix . 'user_checkin_history';

        $doctrineSchema = $connection->getDoctrineSchemaManager();
        $doctrineTable = $doctrineSchema->listTableDetails($prefixedTable);

        $uniqueIndexName = $prefix . 'user_checkin_history_user_id_last_checkin_date_unique';
        $fkName = $prefix . 'user_checkin_history_user_id_foreign';

        $hasUnique = $doctrineTable->hasIndex($uniqueIndexName) || $doctrineTable->hasIndex('user_checkin_history_user_id_last_checkin_date_unique');
        $hasFk = $doctrineTable->hasForeignKey($fkName) || $doctrineTable->hasForeignKey('user_checkin_history_user_id_foreign');

        if ($hasUnique || $hasFk) {
            $schema->table('user_checkin_history', function (Blueprint $table) use ($hasUnique, $hasFk) {
                if ($hasFk) {
                    $table->dropForeign(['user_id']);
                }
                if ($hasUnique) {
                    $table->dropUnique(['user_id', 'last_checkin_date']);
                }
            });
        }
    },
];
