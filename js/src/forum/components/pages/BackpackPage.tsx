import app from 'flarum/forum/app';
import type Mithril from 'mithril';

import UserPage from 'flarum/forum/components/UserPage';
import Button from 'flarum/common/components/Button';
import BackpackItem from '../component/BackpackItem';
import LoadingBlock from '../component/LoadingBlock';
import PaginatedList from '../PaginatedList';
import bindInfiniteScroll from '../../utils/bindInfiniteScroll';

export default class BackpackPage extends UserPage {
  private list: PaginatedList<StoreApiResource> = new PaginatedList({
    endpoint: '/store/backpack/list',
    params: () => ({
      filter: {
        user_id: this.user ? this.user.id() : undefined,
      },
    }),
    // 保留原行为：整页刷新失败时清空列表
    clearItemsOnRefreshError: true,
  });
  private unbindScroll: (() => void) | null = null;

  isAllowed(): boolean {
    const actor = app.session?.user;
    if (!actor) return false;
    if (!this.user) return true;
    return this.user.id() === actor.id() || Boolean(actor.isAdmin?.() || actor.attribute('canStoreModerate'));
  }

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);

    this.loadUser(m.route.param('username'));
  }

  show(user: any) {
    super.show(user);

    if (this.isAllowed()) {
      this.list.refresh();
    }
  }

  oncreate(vnode: Mithril.VnodeDOM<any, this>) {
    // 手机端瀑布流：滚动到底自动加载下一页，替代被底部导航遮挡的加载按钮
    this.unbindScroll = bindInfiniteScroll(this.list);
  }

  onremove(vnode: Mithril.VnodeDOM<any, this>) {
    if (this.unbindScroll) {
      this.unbindScroll();
      this.unbindScroll = null;
    }
  }

  content(): Mithril.Children {
    if (this.user && !this.isAllowed()) {
      return (
        <div className="StoreUserPage-content StoreBackpackPage-content">
          <div style="font-size:1.4em;color: var(--muted-more-color);text-align: center;line-height: 100px;">
            {app.translator.trans('mattoid-store.forum.error.permission-denied')}
          </div>
        </div>
      );
    }

    return (
      <div className="StoreUserPage-content StoreBackpackPage-content">
        <h2 class="BadgeOverviewTitle">{app.translator.trans('mattoid-store.forum.backpack')}</h2>
        <div className="Store-Body Backpack-Body">
          {this.list.items.map((item: StoreApiResource) => {
            return (
              <div className="storeItemContainer backpackItemContainer" key={item.id}>
                {BackpackItem.component({ item: item, user: this.user, onChanged: () => this.list.refresh() })}
              </div>
            );
          })}

          {!this.list.loading && this.list.items.length === 0 && (
            <div>
              <div style="font-size:1.4em;color: var(--muted-more-color);text-align: center;line-height: 100px;">
                {app.translator.trans('mattoid-store.lib.backpack-empty')}
              </div>
            </div>
          )}

          {/* 「加载更多」按钮与手机端瀑布流（滚动到底自动加载，见 oncreate）并存 */}
          {this.list.hasMore() && (
            <div style="text-align:center;padding:20px">
              <Button
                className={'Button Button--primary'}
                disabled={this.list.loading}
                loading={this.list.loading}
                onclick={() => this.list.loadMore()}
              >
                {app.translator.trans('mattoid-store.lib.list-load-more')}
              </Button>
            </div>
          )}

          {this.list.loading && <LoadingBlock />}
        </div>
      </div>
    );
  }
}
