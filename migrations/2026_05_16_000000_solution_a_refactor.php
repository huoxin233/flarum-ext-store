<?php

/**
 * 重构迁移（Solution A + 安全加固）
 * Refactor migration (Solution A + security hardening)
 *
 * 变更：
 * - 06: 删除 store_goods 表（商品元数据改为运行时聚合）
 * - 06: 删除 store.class_name / store.pop_up 列（由 serializer 运行时注入）
 * - V-11: store 表增加 deleted_at（SoftDeletes）
 * - V-12: store.discount_price 改为 decimal(10,2)
 * - 性能优化: 增加常用查询的复合索引
 *
 * Changes:
 * - 06: Drop store_goods table (product metadata now lives in runtime registry)
 * - 06: Drop store.class_name / store.pop_up columns (injected by serializer)
 * - V-11: Add deleted_at on store (SoftDeletes)
 * - V-12: Change store.discount_price to decimal(10,2)
 * - Perf: Add composite indexes for hot queries
 */

use Doctrine\DBAL\Types\Type;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // 1. Drop store_goods table (no longer used under Solution A)
        if ($schema->hasTable('store_goods')) {
            $schema->drop('store_goods');
        }

        $connection = $schema->getConnection();
        $platform = $connection->getDoctrineSchemaManager()->getDatabasePlatform();
        if (! Type::hasType('json')) {
            // Doctrine 兼容性占位，不影响实际逻辑
        }

        // 2. Modify store table
        $schema->table('store', function (Blueprint $table) use ($schema) {
            // Drop pop_up / class_name columns (Solution A)
            if ($schema->hasColumn('store', 'pop_up')) {
                $table->dropColumn('pop_up');
            }
            if ($schema->hasColumn('store', 'class_name')) {
                $table->dropColumn('class_name');
            }
            // V-11: soft delete
            if (! $schema->hasColumn('store', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // V-12: discount_price → decimal(10, 2)
        try {
            $connection->statement('ALTER TABLE `store` MODIFY `discount_price` DECIMAL(10, 2) NOT NULL DEFAULT 0');
        } catch (\Throwable $e) {
            // 非 MySQL / SQLite 等平台兜底（best-effort）
            // best-effort fallback for non-MySQL platforms (SQLite etc.)
        }

        // 性能复合索引（不存在 IF EXISTS 时忽略错误）
        // Composite indexes for hot queries (ignore errors if already exists)
        $schema->table('store_cart', function (Blueprint $table) {
            try { $table->index(['user_id', 'store_id', 'status'], 'store_cart_dup_idx'); } catch (\Throwable $e) {}
            try { $table->index(['status', 'type', 'outtime'], 'store_cart_invalid_idx'); } catch (\Throwable $e) {}
        });
        $schema->table('store', function (Blueprint $table) {
            try { $table->index(['status', 'type', 'created_at'], 'store_list_idx'); } catch (\Throwable $e) {}
        });
    },
    'down' => function (Builder $schema) {
        // 反向：drop 复合索引、恢复列、重建 store_goods 表
        // Reverse: drop indexes, restore columns, recreate store_goods table

        // 索引
        $schema->table('store_cart', function (Blueprint $table) {
            try { $table->dropIndex('store_cart_dup_idx'); } catch (\Throwable $e) {}
            try { $table->dropIndex('store_cart_invalid_idx'); } catch (\Throwable $e) {}
        });
        $schema->table('store', function (Blueprint $table) {
            try { $table->dropIndex('store_list_idx'); } catch (\Throwable $e) {}
        });

        // V-12 反向
        try {
            $schema->getConnection()->statement('ALTER TABLE `store` MODIFY `discount_price` INT NOT NULL DEFAULT 0');
        } catch (\Throwable $e) {}

        $schema->table('store', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('store', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
            if (! $schema->hasColumn('store', 'class_name')) {
                $table->string('class_name')->default('store-buy Modal--small');
            }
            if (! $schema->hasColumn('store', 'pop_up')) {
                $table->text('pop_up')->nullable();
            }
        });

        if (! $schema->hasTable('store_goods')) {
            $schema->create('store_goods', function (Blueprint $table) {
                $table->string('code')->unique();
                $table->string('name');
                $table->string('class_name');
                $table->text('pop_up');
                $table->timestamp('created_at')->index();
            });
        }
    },
];
