<?php

namespace Mattoid\Store\Controller;

use Flarum\Api\Controller\AbstractListController;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Mattoid\Store\Controller\Concerns\ResolvesTargetUser;
use Mattoid\Store\Model\StoreGiftLogModel;
use Mattoid\Store\Serializer\StoreGiftLogSerializer;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

/**
 * 赠送记录（获赠视角，分页）
 */
class ListGiftLogController extends AbstractListController
{
    use ResolvesTargetUser;

    public $serializer = StoreGiftLogSerializer::class;

    /** 每页条数上限 */
    const MAX_LIMIT = 50;

    protected $url;

    public function __construct(UrlGenerator $url)
    {
        $this->url = $url;
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $userId = $this->resolveTargetUserId($request, $actor);

        $params = $request->getQueryParams();
        $limit = min($this->extractLimit($request), self::MAX_LIMIT);
        $offset = $this->extractOffset($request);

        $query = StoreGiftLogModel::query()
            ->where('user_id', $userId)
            ->orderByDesc('id');

        $total = $query->count();
        $logs = $query->skip($offset)->take($limit)->get();

        $document->addPaginationLinks(
            $this->url->to('api')->route('store.gift.list'),
            $params,
            $offset,
            $limit,
            $offset + $limit < $total ? null : 0
        );

        return $logs;
    }
}
