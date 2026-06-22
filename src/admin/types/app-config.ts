import type { SettingsData } from '../settings';
import type { JsMode } from './js-mode';

export type AppConfig = {
  post_id: number;
  initialHtml: string;
  initialCustomHead?: string;
  initialCss: string;
  initialJs: string;
  initialJsMode?: JsMode;
  canEditJs: boolean;
  previewUrl: string;
  iframePreviewUrl?: string;
  restUrl: string;
  restCompileUrl: string;
  setupRestUrl: string;
  importRestUrl?: string;
  settingsRestUrl: string;
  settingsData: SettingsData;
  backUrl?: string;
  listUrl?: string;
  listLabel?: string;
  tailwindEnabled?: boolean;
  setupRequired?: boolean;
  restNonce: string;
  adminTitleSeparators?: string[];
};
