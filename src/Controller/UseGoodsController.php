<?php

namespace Mattoid\Store\Controller;

use Flarum\Api\Controller\AbstractCreateController;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\Translator;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\UserRepository;
use Illuminate\Support\Arr;
use Mattoid\Store\Extend\StoreExtend;
use Mattoid\Store\Model\StoreCartModel;
use Mattoid\Store\Model\StoreModel;
use Mattoid\Store\Serializer\DataSerializer;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

/**
 * 用户在购物车点击「使用 / 取消使用」商品（V-06）
 * Toggle the enable state of a cart item.
 *
 * 修复要点：
 * - V-06: 权限从 group-moderate 改为 group-view（cart 已按 user_id 隔离）
 * - V-06: 增加 cart null 校验，避免 NPE
 * - V-05: catch Throwable 避免商品插件异常吞没
 * - 软删除商品（V-11）兼容：使用 withTrashed 读取 store 快照
 */
class UseGoodsController extends AbstractCreateController
{
    protected $url;
    protected $translator;
    protected $repository;

    /**
     * {@inheritdoc}
     */
    public $serializer = DataSerializer::class;

    public function __construct(UserRepository $repository, UrlGenerator $url, Translator $translator)
    {
        $this->url = $url;
        $this->translator = $translator;
        $this->repository = $repository;
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getParsedBody();
        $id = Arr::get($params, 'id');

        // V-06: cart 与 actor 已通过 user_id 隔离，仅需 group-view 权限
        // V-06: cart is isolated by user_id, view permission is sufficient
        if (! $actor->can('mattoid-store.group-view')) {
            throw new PermissionDeniedException();
        }

        if (! $id) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.cart-no-use')]);
        }

        $cart = StoreCartModel::query()
            ->where('id', $id)
            ->where('user_id', $actor->id)
            ->where('status', 1)
            ->first();

        // V-06: NPE 防御
        // V-06: NPE guard
        if (! $cart) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.cart-no-use')]);
        }

        $enable = StoreExtend::getEnable($cart->code);
        if (! $enable) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.cart-no-use')]);
        }

        // 软删除场景下保留对历史商品的访问能力
        // Allow access to soft-deleted stores for historical carts
        $store = StoreModel::withTrashed()->where('id', $cart->store_id)->first();
        if (! $store) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.store-goods-non-existent')]);
        }

        $cart->enable = ! $cart->enable ? 1 : 0;

        // V-05: catch Throwable，避免商品插件抛非 Exception 异常时静默失败
        // V-05: catch Throwable to avoid silent failure
        try {
            $ok = $enable::enable($actor, $store, $cart);
        } catch (\Throwable $e) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.cart-use-fail')]);
        }

        if (! $ok) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.cart-use-fail')]);
        }

        return $cart->save();
    }
}
