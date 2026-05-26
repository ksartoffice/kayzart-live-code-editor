export type ExternalResourceAttrs = Record<string, string | boolean>;

export type ExternalResourceInput =
  | string
  | {
      url?: unknown;
      attrs?: unknown;
    };

export type ExternalResource = {
  url: string;
  attrs: ExternalResourceAttrs;
};

const normalizeAttrValue = (value: unknown): string | boolean | null => {
  if (typeof value === 'boolean') {
    return value;
  }
  if (typeof value !== 'string') {
    return null;
  }
  const trimmed = value.trim();
  return trimmed ? trimmed : null;
};

export const normalizeExternalResource = (entry: ExternalResourceInput): ExternalResource | null => {
  if (typeof entry === 'string') {
    const url = entry.trim();
    return url ? { url, attrs: {} } : null;
  }
  if (!entry || typeof entry !== 'object' || typeof entry.url !== 'string') {
    return null;
  }
  const url = entry.url.trim();
  if (!url) {
    return null;
  }
  const attrs: ExternalResourceAttrs = {};
  if (entry.attrs && typeof entry.attrs === 'object' && !Array.isArray(entry.attrs)) {
    Object.entries(entry.attrs as Record<string, unknown>).forEach(([rawKey, rawValue]) => {
      const key = rawKey.trim().toLowerCase();
      const value = normalizeAttrValue(rawValue);
      if (key && value !== null) {
        attrs[key] = value;
      }
    });
  }
  return { url, attrs };
};

export const normalizeExternalResources = (
  list: ExternalResourceInput[] | undefined | null
): ExternalResource[] => {
  const seen = new Set<string>();
  const normalized: ExternalResource[] = [];
  (Array.isArray(list) ? list : []).forEach((entry) => {
    const resource = normalizeExternalResource(entry);
    if (!resource || seen.has(resource.url)) {
      return;
    }
    seen.add(resource.url);
    normalized.push(resource);
  });
  return normalized;
};

export const serializeExternalResources = (list: ExternalResource[]): ExternalResource[] =>
  normalizeExternalResources(list).map((resource) => ({
    url: resource.url,
    attrs: { ...resource.attrs },
  }));

export const isHttpsExternalResource = (resource: ExternalResource): boolean => {
  try {
    return new URL(resource.url).protocol === 'https:';
  } catch {
    return false;
  }
};

export const areSameExternalResources = (
  left: ExternalResource[],
  right: ExternalResource[]
): boolean => JSON.stringify(serializeExternalResources(left)) === JSON.stringify(serializeExternalResources(right));
