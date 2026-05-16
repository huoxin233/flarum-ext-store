<?php

namespace Mattoid\Store\Listeners;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Mattoid\Store\Event\StoreStockSubEvent;
use Mattoid\Store\Model\StoreModel;

/**
 * 减少库存（V-10：原子化）
 * Decrement stock (V-10: atomic update).
 */
class StoreStockSubListeners
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

    public function handle(StoreStockSubEvent $event)
    {
        $storeId = $event->store->id;

        // V-10: 单条 UPDATE 原子化处理库存
        //   - stock = -99：无限库存，不变
        //   - stock >  0：减 1
        //   - 其它：拒绝（受影响行数为 0）
        // V-10: atomic single-statement UPDATE
        //   - stock = -99: unlimited, unchanged
        //   - stock >  0: decrement by 1
        //   - otherwise: rejected (affected = 0)
        $updated = StoreModel::query()
            ->where('id', $storeId)
            ->where(function ($q) {
                $q->where('stock', '>', 0)->orWhere('stock', '=', -99);
            })
            ->update([
                'stock' => DB::raw('CASE WHEN stock = -99 THEN -99 ELSE stock - 1 END'),
            ]);

        if ($updated === 0) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.insufficient-inventory')]);
        }
    }
}
