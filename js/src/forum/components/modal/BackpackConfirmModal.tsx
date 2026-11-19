import app from 'flarum/forum/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import GoodsIcon from '../../../common/components/GoodsIcon';

export interface IBackpackConfirmModalAttrs extends IInternalModalAttrs {
  title: Mithril.Children;
  itemTitle: string;
  message: Mithril.Children;
  icon?: string | null;
  danger?: boolean;
  onConfirm: () => Promise<any> | void;
}

/**
 * 背包不可逆操作二次确认弹窗（取消订阅 / 使用一次性消耗品）
 */
export default class BackpackConfirmModal extends Modal<IBackpackConfirmModalAttrs> {
  className(): string {
    return 'Modal--small StoreConfirmModal';
  }

  title(): Mithril.Children {
    return this.attrs.title;
  }

  content(): Mithril.Children {
    const isDanger = !!this.attrs.danger;
    return (
      <div className="Modal-body">
        <div className="StoreConfirmBody">
          <div className={'StoreConfirmIcon' + (isDanger ? ' StoreConfirmIcon--danger' : '')}>
            <GoodsIcon
              icon={this.attrs.icon}
              title={this.attrs.itemTitle}
              fallback={isDanger ? 'fas fa-ban' : 'fas fa-box-open'}
            />
          </div>
          <div className="StoreConfirmText">
            <div className="StoreConfirmTitle">{this.attrs.itemTitle}</div>
            <div className="StoreConfirmDesc">{this.attrs.message}</div>
          </div>
        </div>
        <div className="StoreConfirmActions">
          <Button
            className={'Button ' + (isDanger ? 'Button--danger' : 'Button--primary')}
            type="submit"
            loading={this.loading}
          >
            {app.translator.trans('mattoid-store.lib.confirm')}
          </Button>
          <Button
            className="Button storeButton--gray"
            disabled={this.loading}
            onclick={() => this.hide()}
          >
            {app.translator.trans('mattoid-store.lib.cancel')}
          </Button>
        </div>
      </div>
    );
  }

  onsubmit(e: Event) {
    e.preventDefault();
    if (this.loading) return;

    this.loading = true;
    m.redraw();

    Promise.resolve(this.attrs.onConfirm())
      .then(
        () => {
          this.loading = false;
          this.hide();
        },
        () => {
          this.loading = false;
          this.hide();
        }
      );
  }
}
