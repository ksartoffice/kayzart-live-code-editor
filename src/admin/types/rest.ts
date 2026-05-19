import type { SettingsData } from '../settings';

export type SaveResponse = {
  ok?: boolean;
  error?: string;
  settings?: SettingsData;
};
