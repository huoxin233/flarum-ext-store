<?php

namespace Mattoid\Store\Listeners;

use AntoineFr\Money\Service\BalanceManager;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Mattoid\Store\Event\StoreBuyFailEvent;
use Mattoid\Store\Event\StoreCartEditEvent;
use Psr\Log\LoggerInterface;

/**
 * 购买失败处理逻辑
 * Purchase failure handling.
 *
 * 修复要点：
 * - 02: BalanceManager 替代手写乐观锁退款
 * - V-16: 移除 MoneyHistory 软依赖（BalanceManager 自动写流水）
 * - V-05: catch Throwable
 */
class StoreBuyFailListeners
{
    private $events;
    private $settings;
    private $translator;
    private $balances;
    private $logger;

    public function __construct(
        Dispatcher $events,
        SettingsRepositoryInterface $settings,
        Translator $translator,
        BalanceManager $balances,
        LoggerInterface $logger
    ) {
        $this->events = $events;
        $this->settings = $settings;
        $this->translator = $translator;
        $this->balances = $balances;
        $this->logger = $logger;
    }

    public function handle(StoreBuyFailEvent $event)
    {
        $cart = $event->cart;
        $store = $event->store;

        // 标记购物车失败（监听器会回滚库存）
        // Mark cart as failed (listener will roll back stock)
        $cart->status = 2;
        try {
            $this->events->dispatch(new StoreCartEditEvent($cart));
        } catch (\Throwable $e) {
            $this->logger->error('store.buy.fail_cart_edit', [
                'cart_id' => $cart->id ?? null,
                'exception' => (string) $e,
            ]);
        }

        $user = User::query()->where('id', $event->user->id)->first();
        if (! $user) {
            return;
        }

        try {
            // 02: BalanceManager 退款 + 资金流水
            $this->balances->applyBalanceChange(
                $user,
                (float) $cart->pay_amt,
                'STORE_BUY_GOODS_FAIL',
                $this->translator->trans('mattoid-store.forum.buy-goods-fail', ['title' => $store->title]),
                [
                    'item_title' => $store->title,
                ],
                $user,
                preventOverdraft: false
            );

            $user->save();
        } catch (\Throwable $e) {
            $this->logger->error('store.buy.refund_failed', [
                'user_id' => $user->id,
                'cart_id' => $cart->id ?? null,
                'exception' => (string) $e,
            ]);
        }
    }
}
