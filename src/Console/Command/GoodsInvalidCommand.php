<?php

namespace Mattoid\Store\Console\Command;

use AntoineFr\Money\Service\BalanceManager;
use Carbon\Carbon;
use Flarum\Console\AbstractCommand;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Mattoid\Store\Event\StoreInvalidEvent;
use Mattoid\Store\Extend\StoreExtend;
use Mattoid\Store\Model\StoreCartModel;
use Mattoid\Store\Model\StoreModel;
use Mattoid\Store\Support\CacheKeys;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * 处理失效商品通知逻辑
 * Process invalid products & auto-renewal.
 *
 * 修复要点：
 * - 02: BalanceManager 取代手写 user.money 乐观锁
 * - 02: isEmpty() 替代错误的 !$collection 判空
 * - 02: ->pluck('store_id')->unique()->toArray() 替代 array_column(json_decode())
 * - 02: try/finally 释放锁
 * - 02: storeMap[$cart->store_id] ?? null 防御 + skip
 * - V-05: catch Throwable
 * - V-15: 独立 cache key namespace
 */
class GoodsInvalidCommand extends AbstractCommand
{
    protected $cache;
    protected $events;
    protected $settings;
    protected $translator;
    protected $balances;
    protected $logger;

    private $storeTimezone = 'Asia/Shanghai';

    public function __construct(
        SettingsRepositoryInterface $settings,
        TranslatorInterface $translator,
        Repository $cache,
        Dispatcher $events,
        BalanceManager $balances,
        LoggerInterface $logger
    ) {
        parent::__construct();
        $this->cache = $cache;
        $this->events = $events;
        $this->settings = $settings;
        $this->translator = $translator;
        $this->balances = $balances;
        $this->logger = $logger;

        $storeTimezone = $this->settings->get('mattoid-store.storeTimezone', 'Asia/Shanghai');
        $this->storeTimezone = $storeTimezone ?: 'Asia/Shanghai';
    }

    protected function configure()
    {
        $this->setName('mattoid:store:check:date')->setDescription('Check the expiration time of the product');
    }

    protected function fire()
    {
        $storeMap = [];
        $dateTime = Carbon::now()->tz($this->storeTimezone);

        $invalidList = StoreCartModel::query()
            ->where('outtime', '<=', $dateTime)
            ->where('type', 'limit')
            ->where('status', 1)
            ->get();

        // 02: Collection 不为 false，需用 isEmpty()
        // 02: Collection is never falsy, use isEmpty()
        if ($invalidList->isEmpty()) {
            return;
        }

        // 02: pluck + unique 替代 array_column(json_decode)
        $storeIdList = $invalidList->pluck('store_id')->unique()->toArray();
        $storeList = StoreModel::withTrashed()->whereIn('id', $storeIdList)->get();
        foreach ($storeList as $store) {
            $storeMap[$store->id] = $store;
        }

        foreach ($invalidList as $cart) {
            $buyStatus = true;

            // 02: storeMap 缺失防御
            // 02: storeMap missing guard
            $store = $storeMap[$cart->store_id] ?? null;
            if (! $store) {
                $this->error("[{$cart->store_id}] Store not found, skipping cart {$cart->id}");
                $buyStatus = false;
                try {
                    $cart->status = 2;
                    $cart->save();
                } catch (\Throwable $e) {
                    $this->logger->error('store.invalid.mark_failed', ['cart_id' => $cart->id, 'exception' => (string) $e]);
                }
                continue;
            }

            try {
                $this->autoDeduction($store, $cart);
            } catch (\Throwable $e) {
                $buyStatus = false;
                $this->error("[{$cart->code}] {$cart->store_id}:{$cart->id}: {$this->translator->trans('mattoid-store.forum.error.automatic-renewal-fail', ['message' => $e->getMessage()])}");
                try {
                    $invalid = StoreExtend::getInvalid($cart->code);
                    if ($invalid) {
                        $invalid->invalid($store, $cart);
                    }

                    $cart->status = 2;
                    $cart->save();
                } catch (\Throwable $inner) {
                    $this->error("[{$cart->code}] {$cart->store_id}:{$cart->id}: {$this->translator->trans('mattoid-store.forum.error.goods-invalid-fail', ['message' => $inner->getMessage()])}");
                    $this->logger->error('store.invalid.handler_failed', [
                        'cart_id' => $cart->id,
                        'exception' => (string) $inner,
                    ]);
                }
            }

            try {
                $this->events->dispatch(new StoreInvalidEvent($store, $cart, $buyStatus));
            } catch (\Throwable $e) {
                $this->error("[{$cart->code}] {$cart->store_id}:{$cart->id}: {$this->translator->trans('mattoid-store.forum.error.goods-invalid-event-fail', ['message' => $e->getMessage()])}");
            }
        }
    }

    private function autoDeduction(StoreModel $store, StoreCartModel $cart): void
    {
        if (! $cart->auto_deduction) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.automatic-renewal')]);
        }

        if ($store->status != 1 || $store->trashed()) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.invalid-product')]);
        }

        $key = CacheKeys::autoDeductionLock($cart->store_id, $cart->user_id);
        if (! $this->cache->add($key, time(), 5)) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.validate-fail')]);
        }

        try {
            $user = User::query()->where('id', $cart->user_id)->first();
            if (! $user) {
                throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.user-balance-low')]);
            }

            // 02: BalanceManager 处理扣费 + 资金流水
            $applied = $this->balances->applyBalanceChange(
                $user,
                -(float) $cart->price,
                'STORE_AUTO_DEDUCTION',
                $this->translator->trans('mattoid-store.forum.auto-deduction', ['title' => $store->title]),
                [
                    'item_title' => $store->title,
                ],
                $user,
                preventOverdraft: true
            );
            if (! $applied) {
                throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.user-balance-low')]);
            }
            $user->save();

            // 刷新过期时间
            // Refresh expiry timestamp
            $cart->pay_amt = $cart->price;
            $cart->outtime = Carbon::now()->tz($this->storeTimezone)->addDays($store->outtime);
            $cart->created_at = Carbon::now()->tz($this->storeTimezone);
            $cart->save();
        } finally {
            // 02: try/finally 保证异常路径也释放锁
            $this->cache->delete($key);
        }
    }
}
