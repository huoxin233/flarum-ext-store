<?php

namespace Mattoid\Store\Controller;

use Carbon\Carbon;
use Flarum\Api\Controller\AbstractCreateController;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\UserRepository;
use Mattoid\Store\Extend\StoreExtend;
use Mattoid\Store\Model\StoreModel;
use Mattoid\Store\Serializer\DataSerializer;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

/**
 * 编辑商品
 * Edit Goods
 */
class PutStoreController extends AbstractCreateController
{
    /**
     * 允许从前端 PUT 写入的字段白名单（V-07: mass-assignment 防护）
     * Whitelist of fields allowed from PUT body (V-07)
     */
    private const ALLOWED_FIELDS = [
        'code', 'title', 'desc', 'icon',
        'price', 'stock', 'type', 'outtime',
        'discount', 'discount_limit', 'discount_limit_unit',
        'hide', 'repeat', 'status', 'auto_deduction',
    ];

    /**
     * 折扣期限单位白名单（V-13）
     * Whitelist of allowed discount_limit_unit values (V-13)
     */
    private const ALLOWED_LIMIT_UNITS = ['days', 'hour', 'minute', 'second'];

    protected $url;
    protected $settings;
    protected $translator;
    protected $repository;

    /**
     * {@inheritdoc}
     */
    public $serializer = DataSerializer::class;

    private $storeTimezone = 'Asia/Shanghai';

    public function __construct(SettingsRepositoryInterface $settings, UserRepository $repository, UrlGenerator $url, Translator $translator)
    {
        $this->url = $url;
        $this->settings = $settings;
        $this->translator = $translator;
        $this->repository = $repository;

        $storeTimezone = $this->settings->get('mattoid-store.storeTimezone', 'Asia/Shanghai');
        $this->storeTimezone = $storeTimezone ?: 'Asia/Shanghai';
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

        $normalized = $this->normalizeKeys($parseBody);

        // V-07: 白名单过滤
        // V-07: Whitelist filtering
        $params = array_intersect_key($normalized, array_flip(self::ALLOWED_FIELDS));

        $code = $params['code'] ?? null;
        $status = (int) ($params['status'] ?? 0);

        // V-08: 仅当尝试上架（status=1）时强制校验商品类型存在；下架/隐藏允许保留孤儿商品
        // V-08: enforce product type existence only when attempting to publish (status=1);
        //       allow orphaned rows to be downgraded for cleanup
        if ($status === 1 && (! $code || ! StoreExtend::has($code))) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.admin.error.invalid-product')]);
        }

        // V-13: discount_limit_unit 白名单
        // V-13: discount_limit_unit whitelist
        if (isset($params['discount_limit_unit']) && $params['discount_limit_unit'] !== ''
            && ! in_array($params['discount_limit_unit'], self::ALLOWED_LIMIT_UNITS, true)) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.admin.error.invalid-product')]);
        }

        // 0 或 -99 视为无限库存
        // 0 or -99 is treated as unlimited stock
        if (isset($params['stock']) && ((int) $params['stock'] === 0 || (int) $params['stock'] === -99)) {
            $params['stock'] = -99;
        }

        if (isset($params['price']) && isset($params['discount'])) {
            $price = $params['price'];
            $discount = $params['discount'];
            if (extension_loaded('bcmath')) {
                $params['discount_price'] = bcdiv(bcmul((string) $price, (string) $discount), '100', 2);
            } else {
                $params['discount_price'] = round(((float) $price) * ((float) $discount) / 100, 2);
            }
        }

        // Solution A: 不再写入 pop_up / class_name
        // Solution A: pop_up / class_name are no longer persisted
        unset($params['pop_up'], $params['class_name']);

        $params['updated_at'] = Carbon::now()->tz($this->storeTimezone);

        return StoreModel::query()->where('id', $id)->update($params);
    }

    /**
     * camelCase → snake_case 键归一
     */
    private function normalizeKeys(array $body): array
    {
        $result = [];
        foreach ($body as $key => $value) {
            $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key));
            $result[$snake] = $value;
        }
        return $result;
    }
}
