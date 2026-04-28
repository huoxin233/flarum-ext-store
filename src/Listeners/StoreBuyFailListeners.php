<?php

namespace Mattoid\Store\Listeners;

use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Mattoid\Store\Event\StoreBuyFailEvent;
use Mattoid\Store\Event\StoreCartEditEvent;
use Mattoid\Store\Event\StoreStockSubEvent;
use AntoineFr\Money\Service\BalanceManager;

/**
 * 购买失败处理逻辑
 * Purchase failure processing logic
 */
class StoreBuyFailListeners
{
    private $events;
    private $settings;
    private $translator;
    private $balances;


    public function __construct(
        Dispatcher $events,
        SettingsRepositoryInterface $settings,
        Translator $translator,
        BalanceManager $balances
    ) {
        $this->events = $events;
        $this->settings = $settings;
        $this->translator = $translator;
        $this->balances = $balances;
    }

    public function handle(StoreBuyFailEvent $event)
    {
        $cart = $event->cart;
        $store = $event->store;

        // 通知购物车购买失败
        // Notify shopping cart purchase failure
        $cart->status = 2;
        $this->events->dispatch(new StoreCartEditEvent($cart));

        // 回滚用户余额由 BalanceManager 安全处理
        $user = User::query()->where('id', $event->user->id)->first();

        $this->balances->applyBalanceChange(
            $user,
            $cart->pay_amt,
            'STORE_BUY_GOODS_FAIL',
            $this->translator->trans("mattoid-store.forum.buy-goods-fail", ['title' => $store->title]),
            [
                'item_title' => $store->title,
            ],
            $user,
            preventOverdraft: false
        );

        $user->save();
    }
}
