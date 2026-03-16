import type { SettingsData } from '../settings';

export type AppConfig = {
  post_id: number;
  initialHtml: string;
  initialCss: string;
  initialJs: string;
  canEditJs: boolean;
  previewUrl: string;
  iframePreviewUrl?: string;
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
