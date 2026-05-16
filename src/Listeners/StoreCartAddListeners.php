<?php

namespace Mattoid\Store\Listeners;

use Carbon\Carbon;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Mattoid\Store\Event\StoreCartAddEvent;
use Mattoid\Store\Event\StoreStockSubEvent;
use Mattoid\Store\Model\StoreCartModel;

/**
 * 添加购物车
 * Add shopping cart.
 */
class StoreCartAddListeners
{
    private $events;
    private $settings;
    private $translator;

    private $storeTimezone = 'Asia/Shanghai';

    public function __construct(Dispatcher $events, SettingsRepositoryInterface $settings, Translator $translator)
    {
        $this->events = $events;
        $this->settings = $settings;
        $this->translator = $translator;

        $storeTimezone = $this->settings->get('mattoid-store.storeTimezone', 'Asia/Shanghai');
        $this->storeTimezone = $storeTimezone ?: 'Asia/Shanghai';
    }

    public function handle(StoreCartAddEvent $event)
    {
        $actor = $event->user;
        $store = $event->store;
        $price = $event->price;

        $now = Carbon::now()->tz($this->storeTimezone);

        $cart = new StoreCartModel();
        $cart->user_id = $actor->id;
        $cart->store_id = $store->id;
        $cart->code = $store->code;
        $cart->title = $store->title;
        $cart->price = $store->price;
        $cart->pay_amt = $price;
        $cart->type = $store->type;
        $cart->status = 0;
        $cart->enable = 0;
        $cart->auto_deduction = (int) $store->auto_deduction;
        $cart->created_at = $now;
        $cart->updated_at = $now;

        if ($store->type === 'limit') {
            $cart->outtime = (clone $now)->addDays((int) $store->outtime);
        }

        $cart->save();

        // 同步扣减库存（StoreStockSubListeners 原子操作）
        // Synchronously decrement stock (atomic in StoreStockSubListeners)
        $this->events->dispatch(new StoreStockSubEvent($store));

        return $cart;
    }
}
