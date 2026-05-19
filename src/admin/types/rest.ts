import type { SettingsData } from '../settings';

export type SetupResponse = {
  ok?: boolean;
  error?: string;
};

export type ImportResponse = {
  ok?: boolean;
  error?: string;
  html?: string;
  settingsData?: SettingsData;
  importWarnings?: string[];
  importedImages?: Array<{
    sourceUrl: string;
    attachmentId: number;
    attachmentUrl: string;
  }>;
};

export type SaveResponse = {
  ok?: boolean;
  error?: string;
  settings?: SettingsData;
};
