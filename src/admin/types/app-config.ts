import type { SettingsData } from '../settings';
import type { JsMode } from './js-mode';

export type AppConfig = {
  post_id: number;
  initialHtml: string;
  initialCss: string;
  initialJs: string;
  initialJsMode?: JsMode;
  canEditJs: boolean;
  previewUrl: string;
  iframePreviewUrl?: string;
  previewMessageToken: string;
  monacoVsPath: string;
  restUrl: string;
  restCompileUrl: string;
  setupRestUrl: string;
  importRestUrl: string;
  settingsRestUrl: string;
  settingsData: SettingsData;
  backUrl?: string;
  listUrl?: string;
  tailwindEnabled?: boolean;
  setupRequired?: boolean;
  restNonce: string;
  adminTitleSeparators?: string[];
};
