import type { SettingsData } from './settings';
import type { JsMode } from './types/js-mode';

export type ImportPayload = {
  version: number;
  html: string;
  css: string;
  tailwindEnabled: boolean;
  generatedCss?: string;
  js?: string;
  jsMode?: JsMode;
  externalScripts?: string[];
  externalStyles?: string[];
  shadowDomEnabled?: boolean;
  shortcodeEnabled?: boolean;
  singlePageEnabled?: boolean;
  liveHighlightEnabled?: boolean;
};

export type ImportResult = {
  payload: ImportPayload;
  settingsData?: SettingsData;
};

export type ExportPayload = {
  version: 1;
  html: string;
  css: string;
  tailwindEnabled: boolean;
  generatedCss: string;
  js: string;
  jsMode: JsMode;
  externalScripts: string[];
  externalStyles: string[];
  shadowDomEnabled: boolean;
  shortcodeEnabled: boolean;
  singlePageEnabled: boolean;
  liveHighlightEnabled: boolean;
};
