type MediaKind = 'image' | 'video' | 'file';

type MediaPropsResolver = (
  display: Record<string, unknown>,
  attachment: Record<string, unknown>
) => unknown;

const readMediaString = (value: unknown): string => (typeof value === 'string' ? value.trim() : '');

const readMediaTitle = (value: unknown): string => {
  if (typeof value === 'string') {
    return value.trim();
  }
  if (value && typeof value === 'object') {
    const raw = readMediaString((value as Record<string, unknown>).raw);
    if (raw) {
      return raw;
    }
    return readMediaString((value as Record<string, unknown>).rendered);
  }
  return '';
};

const escapeHtmlAttribute = (value: string): string =>
  value
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

const escapeHtmlText = (value: string): string =>
  value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const readMediaNumber = (value: unknown): number | null => {
  const next = typeof value === 'number' ? value : Number(value);
  if (!Number.isFinite(next) || next <= 0) {
    return null;
  }
  return Math.round(next);
};

const resolveMediaKind = (attachment: Record<string, unknown>): MediaKind => {
  const type = readMediaString(attachment.type).toLowerCase();
  const mime = readMediaString(attachment.mime).toLowerCase();
  if (type === 'image' || mime.startsWith('image/')) {
    return 'image';
  }
  if (type === 'video' || mime.startsWith('video/')) {
    return 'video';
  }
  return 'file';
};

const resolveMediaProps = (
  attachment: Record<string, unknown>,
  display?: Record<string, unknown>,
  propsResolver?: MediaPropsResolver
): Record<string, unknown> => {
  const input = display ? { ...display } : {};
  if (typeof propsResolver !== 'function') {
    return input;
  }
  try {
    const resolved = propsResolver(input, attachment);
    if (resolved && typeof resolved === 'object') {
      return resolved as Record<string, unknown>;
    }
  } catch {
    // Fall back to raw display values.
  }
  return input;
};

const resolveMediaLinkUrl = (
  attachment: Record<string, unknown>,
  props: Record<string, unknown>
): string => {
  const linkUrl = readMediaString(props.linkUrl);
  if (linkUrl) {
    return linkUrl;
  }
  const link = readMediaString(props.link);
  if (link === 'none') {
    return '';
  }
  if (link === 'post') {
    return readMediaString(attachment.link);
  }
  if (link === 'file' || link === 'embed') {
    return readMediaString(attachment.url);
  }
  return '';
};

const resolveImageUrl = (
  attachment: Record<string, unknown>,
  props: Record<string, unknown>
): string => {
  const src = readMediaString(props.src);
  if (src) {
    return src;
  }

  const sizeKey = readMediaString(props.size);
  const sizes = attachment.sizes;
  if (sizeKey && sizes && typeof sizes === 'object') {
    const selected = (sizes as Record<string, unknown>)[sizeKey];
    if (selected && typeof selected === 'object') {
      const selectedUrl = readMediaString((selected as Record<string, unknown>).url);
      if (selectedUrl) {
        return selectedUrl;
      }
    }
  }

  return readMediaString(attachment.url);
};

const buildImageHtml = (
  attachment: Record<string, unknown>,
  props: Record<string, unknown>
): string => {
  const src = resolveImageUrl(attachment, props);
  if (!src) {
    return '';
  }

  const alt = readMediaString(props.alt) || readMediaString(attachment.alt);
  const width = readMediaNumber(props.width);
  const height = readMediaNumber(props.height);
  const widthAttr = width ? ` width="${width}"` : '';
  const heightAttr = height ? ` height="${height}"` : '';
  const imageHtml = `<img src="${escapeHtmlAttribute(src)}" alt="${escapeHtmlAttribute(alt)}"${widthAttr}${heightAttr}>`;
  const linkUrl = resolveMediaLinkUrl(attachment, props);
  if (!linkUrl) {
    return imageHtml;
  }
  return `<a href="${escapeHtmlAttribute(linkUrl)}">${imageHtml}</a>`;
};

export function buildMediaHtml(
  attachment: Record<string, unknown>,
  display?: Record<string, unknown>,
  propsResolver?: MediaPropsResolver
): string {
  const mediaProps = resolveMediaProps(attachment, display, propsResolver);
  const mediaKind = resolveMediaKind(attachment);
  if (mediaKind === 'image') {
    return buildImageHtml(attachment, mediaProps);
  }

  const mediaUrl = readMediaString(attachment.url);
  if (!mediaUrl) {
    return '';
  }
  const url = escapeHtmlAttribute(mediaUrl);
  if (mediaKind === 'video') {
    return `<video controls src="${url}"></video>`;
  }

  const linkUrl = resolveMediaLinkUrl(attachment, mediaProps) || mediaUrl;
  const title = readMediaTitle(mediaProps.title) || readMediaTitle(attachment.title);
  const filename = readMediaString(attachment.filename);
  const label = title || filename || linkUrl;
  const href = escapeHtmlAttribute(linkUrl);
  return `<a href="${href}">${escapeHtmlText(label)}</a>`;
}

export function buildMediaUrl(
  attachment: Record<string, unknown>,
  display?: Record<string, unknown>,
  propsResolver?: MediaPropsResolver
): string {
  const mediaProps = resolveMediaProps(attachment, display, propsResolver);
  const mediaKind = resolveMediaKind(attachment);
  const mediaUrl =
    mediaKind === 'image'
      ? resolveImageUrl(attachment, mediaProps)
      : readMediaString(attachment.url);
  return mediaUrl ? escapeHtmlAttribute(mediaUrl) : '';
}
