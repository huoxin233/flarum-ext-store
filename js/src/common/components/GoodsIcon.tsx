import app from 'flarum/common/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { isFontAwesomeIcon, parseFontAwesomeIcon, normalizeIconClass, isVideoIcon, isImageIcon } from '../icon';

export interface GoodsIconExtIcon {
  name?: string;
  color?: string;
  backgroundColor?: string;
  backgroundImage?: string;
}

export interface GoodsIconAttrs extends ComponentAttrs {
  /** 图标字符串：图片 URL、WebM 视频 URL、Font Awesome 类名或 CSS 样式类 */
  icon?: string | null;
  /** 插件所属扩展的图标回退对象 */
  extIcon?: GoodsIconExtIcon | null;
  /** 商品标题（用于 img 的 alt / 辅助文本） */
  title?: string;
  /** 兜底 Font Awesome 图标（默认 'fas fa-box'） */
  fallback?: string;
  /** 自定义外层 class */
  className?: string;
  /** 是否为后台预览模式（CSS 样式类显示详细标注） */
  isPreview?: boolean;
}

/**
 * 统一商品图标渲染组件（GoodsIcon）
 *
 * 遵循 Flarum 核心组件规范（如 <Avatar />, <ExtensionIcon />）。
 * 统一处理图片、WebM动图、Font Awesome 图标、CSS 样式类名以及扩展回退图标：
 * 1. Font Awesome 图标（如 fas fa-gift, fa-solid fa-gem, fa-gift） -> <i class="GoodsIcon-fa ..." aria-hidden="true">
 * 2. 视频动图（.webm / .mp4） -> <video class="GoodsIcon-video" ...>
 * 3. 静态图片（URL / 资源路径） -> <img class="GoodsIcon-img" ...>（通过 extractText 防御 [object Object]）
 * 4. 装饰类名（如装扮插件的用户名颜色样式名） -> <span class="GoodsIcon-class ...">
 * 5. 扩展所属图标（extIcon） -> 带背景色的 <i class="extIcon.name" aria-hidden="true">
 * 6. 最终兜底 -> <i class="GoodsIcon-fallback fas fa-box" aria-hidden="true">
 */
export default class GoodsIcon extends Component<GoodsIconAttrs> {
  view(vnode?: Mithril.Vnode<GoodsIconAttrs, this>): Mithril.Children {
    const attrs = (vnode && vnode.attrs) || this.attrs || {};
    const { icon, extIcon, title, fallback, className, isPreview } = attrs;
    const cleanIcon = typeof icon === 'string' ? icon.trim() : '';
    const fallbackIcon = fallback || 'fas fa-box';

    let content: Mithril.Children;

    if (cleanIcon !== '') {
      const parsedFA = parseFontAwesomeIcon(cleanIcon);
      if (parsedFA) {
        if (parsedFA.backgroundColor) {
          content = (
            <span
              className="GoodsIcon-badge"
              style={{
                backgroundColor: parsedFA.backgroundColor,
                color: parsedFA.color || '#ffffff',
              }}
            >
              <i className={parsedFA.name} aria-hidden="true" />
            </span>
          );
        } else {
          content = (
            <i
              className={`GoodsIcon-fa ${parsedFA.name}`}
              style={parsedFA.color ? { color: parsedFA.color } : undefined}
              aria-hidden="true"
            />
          );
        }
      } else if (isVideoIcon(cleanIcon)) {
        content = (
          <video className="GoodsIcon-video" autoplay loop muted playsinline>
            <source src={cleanIcon} type="video/webm" />
          </video>
        );
      } else if (isImageIcon(cleanIcon)) {
        content = <img className="GoodsIcon-img" src={cleanIcon} alt={extractText(title) || ''} />;
      } else {
        // CSS 样式类名（如装扮插件的用户名颜色样式名）
        content = (
          <span className={`GoodsIcon-class ${cleanIcon}`}>
            {isPreview
              ? app.translator.trans('mattoid-store.admin.settings.goods-icon-effect-preview')
              : app.translator.trans('mattoid-store.lib.class-icon-preview')}
          </span>
        );
      }
    } else if (extIcon && (extIcon.name || extIcon.backgroundImage)) {
      content = (
        <span
          className="GoodsIcon-extIcon"
          style={{
            backgroundColor: extIcon.backgroundColor,
            color: extIcon.color,
            backgroundImage: extIcon.backgroundImage,
          }}
        >
          {extIcon.name ? <i className={extIcon.name} aria-hidden="true" /> : ''}
        </span>
      );
    } else {
      content = <i className={`GoodsIcon-fallback ${fallbackIcon}`} aria-hidden="true" />;
    }

    return (
      <div className={`GoodsIcon ${className || ''} ${isPreview ? 'GoodsIcon--preview' : ''}`}>
        {content}
        {isPreview && cleanIcon !== '' && !isImageIcon(cleanIcon) && !isVideoIcon(cleanIcon) ? (
          <span className="GoodsIcon-caption">{cleanIcon}</span>
        ) : null}
      </div>
    );
  }
}
