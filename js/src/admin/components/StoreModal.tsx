import app from 'flarum/admin/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';

interface StoreModalAttrs extends IInternalModalAttrs {
  title?: string;
  storeData?: StoreItemData;
}

export default class StoreModal extends Modal<StoreModalAttrs> {
  private type: string = '';
  private storeData: StoreItemData = {} as StoreItemData;

  loading: boolean = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.type = this.attrs.title ?? '';
    this.storeData = this.attrs.storeData as StoreItemData;
  }

  className() {
    return 'Modal--small';
  }

  title() {
    return app.translator.trans('mattoid-store.admin.settings.goods-item-' + this.type);
  }

  content() {
    //
    return (
      <div className="Modal-body">
        <div className="Form-group" style="text-align: center;">
          {Button.component(
            {
              className: 'Button Button--primary',
              type: 'submit',
              loading: this.loading,
            },
            app.translator.trans('mattoid-store.lib.confirm')
          )}
          &nbsp;
          {Button.component(
            {
              className: 'Button storeButton--gray',
              loading: this.loading,
              onclick: () => {
                this.hide();
              },
            },
            app.translator.trans('mattoid-store.lib.cancel')
          )}
        </div>
      </div>
    );
  }

  onsubmit(e: Event) {
    e.preventDefault();

    this.loading = true;

    const status = this.storeData.status;
    this.storeData.status = Number(!this.storeData.status);

    const method = this.type === 'delete' ? 'DELETE' : 'PUT';
    app
      .request({
        method: method,
        url: app.forum.attribute('apiUrl') + '/store/goods',
        body: this.storeData,
      })
      .then(
        () => location.reload(),
        () => {
          this.loading = false;
          this.storeData.status = status;
        }
      );
  }
}
