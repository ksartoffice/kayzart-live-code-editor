import type { AppConfig } from '../types/app-config';
import type { SettingsData } from '../settings';
import type { ImportPayload } from '../types';
import type { SetupWizardResult } from '../setup-wizard';
import type { JsMode } from '../types/js-mode';
import { normalizeJsMode } from '../types/js-mode';

export type ResolvedInitialState = {
  initialHtml: string;
  initialCss: string;
  initialJs: string;
  initialJsMode: JsMode;
  settingsData: SettingsData;
};

function buildImportedSettings(
  baseSettings: SettingsData,
  payload: ImportPayload,
  initialViewUrl: string
): SettingsData {
  const nextSettings: SettingsData = {
    ...baseSettings,
    slug: baseSettings.slug || '',
    externalScripts: payload.externalScripts ?? [],
    externalStyles: payload.externalStyles ?? [],
    externalScriptsMax: baseSettings.externalScriptsMax,
    externalStylesMax: baseSettings.externalStylesMax,
    liveHighlightEnabled: payload.liveHighlightEnabled ?? baseSettings.liveHighlightEnabled ?? true,
  };
  if (initialViewUrl && !nextSettings.viewUrl) {
    nextSettings.viewUrl = initialViewUrl;
  }
  return nextSettings;
}

export function resolveInitialState(
  cfg: AppConfig,
  setupResult?: SetupWizardResult
): ResolvedInitialState {
  const initialViewUrl = cfg.settingsData?.viewUrl || '';
  const imported = setupResult?.imported;

  if (!imported) {
    return {
      initialHtml: cfg.initialHtml ?? '',
      initialCss: cfg.initialCss ?? '',
      initialJs: cfg.initialJs ?? '',
      initialJsMode: normalizeJsMode(cfg.initialJsMode),
      settingsData: cfg.settingsData,
    };
  }

  const payload = imported.payload;
  return {
    initialHtml: payload.html,
    initialCss: payload.css,
    initialJs: payload.js ?? '',
    initialJsMode: normalizeJsMode(payload.jsMode),
    settingsData:
      imported.settingsData ||
      buildImportedSettings(cfg.settingsData, payload, initialViewUrl),
  };
}
