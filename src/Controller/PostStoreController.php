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
 * 添加商品
 * Add Goods
 */
class PostStoreController extends AbstractCreateController
{
    /**
     * 允许从前端 POST 写入的字段白名单（V-07: mass-assignment 防护）
     * Whitelist of fields allowed from POST body (V-07: mass-assignment protection)
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

        // 将驼峰键归一为下划线
        // Normalize camelCase keys to snake_case
        $normalized = $this->normalizeKeys($parseBody);

        // V-07: 白名单过滤
        // V-07: Whitelist filtering
        $params = array_intersect_key($normalized, array_flip(self::ALLOWED_FIELDS));

        if (empty($params['code'])) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.admin.error.invalid-product')]);
        }

        // Solution A: 从运行时注册表校验商品类型存在
        // Solution A: validate product type from runtime registry
        if (! StoreExtend::has($params['code'])) {
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
        if (! isset($params['stock']) || $params['stock'] === '' || (int) $params['stock'] === 0 || (int) $params['stock'] === -99) {
            $params['stock'] = -99;
        }

        // 折扣价计算（V-12 配套迁移已改为 decimal(10,2)）
        // Calculate discount price (V-12: migration changes column to decimal(10,2))
        $price = $params['price'] ?? 0;
        $discount = $params['discount'] ?? 0;
        if (extension_loaded('bcmath')) {
            $params['discount_price'] = bcdiv(bcmul((string) $price, (string) $discount), '100', 2);
        } else {
            $params['discount_price'] = round(((float) $price) * ((float) $discount) / 100, 2);
        }

        // Solution A: 不再写入 pop_up / class_name（运行时由 serializer 注入）
        // Solution A: pop_up / class_name are no longer persisted (injected at serialize time)
        unset($params['pop_up'], $params['class_name']);

        $now = Carbon::now()->tz($this->storeTimezone);
        $params['created_at'] = $now;
        $params['updated_at'] = $now;

        return StoreModel::query()->insertGetId($params);
    }

    /**
     * camelCase → snake_case 键归一
     * Normalize camelCase keys to snake_case
     */
    private function normalizeKeys(array $body): array
    {
        $result = [];
        foreach ($body as $key => $value) {
            // 保留空字符串以便 stock 默认 -99 的判断
            // Preserve empty strings so the stock-default-to-(-99) check works
            $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key));
            $result[$snake] = $value;
        }
        return $result;
    }
}
