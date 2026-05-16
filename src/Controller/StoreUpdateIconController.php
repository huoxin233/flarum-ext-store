<?php

namespace Mattoid\Store\Controller;

use Carbon\Carbon;
use Flarum\Api\Controller\AbstractCreateController;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Mattoid\Store\Model\StoreGoodsIconModel;
use Mattoid\Store\Serializer\DataSerializer;
use Mattoid\Store\Upload\StoreUploader;
use Mattoid\Store\Upload\StoreValidator;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

/**
 * 上传图标（V-01 + V-02 + V-17）
 * Upload icon.
 *
 * 修复要点：
 * - V-01: 增加 group-moderate 权限校验
 * - V-02: 配合 StoreUploader / StoreValidator 实施 MIME + 扩展名白名单
 * - V-17: 记录相对路径，避免跨域名迁移失效
 */
class StoreUpdateIconController extends AbstractCreateController
{
    public $serializer = DataSerializer::class;
    public $include = ['store'];

    protected $settings;
    protected $uploader;
    protected $validator;
    protected $translator;

    private $storeTimezone = 'Asia/Shanghai';

    public function __construct(SettingsRepositoryInterface $settings, StoreUploader $uploader, StoreValidator $validator, Translator $translator)
    {
        $this->uploader = $uploader;
        $this->settings = $settings;
        $this->validator = $validator;
        $this->translator = $translator;

        $storeTimezone = $this->settings->get('mattoid-store.storeTimezone', 'Asia/Shanghai');
        $this->storeTimezone = $storeTimezone ?: 'Asia/Shanghai';
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);

        // V-01: 必须为管理员/版主
        // V-01: require moderator privilege
        if (! $actor->can('mattoid-store.group-moderate')) {
            throw new PermissionDeniedException();
        }

        $file = Arr::get($request->getUploadedFiles(), 'file');
        if (! $file) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.file-required')]);
        }

        $this->validator->assertValid(['file' => $file]);

        $icon = new StoreGoodsIconModel();
        $icon->uuid = $this->uploader->getFileMd5($file);
        try {
            $icon->save();
        } catch (QueryException $e) {
            throw new ValidationException(['message' => $this->translator->trans('mattoid-store.forum.error.file-exist')]);
        }

        $icon->increment('count');

        $uploaded = $this->uploader->upload($file);
        $icon->url = $uploaded['url'];     // V-17: serializer 输出使用，相对路径建议存额外列；
                                            //        当前先维持 url 字段，迁移完成后可拆 filename/url。
        $icon->created_at = Carbon::now()->tz($this->storeTimezone);
        $icon->updated_at = Carbon::now()->tz($this->storeTimezone);
        $icon->save();

        return [
            'id' => $icon->id,
            'path' => $icon->url,
        ];
    }
}
