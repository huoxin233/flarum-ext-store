<?php

namespace Mattoid\Store\Controller;

use Flarum\Api\Controller\AbstractCreateController;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\Translator;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\UserRepository;
use Mattoid\Store\Model\StoreModel;
use Mattoid\Store\Serializer\DataSerializer;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

/**
 * 从商店删除商品（软删除，V-11）
 * Delete item from store (soft delete, V-11)
 */
class DeleteStoreController extends AbstractCreateController
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
        $parseBody = $request->getParsedBody();

        if (! $actor->can('mattoid-store.group-moderate')) {
            throw new PermissionDeniedException();
        }

        $id = $parseBody['id'] ?? null;
        if (! $id) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.admin.error.invalid-product')]);
        }

        // 软删除（V-11）：保留 store_cart 历史数据的可解析性
        // Soft delete (V-11): keep historical store_cart records resolvable
        return StoreModel::query()->where('id', $id)->delete();
    }
}
