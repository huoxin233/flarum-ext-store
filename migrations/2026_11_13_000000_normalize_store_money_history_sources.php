<?php

use Illuminate\Database\Schema\Builder;
use Mattoid\Store\Service\StoreMoneyManager;

return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        if ($schema->hasTable('user_money_history')) {
            $db->table('user_money_history')
                ->where('source', 'STOREBUYGOODS')
                ->update(['source' => StoreMoneyManager::SOURCE_BUY_GOODS]);

            $db->table('user_money_history')
                ->where('source', 'STOREBUYGOODSFAIL')
                ->update(['source' => StoreMoneyManager::SOURCE_BUY_GOODS_FAIL]);

            $db->table('user_money_history')
                ->where('source', 'AUTODEDUCTION')
                ->update(['source' => StoreMoneyManager::SOURCE_AUTO_DEDUCTION]);
        }
    },

    'down' => function (Builder $schema) {
        $db = $schema->getConnection();
        if ($schema->hasTable('user_money_history')) {
            $db->table('user_money_history')
                ->where('source', StoreMoneyManager::SOURCE_BUY_GOODS)
                ->update(['source' => 'STOREBUYGOODS']);

            $db->table('user_money_history')
                ->where('source', StoreMoneyManager::SOURCE_BUY_GOODS_FAIL)
                ->update(['source' => 'STOREBUYGOODSFAIL']);

            $db->table('user_money_history')
                ->where('source', StoreMoneyManager::SOURCE_AUTO_DEDUCTION)
                ->update(['source' => 'AUTODEDUCTION']);
        }
    },
];
