<?php

namespace Mattoid\Store\Controller;

use Flarum\Api\Controller\AbstractListController;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\Translator;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\UserRepository;
use Mattoid\Store\Extend\StoreExtend;
use Mattoid\Store\Serializer\GoodsSerializer;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

/**
 * 获取可添加商品列表（管理端）
 * Get a list of products that can be added (on the management end)
 *
 * Solution A: 数据来源为 StoreExtend 运行时注册表，不再查询 store_goods 表。
 * Data source is StoreExtend's runtime registry, no longer querying store_goods table.
 */
class ListGoodsController extends AbstractListController
{
    protected $url;
    protected $translator;
    protected $repository;

    /**
     * {@inheritdoc}
     */
    public $serializer = GoodsSerializer::class;

    public function __construct(UserRepository $repository, UrlGenerator $url, Translator $translator)
    {
        $this->url = $url;
        $this->translator = $translator;
        $this->repository = $repository;
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();
        $limit = $this->extractLimit($request);
        $offset = $this->extractOffset($request);

        if (! $actor->can('mattoid-store.group-moderate')) {
            throw new PermissionDeniedException();
        }

        // 从运行时注册表聚合商品元数据
        // Aggregate product metadata from the runtime registry
        $all = collect(StoreExtend::codes())
            ->map(function (string $code) {
                $goods = StoreExtend::getStoreGoods($code);
                if (! $goods) {
                    return null;
                }
                // 通过反射读取受保护属性 name（保持基类兼容性）
                // Read protected `name` via reflection (keeps the abstract base class compatible)
                $reflection = new \ReflectionObject($goods);
                $nameProp = $reflection->hasProperty('name') ? $reflection->getProperty('name') : null;
                $nameProp && $nameProp->setAccessible(true);
                $name = $nameProp ? $nameProp->getValue($goods) : $code;

                return (object) [
                    'code' => $code,
                    'name' => $name,
                ];
            })
            ->filter()
            ->values();

        $total = $all->count();
        $hasMore = $limit > 0 && ($offset + $limit) < $total;

        $document->addPaginationLinks(
            $this->url->to('api')->route('store.goods.list'),
            $params,
            $offset,
            $limit,
            $hasMore ? null : 0
        );

        return $limit > 0 ? $all->slice($offset, $limit)->values() : $all;
    }
}
