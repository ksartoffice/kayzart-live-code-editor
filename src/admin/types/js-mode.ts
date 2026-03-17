export type JsMode = 'classic' | 'module';

export const JS_MODE_VALUES: JsMode[] = ['classic', 'module'];

export function normalizeJsMode(value: unknown): JsMode {
  if (typeof value !== 'string') {
    return 'classic';
  }
  const normalized = value.trim().toLowerCase();
  if (normalized === 'module') {
    return 'module';
  }
  if (normalized === 'classic' || normalized === 'auto') {
    return 'classic';
  }
  return 'classic';
}
