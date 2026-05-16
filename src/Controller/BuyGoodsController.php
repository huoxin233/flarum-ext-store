<?php

namespace Mattoid\Store\Controller;

use AntoineFr\Money\Service\BalanceManager;
use Carbon\Carbon;
use Flarum\Api\Controller\AbstractListController;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Flarum\User\UserRepository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Mattoid\Store\Event\StoreBuyEvent;
use Mattoid\Store\Event\StoreBuyFailEvent;
use Mattoid\Store\Event\StoreCartAddEvent;
use Mattoid\Store\Event\StoreCartEditEvent;
use Mattoid\Store\Extend\StoreExtend;
use Mattoid\Store\Model\StoreCartModel;
use Mattoid\Store\Model\StoreModel;
use Mattoid\Store\Serializer\GoodsSerializer;
use Mattoid\Store\Serializer\StoreSerializer;
use Mattoid\Store\Support\CacheKeys;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Tobscure\JsonApi\Document;

/**
 * 购买商品
 * Purchase goods.
 *
 * 修复要点：
 * - V-04: 全流程 DB::transaction 包裹，保证 partial failure 时整体回滚
 * - V-05: catch \Throwable 替代原错误的 catch (Mockery\Exception)
 * - V-09 / V-15: cache key 引入 namespace，避免与其他流程冲突
 * - V-16: 资金处理统一交给 BalanceManager（去除 MoneyHistory 软依赖）
 * - 06: 商品类型 / popUp 改为从 StoreExtend 运行时注册表读取
 */
class BuyGoodsController extends AbstractListController
{
    public $serializer = StoreSerializer::class;

    protected $repository;
    protected $translator;
    protected $settings;
    protected $events;
    protected $cache;
    protected $balances;
    protected $logger;

    private $storeTimezone = 'Asia/Shanghai';

    public function __construct(
        SettingsRepositoryInterface $settings,
        UserRepository $repository,
        Dispatcher $events,
        Translator $translator,
        CacheContract $cache,
        BalanceManager $balances,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->events = $events;
        $this->settings = $settings;
        $this->translator = $translator;
        $this->repository = $repository;
        $this->balances = $balances;
        $this->logger = $logger;

        $storeTimezone = $this->settings->get('mattoid-store.storeTimezone', 'Asia/Shanghai');
        $this->storeTimezone = $storeTimezone ?: 'Asia/Shanghai';
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getParsedBody();
        $id = Arr::get($params, 'id');

        if (! $actor->can('mattoid-store.group-view')) {
            throw new PermissionDeniedException();
        }

        if (! $id) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.store-goods-non-existent')]);
        }

        // V-09 / V-15: 引入命名空间，避免 hash 与 auto-deduction 锁串扰
        // V-09 / V-15: namespaced cache key to avoid collision with auto-deduction lock
        $lockKey = CacheKeys::buyLock($id, $actor->id);
        if (! $this->cache->add($lockKey, time(), 5)) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.validate-fail')]);
        }

        try {
            // V-04: 全流程事务
            // V-04: full transaction wrap
            return DB::transaction(function () use ($actor, $params, $id) {
                $store = StoreModel::query()->where('id', $id)->where('status', 1)->first();
                if (! $store) {
                    throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.store-goods-non-existent')]);
                }
                if ($store->stock != -99 && $store->stock <= 0) {
                    throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.insufficient-inventory')]);
                }

                // 商品类型运行时校验（Solution A）
                // Validate product type from runtime registry (Solution A)
                if (! StoreExtend::has($store->code)) {
                    throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.store-goods-non-existent')]);
                }

                if ($store->repeat == 0) {
                    $storeCart = StoreCartModel::query()
                        ->where('user_id', $actor->id)
                        ->where('store_id', $store->id)
                        ->where('status', 1)
                        ->where(function ($where) {
                            $where->where(function ($q) {
                                $q->where('type', 'limit')
                                    ->where('outtime', '>=', Carbon::now()->tz($this->storeTimezone));
                            });
                            $where->orWhere('type', 'permanent');
                        })
                        ->first();
                    if ($storeCart) {
                        throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.cannot-purchase-repeatedly')]);
                    }
                }

                $validate = StoreExtend::getValidate($store->code);
                if ($validate && ! $validate->validate($actor, $store, $params)) {
                    throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.validate-fail')]);
                }

                // 折扣窗口判断
                // Discount window check
                $price = (float) $store->price;
                $now = time();
                $endTime = Carbon::parse($store->updated_at)
                    ->tz($this->storeTimezone)
                    ->modify('+'.$store->discount_limit.' '.$store->discount_limit_unit)
                    ->getTimestamp();
                if ($store->discount_price > 0 && $store->discount > 0 && $now < $endTime) {
                    $price = (float) $store->discount_price;
                }

                $user = User::query()->where('id', $actor->id)->first();
                if (! $user) {
                    throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.user-balance-low')]);
                }

                // V-16: 资金处理交给 BalanceManager（含写入资金流水）
                // V-16: delegate balance change to BalanceManager (logs are written internally)
                $applied = $this->balances->applyBalanceChange(
                    $user,
                    -$price,
                    'STORE_BUY_GOODS',
                    $this->translator->trans('mattoid-store.forum.buy-goods', ['title' => $store->title]),
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

                // 加入购物车（监听器会原子扣减库存）
                // Create cart record (listener will atomically decrement stock)
                $carts = $this->events->dispatch(new StoreCartAddEvent($user, $store, $price));
                $cart = array_shift($carts);
                if (! $cart) {
                    throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.buy-goods-fail', ['title' => $store->title])]);
                }

                // 商品后置业务
                // Product-specific after-purchase hook
                $after = StoreExtend::getAfter($store->code);
                if ($after) {
                    $afterSuccess = false;
                    try {
                        $afterSuccess = (bool) $after->after($actor, $store, $params);
                    } catch (\Throwable $e) {
                        // V-05: catch Throwable + 日志
                        // V-05: catch Throwable + log
                        $this->logger->error('store.buy.after_failed', [
                            'store_id' => $store->id,
                            'user_id' => $actor->id,
                            'exception' => (string) $e,
                        ]);
                    }

                    if (! $afterSuccess) {
                        $this->events->dispatch(new StoreBuyFailEvent($user, $store, $cart, $params));
                        throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.buy-goods-fail', ['title' => $store->title])]);
                    }
                }

                // 标记购物车支付完成
                // Mark cart paid
                $cart->status = 1;
                $this->events->dispatch(new StoreCartEditEvent($cart));

                // 对外通知购买成功
                // Notify external listeners
                $this->events->dispatch(new StoreBuyEvent($user, $store, $cart, $params));

                $this->logger->info('store.buy.success', [
                    'store_id' => $store->id,
                    'user_id' => $actor->id,
                    'cart_id' => $cart->id,
                    'price' => $price,
                ]);

                return $store;
            });
        } finally {
            // V-09 / V-15: 锁的释放放入 finally 防止异常路径泄漏
            // Release the lock in finally to avoid leakage on exception paths
            $this->cache->delete($lockKey);
        }
    }
}
