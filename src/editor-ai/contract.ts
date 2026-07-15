import type {
  EditorMode,
  EditorSnapshot as HostEditorSnapshot,
  KayzArtExtensionApi,
  SelectedElementContext,
} from '../admin/extensions/settings-tab-registry';

export type { EditorMode, KayzArtExtensionApi, SelectedElementContext };

export type EditorSnapshot = Omit<HostEditorSnapshot, 'customHead'> & {
  customHead: string;
};

export type ChangedTarget = 'html' | 'head' | 'css' | 'js';

export type AiAvailability = {
  available: boolean;
  featureEnabled: boolean;
  sdkPresent: boolean;
  providerConfigured: boolean;
  schedulerPresent: boolean;
  canEdit: boolean;
  jobsUrl: string;
  jobsBaseUrl: string;
  connectorsUrl: string;
  canManageConnectors: boolean;
};

export type AiJobStatus =
  | 'pending'
  | 'running'
  | 'completed'
  | 'error'
  | 'canceled'
  | 'timed_out'
  | 'enqueue_failed';

export type AiUsage = {
  inputTokens: number;
  cachedInputTokens: number;
  outputTokens: number;
  reasoningOutputTokens: number;
};

export type AiJobEvent = {
  event: 'progress' | 'tool_start' | 'tool_end' | 'final' | 'error' | 'canceled' | 'timed_out';
  requestId: string;
  message?: string;
  toolName?: string;
  inputSummary?: string;
  outputSummary?: string;
  summary?: string;
  snapshot?: EditorSnapshot;
  retryable?: boolean;
};

export type AiCreateJobResponse = {
  ok: boolean;
  jobId: string;
  requestId: string;
  status: AiJobStatus;
  statusUrl: string;
  cancelUrl: string;
  pollIntervalMs: number;
  timeoutMs: number;
};

export type AiJobStatusResponse = {
  ok: boolean;
  jobId: string;
  requestId: string;
  status: AiJobStatus;
  events: AiJobEvent[];
  snapshot: EditorSnapshot | null;
  error: { message: string; retryable?: boolean } | null;
  usage: AiUsage | null;
  cancelRequested: boolean;
  createdAt: string;
  updatedAt: string;
  startedAt: string | null;
  finishedAt: string | null;
  pollIntervalMs: number;
  timeoutMs: number;
};

export type AiEditRequest = EditorSnapshot & {
  requestId: string;
  post_id: number;
  editorMode: EditorMode;
  prompt: string;
  selectedContexts?: SelectedElementContext[];
};

export type ActiveJobRecord = {
  version: 1;
  postId: number;
  jobId: string;
  requestId: string;
  statusUrl: string;
  cancelUrl: string;
  pollIntervalMs: number;
  timeoutMs: number;
  startedAt: number;
  prompt: string;
  contexts: SelectedElementContext[];
  inputSnapshot: EditorSnapshot;
};

export function normalizeSnapshot(snapshot: HostEditorSnapshot): EditorSnapshot {
  return {
    html: snapshot.html,
    customHead: snapshot.customHead ?? '',
    css: snapshot.css,
    js: snapshot.js,
    jsMode: snapshot.jsMode === 'module' ? 'module' : 'classic',
    baseHash: snapshot.baseHash,
  };
}
