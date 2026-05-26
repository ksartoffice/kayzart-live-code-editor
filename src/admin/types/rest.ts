import type { SettingsData } from '../settings';

export type SaveResponse = {
  ok?: boolean;
  error?: string;
  customHead?: string;
  customHeadRemovedTags?: string[];
  settings?: SettingsData;
};

export type SetupResponse = {
  ok?: boolean;
  error?: string;
  tailwindEnabled?: boolean;
};

export type CompileTailwindResponse = {
  ok?: boolean;
  error?: string;
  css?: string;
};
