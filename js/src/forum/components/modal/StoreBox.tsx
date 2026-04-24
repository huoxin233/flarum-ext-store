import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Stream from 'flarum/common/utils/Stream';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Select from 'flarum/common/components/Select';
import TextEditor from 'flarum/common/components/TextEditor';
import app from 'flarum/forum/app';
import type Mithril from 'mithril';

interface StoreBoxAttrs extends IInternalModalAttrs {
  storeData?: StoreItemData;
}

interface StoreBoxField {
  prop: 'input' | 'switch' | 'select' | 'textarea';
  label: string;
  helpText: string;
  value: string;
  type?: string;
  options?: Record<string, string>;
}

export default class StoreBox extends Modal<StoreBoxAttrs> {
  private storeData: StoreItemData = {} as StoreItemData;
  private params: Record<string, any> = {};
  private range: boolean = false;

  loading: boolean = false;

  static initAttrs(attrs: StoreBoxAttrs) {
    super.initAttrs(attrs);
  }

  oninit(vnode: Mithril.Vnode<StoreBoxAttrs, this>) {
    super.oninit(vnode);

    this.storeData = this.attrs.storeData || ({} as StoreItemData);
    this.params.id = this.storeData.id;
  }

  title() {
    return this.storeData.title;
  }

  className(): string {
    return this.storeData.className || '';
  }

  content() {
    return m('.Modal-body', [
      this.getHtml(JSON.parse(this.storeData.popUp || '[]')),
      m('.Form-group .center', [
        Button.component(
          {
            type: 'submit',
            className: 'Button Button--primary',
            loading: this.loading,
            // disabled: parseFloat(this.amount || '0') <= 0,
          },
          app.translator.trans('mattoid-store.forum.button')
        ),
      ]),
    ]);
  }

  getHtml(popUp: StoreBoxField[]) {
    return popUp.map((item: StoreBoxField) => {
      return this.getInput(item);
    });
  }

  getInput(column: StoreBoxField) {
    let input;

    switch (column.prop) {
      case 'input':
        input = m('.Form-group', [
          m('label', app.translator.trans(column.label)),
          m('.helpText', app.translator.trans(column.helpText)),
          m('input.FormControl', {
            type: column.type || 'text',
            value: this.params[column.value],
            onchange: (event: InputEvent) => {
              this.params[column.value] = (event.target as HTMLInputElement).value;
            },
            min: 0,
            step: 0.1,
            disabled: this.loading,
          }),
        ]);
        break;
      case 'switch':
        input = m('.Form-group', [
          Switch.component(
            {
              state: this.range,
              onchange: (value: boolean) => {
                this.range = value;
                this.params[column.value] = value;
              },
              disabled: this.loading,
            },
            app.translator.trans(column.label)
          ),
          m('.helpText', app.translator.trans(column.helpText)),
        ]);
        break;
      case 'select':
        input = m('.Form-group', [
          m('label', app.translator.trans(column.label)),
          m('.helpText', app.translator.trans(column.helpText)),
          Select.component({
            value: this.params[column.value],
            disabled: this.loading,
            options: column.options,
            buttonClassName: 'Button',
            onchange: (val: string) => {
              this.params[column.value] = val;
            },
          }),
        ]);
        break;
      case 'textarea':
        input = m('.Form-group', [
          m('label', app.translator.trans(column.label)),
          m('.helpText', app.translator.trans(column.helpText)),
          m('textarea.FormControl', {
            value: this.params[column.value],
            onchange: (event: InputEvent) => {
              this.params[column.value] = (event.target as HTMLInputElement).value;
            },
          }),
        ]);
        break;
    }

    return input;
  }

  onsubmit(event: Event) {
    event.preventDefault();
    this.loading = true;

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/store/buy/goods',
        body: this.params,
      })
      .then(
        () => location.reload(),
        () => {
          this.loading = false;
        }
      );
  }
}
