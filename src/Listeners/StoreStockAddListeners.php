<?php

namespace Mattoid\Store\Listeners;

use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Mattoid\Store\Event\StoreStockAddEvent;
use Mattoid\Store\Model\StoreModel;

/**
 * 增加库存（用于购买失败库存回滚），V-10 原子化
 * Increment stock (for failed-purchase rollback), V-10 atomic.
 */
class StoreStockAddListeners
{
    private $events;
    private $settings;
    private $translator;

    public function __construct(Dispatcher $events, SettingsRepositoryInterface $settings, Translator $translator)
    {
        $this->events = $events;
        $this->settings = $settings;
        $this->translator = $translator;
    }

    public function handle(StoreStockAddEvent $event)
    {
        $storeId = $event->store->id;

        // V-10: 原子 increment（无限库存保持 -99）
        StoreModel::query()
            ->where('id', $storeId)
            ->where('stock', '!=', -99)
            ->update([
                'stock' => DB::raw('stock + 1'),
            ]);
    }
}
