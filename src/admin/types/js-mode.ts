export type JsMode = 'auto' | 'classic' | 'module';

export const JS_MODE_VALUES: JsMode[] = ['auto', 'classic', 'module'];

export function normalizeJsMode(value: unknown): JsMode {
  return typeof value === 'string' && JS_MODE_VALUES.includes(value as JsMode)
    ? (value as JsMode)
    : 'auto';
}
