<?php

namespace Mattoid\Store\Controller;

use Carbon\Carbon;
use Flarum\Api\Controller\AbstractListController;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use Flarum\User\UserRepository;
use Illuminate\Support\Arr;
use Flarum\User\User;
use Mattoid\Store\Event\StoreBuyFailEvent;
use Mattoid\Store\Event\StoreCartAddEvent;
use Mattoid\Store\Event\StoreBuyEvent;
use Mattoid\Store\Event\StoreCartEditEvent;
use Mattoid\Store\Extend\StoreExtend;
use Mattoid\Store\Model\StoreCartModel;
use Mattoid\Store\Model\StoreModel;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Mattoid\Store\Serializer\GoodsSerializer;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;
use AntoineFr\Money\Service\BalanceManager;

/**
 * 购买商品
 * Purchase goods
 */
class BuyGoodsController extends AbstractListController
{
    public $serializer = GoodsSerializer::class;

    protected $repository;
    protected $translator;
    protected $settings;
    protected $events;
    protected $cache;
    protected $balances;

    private $storeTimezone = 'Asia/Shanghai';


    public function __construct(
        SettingsRepositoryInterface $settings,
        UserRepository $repository,
        Dispatcher $events,
        Translator $translator,
        CacheContract $cache,
        BalanceManager $balances
    ) {
        $this->cache = $cache;
        $this->events = $events;
        $this->settings = $settings;
        $this->translator = $translator;
        $this->repository = $repository;
        $this->balances = $balances;

        $storeTimezone = $this->settings->get('mattoid-store.storeTimezone', 'Asia/Shanghai');
        $this->storeTimezone = ! ! $storeTimezone ? $storeTimezone : 'Asia/Shanghai';
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getParsedBody();
        $id = Arr::get($params, 'id');

        // 验证是否有商店 查看/购买 权限
        if (! $actor->can('mattoid-store.group-view')) {
            throw new PermissionDeniedException();
        }

        $key = md5("{$params['id']}-{$actor->id}");
        if (! $this->cache->add($key, time(), 5)) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.validate-fail')]);
        }

        $store = StoreModel::query()->where('id', $id)->where('status', 1)->first();
        if (! $store) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.store-goods-non-existent')]);
        }
        if ($store->stock != -99 && $store->stock <= 0) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.insufficient-inventory')]);
        }
        if ($store->repeat == 0) {
            $storeCart = StoreCartModel::query()->where('user_id', $actor->id)->where('store_id', $store->id)
                ->where('status', 1)->where(function ($where) {
                    $where->where(function ($where) {
                        $where->where('type', 'limit')->where('outtime', '>=', Carbon::now()->tz($this->storeTimezone));
                    });
                    $where->orWhere('type', 'permanent');
                })->first();
            if ($storeCart) {
                throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.cannot-purchase-repeatedly')]);
            }
        }

        $validate = StoreExtend::getValidate($store->code);
        // $this->translator, $this->settings, $this->events, $this->cache
        if ($validate && ! $validate->validate($actor, $store, $params)) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.validate-fail')]);
        }

        $user = User::query()->where('id', $actor->id)->first();
        // 开始扣费由 BalanceManager 处理以保证事务安全性和并发防透支机制
        $price = $store->price;
        // 计算折扣
        $time = time();
        $endTime = Carbon::parse($store->updated_at)->tz($this->storeTimezone)->modify('+'.$store->discount_limit.' '.$store->discount_limit_unit)->getTimestamp();
        if ($store->discount_price > 0 && $store->discount > 0 && $time < $endTime) {
            $price = $store->discount_price;
        }

        $applied = $this->balances->applyBalanceChange(
            $user,
            -$price,
            'STORE_BUY_GOODS',
            $this->translator->trans("mattoid-store.forum.buy-goods", ['title' => $store->title]),
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

        // 加入购物车 购物车会自动扣除库存
        $carts = $this->events->dispatch(new StoreCartAddEvent($user, $store, $price));
        $cart = array_shift($carts);

        // 处理商品插件后置事件
        $after = StoreExtend::getAfter($store->code);
        if ($after) {
            // 商品处理失败，通知购买失败事件进行回滚操作
            $afterSuccess = false;
            try {
                $afterSuccess = $after->after($actor, $store, $params);
            } catch (\Throwable $e) {
                // after() threw an unexpected exception
            }

            if (! $afterSuccess) {
                $this->events->dispatch(new StoreBuyFailEvent($user, $store, $cart, $params));
                throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.buy-goods-fail', ['title' => $store->title])]);
            }
        }

        // 通知购物车购买成功
        $cart->status = 1;
        $this->events->dispatch(new StoreCartEditEvent($cart));

        // 通知其他插件购买完成
        $this->events->dispatch(new StoreBuyEvent($user, $store, $cart, $params));

        $this->cache->delete($key);

        return $store;
    }
}
