<?php

namespace Mattoid\Store\Controller\Concerns;

use Flarum\Foundation\ValidationException;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 解析并鉴权目标用户 ID：
 * 1. 强制校验登录态与基础访问权限（mattoid-store.group-view）
 * 2. 严格遵循 JSON:API 规范，仅从 filter[user_id] 提取过滤参数
 * 3. 严格数据类型与范围防御（必须为正整数标量，杜绝数组污染）
 * 4. 水平越权防御（BOLA/IDOR）：非本人必须具备 mattoid-store.group-moderate 管理权限
 * 5. 目标实体存在性校验：不存在时抛出 ModelNotFoundException (HTTP 404)
 */
trait ResolvesTargetUser
{
    /**
     * @param ServerRequestInterface $request
     * @param User $actor
     * @return int
     *
     * @throws PermissionDeniedException
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    protected function resolveTargetUserId(ServerRequestInterface $request, User $actor): int
    {
        if ($actor->isGuest() || !$actor->can('mattoid-store.group-view')) {
            throw new PermissionDeniedException();
        }

        $filters = $this->extractFilter($request);
        $requested = Arr::get($filters, 'user_id');

        // 未显式指定 filter[user_id] 时，默认为查询本人
        if ($requested === null || $requested === '') {
            return (int) $actor->id;
        }

        // 严格类型校验：必须是数字标量且大于 0，杜绝 array 注入、负数或非法字符串
        if (!is_numeric($requested) || (int) $requested <= 0) {
            throw new ValidationException(['filter.user_id' => 'Invalid user ID']);
        }

        $targetId = (int) $requested;

        // 若查询他人，必须具备管理权限
        if ($targetId !== (int) $actor->id) {
            if (!$actor->can('mattoid-store.group-moderate')) {
                throw new PermissionDeniedException();
            }

            // 校验目标用户是否存在，不存在直接 404，防止对幽灵用户执行空查询或传递给第三方插件
            if (!User::query()->where('id', $targetId)->exists()) {
                throw new ModelNotFoundException();
            }
        }

        return $targetId;
    }
}
