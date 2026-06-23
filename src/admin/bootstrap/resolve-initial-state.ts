import type { AppConfig } from '../types/app-config';
import type { SettingsData } from '../settings';
import type { JsMode } from '../types/js-mode';
import { normalizeJsMode } from '../types/js-mode';
import type { SetupWizardResult } from '../setup-wizard';

export type ResolvedInitialState = {
  initialHtml: string;
  initialCustomHead: string;
  initialCss: string;
  initialJs: string;
  initialJsMode: JsMode;
  tailwindEnabled: boolean;
  settingsData: SettingsData;
};

export function resolveInitialState(
  cfg: AppConfig,
  setupResult?: boolean | SetupWizardResult
): ResolvedInitialState {
  const tailwindEnabled = typeof setupResult === 'object'
    ? setupResult.tailwindEnabled
    : setupResult;
  const setupHtml = typeof setupResult === 'object' ? setupResult.initialHtml : undefined;
  const setupCss = typeof setupResult === 'object' ? setupResult.initialCss : undefined;

  return {
    initialHtml: setupHtml ?? cfg.initialHtml ?? '',
    initialCustomHead: cfg.initialCustomHead ?? '',
    initialCss: setupCss ?? cfg.initialCss ?? '',
    initialJs: cfg.initialJs ?? '',
    initialJsMode: normalizeJsMode(cfg.initialJsMode),
    tailwindEnabled: Boolean(tailwindEnabled ?? cfg.tailwindEnabled),
    settingsData: cfg.settingsData,
  };
}
