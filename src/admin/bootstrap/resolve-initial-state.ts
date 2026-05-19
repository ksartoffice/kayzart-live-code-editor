import type { AppConfig } from '../types/app-config';
import type { SettingsData } from '../settings';
import type { JsMode } from '../types/js-mode';
import { normalizeJsMode } from '../types/js-mode';

export type ResolvedInitialState = {
  initialHtml: string;
  initialCss: string;
  initialJs: string;
  initialJsMode: JsMode;
  settingsData: SettingsData;
};

export function resolveInitialState(cfg: AppConfig): ResolvedInitialState {
  return {
    initialHtml: cfg.initialHtml ?? '',
    initialCss: cfg.initialCss ?? '',
    initialJs: cfg.initialJs ?? '',
    initialJsMode: normalizeJsMode(cfg.initialJsMode),
    settingsData: cfg.settingsData,
  };
}
