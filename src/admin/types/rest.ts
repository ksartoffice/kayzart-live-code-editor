import type { SettingsData } from '../settings';

export type SaveResponse = {
  ok?: boolean;
  error?: string;
  customHead?: string;
  customHeadRemovedTags?: string[];
  settings?: SettingsData;
  revisionsSupported?: boolean;
  revisionsEnabled?: boolean;
  revision?: RevisionSummary | null;
};

export type RevisionSection = 'html' | 'css' | 'javascript' | 'customHead';

export type RevisionSummary = {
  id: number;
  date: string;
  dateGmt: string;
  author: { id: number; name: string };
  changedSections: RevisionSection[];
  isFirst: boolean;
};

export type RevisionListResponse = {
  ok: boolean;
  supported: boolean;
  minVersion: string;
  currentVersion: string;
  revisionsEnabled: boolean;
  canLoad: boolean;
  revisions: RevisionSummary[];
  pagination: { page: number; perPage: number; total: number; totalPages: number };
};

export type RevisionDetailResponse = {
  ok: boolean;
  revision?: RevisionSummary & {
    snapshot: {
      html: string;
      customHead: string;
      css: string;
      js: string;
      jsMode: 'classic' | 'module';
      baseHash: string;
    };
  };
  error?: string;
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
