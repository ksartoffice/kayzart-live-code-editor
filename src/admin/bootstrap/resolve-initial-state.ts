import type { AppConfig } from '../types/app-config';
import type { SettingsData } from '../settings';
import type { JsMode } from '../types/js-mode';
import { normalizeJsMode } from '../types/js-mode';

export type ResolvedInitialState = {
  initialHtml: string;
  initialCustomHead: string;
  initialCss: string;
  initialJs: string;
  initialJsMode: JsMode;
  tailwindEnabled: boolean;
  settingsData: SettingsData;
};

export function resolveInitialState(cfg: AppConfig, tailwindEnabled?: boolean): ResolvedInitialState {
  return {
    initialHtml: cfg.initialHtml ?? '',
    initialCustomHead: cfg.initialCustomHead ?? '',
    initialCss: cfg.initialCss ?? '',
    initialJs: cfg.initialJs ?? '',
    initialJsMode: normalizeJsMode(cfg.initialJsMode),
    tailwindEnabled: Boolean(tailwindEnabled ?? cfg.tailwindEnabled),
    settingsData: cfg.settingsData,
  };
}
