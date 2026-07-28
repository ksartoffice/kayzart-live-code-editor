export type EditorCssMode = 'normal' | 'tailwind';

export type CssByMode = {
  normal: string | null;
  tailwind: string | null;
};

export const normalizeEditorCssMode = (value: unknown): EditorCssMode =>
  value === 'tailwind' ? 'tailwind' : 'normal';

export const cloneCssByMode = (value: CssByMode): CssByMode => ({
  normal: value.normal,
  tailwind: value.tailwind,
});
