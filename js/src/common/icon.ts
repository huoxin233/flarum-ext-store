/**
 * 商品图标辅助函数：支持图片/WebM视频、Font Awesome 图标（含颜色与背景徽章）以及 CSS 装饰类名。
 */

export interface ParsedFontAwesomeIcon {
  /** 规范化类名（如 fas fa-gift） */
  name: string;
  /** 原始输入的类名部分（如 fa-gift 或 fas fa-gift） */
  rawName: string;
  /** 图标文字颜色（如 #f59e0b 或 rgb(...)） */
  color?: string;
  /** 徽章背景颜色（如 #8b5cf6 或 rgb(...)） */
  backgroundColor?: string;
}

const FA_REGEX =
  /^((?:fa[srbld]?|fa-(?:solid|regular|brands|light|duotone|thin))\s+fa-[\w-]+(?:\s+fa-[\w-]+)*|^fa-[\w-]+)(?:\s+(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\)|hsla?\([^)]+\)|[a-zA-Z]+))?(?:\s+(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\)|hsla?\([^)]+\)|[a-zA-Z]+))?$/;

/**
 * 解析 Font Awesome 图标字符串。
 * 支持以下格式：
 * - 纯图标：`fas fa-gift`、`fa-solid fa-gem`、简写 `fa-gift`
 * - 自定义图标颜色：`fas fa-gem #f59e0b`
 * - 自定义图标颜色与背景徽章颜色：`fas fa-gem #ffffff #8b5cf6`
 */
export function parseFontAwesomeIcon(icon?: string | null): ParsedFontAwesomeIcon | null {
  if (!icon || typeof icon !== 'string') return null;
  const trimmed = icon.trim();
  if (trimmed === '' || trimmed.indexOf('/') !== -1 || trimmed.indexOf('.') !== -1) {
    return null;
  }
  const match = trimmed.match(FA_REGEX);
  if (!match) return null;

  const rawName = match[1].trim();
  let normalizedName = rawName;
  if (/^fa-[\w-]+$/.test(rawName)) {
    normalizedName = `fas ${rawName}`;
  }

  return {
    rawName,
    name: normalizedName,
    color: match[2] ? match[2].trim() : undefined,
    backgroundColor: match[3] ? match[3].trim() : undefined,
  };
}

/**
 * 判断字符串是否为 Font Awesome 图标类名（支持带颜色后缀）。
 */
export function isFontAwesomeIcon(icon?: string | null): boolean {
  return parseFontAwesomeIcon(icon) !== null;
}

/**
 * 规范化 Font Awesome 图标类名。
 * 若管理员仅输入了简写 'fa-gift'，自动补全默认前缀 'fas fa-gift'。
 * 若带有颜色后缀（如 'fas fa-gem #f59e0b'），自动提取纯 class 类名部分。
 */
export function normalizeIconClass(icon?: string | null): string {
  if (!icon || typeof icon !== 'string') return '';
  const parsed = parseFontAwesomeIcon(icon);
  if (parsed) {
    return parsed.name;
  }
  const trimmed = icon.trim();
  if (/^fa-[\w-]+$/.test(trimmed)) {
    return `fas ${trimmed}`;
  }
  return trimmed;
}

/**
 * 根据图标名、图标颜色与徽章背景色构建规范的商品图标字符串。
 */
export function buildFontAwesomeIconString(name: string, color?: string, backgroundColor?: string): string {
  const cleanName = (name || '').trim();
  const cleanColor = (color || '').trim();
  const cleanBg = (backgroundColor || '').trim();

  if (!cleanColor && !cleanBg) {
    return cleanName;
  }
  if (cleanColor && !cleanBg) {
    return `${cleanName} ${cleanColor}`;
  }
  if (!cleanColor && cleanBg) {
    return `${cleanName} #ffffff ${cleanBg}`;
  }
  return `${cleanName} ${cleanColor} ${cleanBg}`;
}

/**
 * 判断是否为 WebM / MP4 等视频动图地址。
 * 兼容带 query 参数或 hash 的 URL（如 /anim.webm?v=1#t=0）。
 */
export function isVideoIcon(icon?: string | null): boolean {
  if (!icon || typeof icon !== 'string') return false;
  const trimmed = icon.trim().toLowerCase();
  const cleanPath = trimmed.split('?')[0].split('#')[0];
  return cleanPath.endsWith('.webm') || cleanPath.endsWith('.mp4');
}

/**
 * 判断是否为静态图片或网络 URL 地址（非视频且非图标/类名）。
 */
export function isImageIcon(icon?: string | null): boolean {
  if (!icon || typeof icon !== 'string') return false;
  const trimmed = icon.trim();
  if (trimmed === '' || isVideoIcon(trimmed)) return false;
  return trimmed.indexOf('/') !== -1 || trimmed.indexOf('.') !== -1;
}
