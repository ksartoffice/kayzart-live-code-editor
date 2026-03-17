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
  tailwindEnabled: boolean;
  importedGeneratedCss: string;
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
    shadowDomEnabled: payload.shadowDomEnabled ?? false,
    shortcodeEnabled: payload.shortcodeEnabled ?? baseSettings.shortcodeEnabled ?? false,
    singlePageEnabled: payload.singlePageEnabled ?? baseSettings.singlePageEnabled ?? true,
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
  const defaultTailwindEnabled = Boolean(setupResult?.tailwindEnabled ?? cfg.tailwindEnabled);

  if (!imported) {
    return {
      initialHtml: cfg.initialHtml ?? '',
      initialCss: cfg.initialCss ?? '',
      initialJs: cfg.initialJs ?? '',
      initialJsMode: normalizeJsMode(cfg.initialJsMode),
      tailwindEnabled: defaultTailwindEnabled,
      importedGeneratedCss: '',
      settingsData: cfg.settingsData,
    };
  }

  const payload = imported.payload;
  return {
    initialHtml: payload.html,
    initialCss: payload.css,
    initialJs: payload.js ?? '',
    initialJsMode: normalizeJsMode(payload.jsMode),
    tailwindEnabled: payload.tailwindEnabled,
    importedGeneratedCss: payload.generatedCss || '',
    settingsData:
      imported.settingsData ||
      buildImportedSettings(cfg.settingsData, payload, initialViewUrl),
  };
}
