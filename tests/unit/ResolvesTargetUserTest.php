<?php

namespace Mattoid\Store\Tests\unit;

use Flarum\Foundation\ValidationException;
use Flarum\Testing\unit\TestCase;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mattoid\Store\Controller\Concerns\ResolvesTargetUser;
use Mockery;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 测试控制器目标用户鉴权与解析 Trait：
 * 验证游客拦截、无权限拦截、本人放行、越权拦截、非法输入与实体校验
 */
class ResolvesTargetUserDummy
{
    use ResolvesTargetUser;

    /** @var array */
    public $filters = [];

    /** @var array<int> */
    public $existingUserIds = [];

    protected function extractFilter(ServerRequestInterface $request)
    {
        return $this->filters;
    }

    protected function userExists(int $userId): bool
    {
        return in_array($userId, $this->existingUserIds, true);
    }

    public function resolve(ServerRequestInterface $request, User $actor): int
    {
        return $this->resolveTargetUserId($request, $actor);
    }
}

class ResolvesTargetUserTest extends TestCase
{
    /** @var ResolvesTargetUserDummy */
    private $controller;

    /** @var ServerRequestInterface */
    private $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ResolvesTargetUserDummy();
        $this->request = Mockery::mock(ServerRequestInterface::class);
    }

    private function createActor(int $id, bool $canView = true, bool $canModerate = false): User
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->id = $id;
        $actor->shouldReceive('isGuest')->andReturn(false);
        $actor->shouldReceive('can')->with('mattoid-store.group-view')->andReturn($canView);
        $actor->shouldReceive('can')->with('mattoid-store.group-moderate')->andReturn($canModerate);

        return $actor;
    }

    /**
     * 未登录游客应直接抛出 PermissionDeniedException (HTTP 403)
     */
    public function test_guest_is_rejected()
    {
        $this->expectException(PermissionDeniedException::class);

        $guest = new Guest();
        $this->controller->resolve($this->request, $guest);
    }

    /**
     * 无商店基础访问权限的用户（无法 view store）应被拦截
     */
    public function test_actor_without_group_view_permission_is_rejected()
    {
        $this->expectException(PermissionDeniedException::class);

        $actor = $this->createActor(42, false, false);
        $this->controller->resolve($this->request, $actor);
    }

    /**
     * 未传 filter[user_id] 或传空时，默认回退为当前登录用户本人 ID
     */
    public function test_defaults_to_actor_id_when_filter_user_id_omitted()
    {
        $actor = $this->createActor(42, true, false);
        $this->controller->filters = [];

        $resolved = $this->controller->resolve($this->request, $actor);
        $this->assertSame(42, $resolved);

        $this->controller->filters = ['user_id' => ''];
        $resolvedEmpty = $this->controller->resolve($this->request, $actor);
        $this->assertSame(42, $resolvedEmpty);
    }

    /**
     * 普通用户显式传自己的 user_id 时，无需管理权限即可放行
     */
    public function test_allows_normal_user_to_query_themselves()
    {
        $actor = $this->createActor(42, true, false);
        $this->controller->filters = ['user_id' => '42'];

        $resolved = $this->controller->resolve($this->request, $actor);
        $this->assertSame(42, $resolved);
    }

    /**
     * 水平越权拦截（BOLA/IDOR）：普通用户无管理权限时，查询他人 ID 必须抛出 PermissionDeniedException
     */
    public function test_blocks_normal_user_from_querying_other_users()
    {
        $this->expectException(PermissionDeniedException::class);

        $actor = $this->createActor(42, true, false);
        $this->controller->filters = ['user_id' => '99'];
        $this->controller->existingUserIds = [99];

        $this->controller->resolve($this->request, $actor);
    }

    /**
     * 类型防御：非数字字符串应抛出 ValidationException
     */
    public function test_rejects_non_numeric_user_id()
    {
        $this->expectException(ValidationException::class);

        $actor = $this->createActor(42, true, false);
        $this->controller->filters = ['user_id' => 'invalid_user'];

        $this->controller->resolve($this->request, $actor);
    }

    /**
     * 范围防御：0 或负数 ID 应抛出 ValidationException
     */
    public function test_rejects_zero_or_negative_user_id()
    {
        $this->expectException(ValidationException::class);

        $actor = $this->createActor(42, true, false);
        $this->controller->filters = ['user_id' => '0'];

        $this->controller->resolve($this->request, $actor);
    }

    /**
     * 范围防御：负数 ID 应抛出 ValidationException
     */
    public function test_rejects_negative_user_id()
    {
        $this->expectException(ValidationException::class);

        $actor = $this->createActor(42, true, false);
        $this->controller->filters = ['user_id' => '-5'];

        $this->controller->resolve($this->request, $actor);
    }

    /**
     * 管理员/具备管理权限的用户查询存在的他人，应正常解析其 ID
     */
    public function test_moderator_can_resolve_existing_other_user()
    {
        $moderator = $this->createActor(1, true, true);
        $this->controller->filters = ['user_id' => '99'];
        $this->controller->existingUserIds = [99];

        $resolved = $this->controller->resolve($this->request, $moderator);
        $this->assertSame(99, $resolved);
    }

    /**
     * 管理员查询不存在的用户（幽灵用户）时，应显式抛出 ModelNotFoundException (HTTP 404)
     */
    public function test_moderator_querying_non_existent_user_throws_404()
    {
        $this->expectException(ModelNotFoundException::class);

        $moderator = $this->createActor(1, true, true);
        $this->controller->filters = ['user_id' => '9999'];
        $this->controller->existingUserIds = [99]; // 9999 不存在

        $this->controller->resolve($this->request, $moderator);
    }
}
