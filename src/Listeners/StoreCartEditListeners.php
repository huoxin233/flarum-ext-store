<?php

namespace Mattoid\Store\Listeners;

use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Mattoid\Store\Event\StoreCartEditEvent;
use Mattoid\Store\Event\StoreStockAddEvent;
use Mattoid\Store\Model\StoreCartModel;
use Mattoid\Store\Model\StoreModel;

/**
 * 编辑购物车
 * Edit cart.
 */
class StoreCartEditListeners
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

    public function handle(StoreCartEditEvent $event)
    {
        $cart = StoreCartModel::query()->where('id', $event->cart->id)->first();
        if (! $cart) {
            return null;
        }
        $cart->status = $event->cart->status;
        $cart->save();

        // 购买失败时回滚库存
        // Roll back stock on failure
        if ($event->cart->status > 1) {
            $store = StoreModel::withTrashed()->where('id', $event->cart->store_id)->first();
            if ($store) {
                $this->events->dispatch(new StoreStockAddEvent($store));
            }
        }

        return $cart;
    }
}
