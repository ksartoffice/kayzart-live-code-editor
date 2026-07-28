import type { AppConfig } from '../types/app-config';
import type { SettingsData } from '../settings';
import type { JsMode } from '../types/js-mode';
import { normalizeJsMode } from '../types/js-mode';
import {
  normalizeEditorCssMode,
  type CssByMode,
  type EditorCssMode,
} from '../types/css-mode';
import { createInitialTailwindSource } from '../logic/css-mode';

export type ResolvedInitialState = {
  initialHtml: string;
  initialCustomHead: string;
  initialCss: string;
  initialCssByMode: CssByMode;
  initialEditorMode: EditorCssMode;
  initialJs: string;
  initialJsMode: JsMode;
  tailwindEnabled: boolean;
  settingsData: SettingsData;
};

export function resolveInitialState(cfg: AppConfig, tailwindEnabled?: boolean): ResolvedInitialState {
  const initialEditorMode = normalizeEditorCssMode(
    tailwindEnabled === undefined
      ? (cfg.initialEditorMode ?? (cfg.tailwindEnabled ? 'tailwind' : 'normal'))
      : tailwindEnabled
        ? 'tailwind'
        : 'normal'
  );
  const configuredCssByMode = cfg.initialCssByMode;
  const initialCss = cfg.initialCss ?? '';
  const hasConfiguredNormal = Boolean(
    configuredCssByMode && Object.prototype.hasOwnProperty.call(configuredCssByMode, 'normal')
  );
  const hasConfiguredTailwind = Boolean(
    configuredCssByMode && Object.prototype.hasOwnProperty.call(configuredCssByMode, 'tailwind')
  );
  const initialCssByMode: CssByMode = {
    normal: hasConfiguredNormal
      ? typeof configuredCssByMode?.normal === 'string'
        ? configuredCssByMode.normal
        : null
      : initialEditorMode === 'normal'
        ? initialCss
        : null,
    tailwind: hasConfiguredTailwind
      ? typeof configuredCssByMode?.tailwind === 'string'
        ? configuredCssByMode.tailwind
        : null
      : initialEditorMode === 'tailwind'
        ? initialCss
        : null,
  };
  let resolvedInitialCss = initialCssByMode[initialEditorMode];
  if (resolvedInitialCss === null) {
    resolvedInitialCss =
      initialEditorMode === 'tailwind'
        ? createInitialTailwindSource(initialCssByMode.normal ?? initialCss)
        : initialCssByMode.tailwind ?? initialCss;
    initialCssByMode[initialEditorMode] = resolvedInitialCss;
  }
  return {
    initialHtml: cfg.initialHtml ?? '',
    initialCustomHead: cfg.initialCustomHead ?? '',
    initialCss: resolvedInitialCss,
    initialCssByMode,
    initialEditorMode,
    initialJs: cfg.initialJs ?? '',
    initialJsMode: normalizeJsMode(cfg.initialJsMode),
    tailwindEnabled: initialEditorMode === 'tailwind',
    settingsData: cfg.settingsData,
  };
}
