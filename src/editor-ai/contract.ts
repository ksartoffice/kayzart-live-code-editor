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
export type ChangeStat = { added: number; removed: number };

export type AiAvailability = {
  available: boolean;
  availabilityUrl?: string;
  setupMode?: 'direct' | 'connectors';
  setupUrl?: string;
  setupLabel?: string;
  canSetUp?: boolean;
  unavailableMessage?: string;
  setupGuide?: {
    summary: string;
    steps: string[];
    links: { label: string; url: string }[];
  };
	setupState?: 'ready' | 'setup_required' | 'system_unavailable';
	backend?: 'wordpress_ai_client' | 'openai_direct' | 'none';
  featureEnabled: boolean;
  sdkPresent: boolean;
  providerConfigured: boolean;
	connectorConfigured?: boolean;
	directKeyConfigured?: boolean;
	directKeySource?: 'environment' | 'constant' | 'database' | 'none';
  schedulerPresent: boolean;
  mbstringPresent: boolean;
  domPresent: boolean;
  canEdit: boolean;
  jobsUrl: string;
  jobsBaseUrl: string;
  timelineUrl: string;
  timelineBaseUrl: string;
  connectorsUrl: string;
	settingsUrl?: string;
  canManageConnectors: boolean;
	canManageSettings?: boolean;
  maxPromptChars?: number;
  initialRequest?: {
    requestId: string;
    prompt: string;
  } | null;
};

export type AiConnectionState = Omit<AiAvailability,
  'jobsUrl' | 'jobsBaseUrl' | 'timelineUrl' | 'timelineBaseUrl' |
  'connectorsUrl' | 'settingsUrl' | 'initialRequest'>;

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
  /* Absent on events stored before the agent started emitting them. */
  turn?: number;
  maxTurns?: number;
  phase?: string;
  target?: string;
  ok?: boolean;
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
  timelineItem: AiTimelineItem | null;
};

export type AiTimelineType = 'ai_edit' | 'agent_edit' | 'save' | 'restore';

export type AiTimelineItem = {
  id: number;
  activityId: string;
  type: AiTimelineType;
  jobId: string | null;
  requestId: string | null;
  prompt: string | null;
  contexts: Array<{ lcId?: string; tagName?: string; text?: string }>;
  executionStatus: AiJobStatus | null;
  /** pending and discarded apply to agent proposals, which become applied once saved merged. */
  applicationStatus: 'not_applied' | 'applied' | 'reverted' | 'pending' | 'discarded' | null;
  changedTargets: ChangedTarget[];
  changeStats: Partial<Record<ChangedTarget, ChangeStat>> | null;
  durationSeconds: number | null;
  timeoutMs: number | null;
  model: string | null;
  inputTokens: number | null;
  outputTokens: number | null;
  beforeHash: string | null;
  afterHash: string | null;
  beforeJsMode: 'classic' | 'module' | null;
  afterJsMode: 'classic' | 'module' | null;
  revisionId: number | null;
  sourceActivityId: number | null;
  sourcePrompt: string | null;
  restoreTarget: 'before' | 'after' | null;
  /** What the built-in AI or the external agent says it changed, written for the person who asked. */
  summary?: string | null;
  detailsAvailable: boolean;
  /** False when the state before the edit cannot be restored, such as a page an agent created. */
  beforeAvailable?: boolean;
  /** Agent that made an agent_edit over MCP, such as an application password name. */
  agentClient?: string | null;
  agentAction?: 'create' | 'update' | null;
  /** Title before and after an agent edit that renamed the page. */
  titleChange?: { before: string; after: string } | null;
  /**
   * Set when an agent saved the edit to its working copy as a proposal. agent
   * groups an agent's proposals; chain groups those built on one working
   * copy, where each includes the ones before it.
   */
  proposal?: { agent: string; chain: string } | null;
  canPoll: boolean;
  revisionAvailable: boolean;
  author: { id: number; name: string };
  createdAt: string;
  updatedAt: string;
};

export type AiTimelineResponse = {
  ok: boolean;
  items: AiTimelineItem[];
  hasMore: boolean;
  nextCursor: number | null;
};

export type AiTimelineSnapshotResponse = { ok: boolean; snapshot: EditorSnapshot };
export type AiTimelineStateResponse = { ok: boolean; signature: string };
export type AiTimelineRestoreResponse = AiTimelineSnapshotResponse & { item: AiTimelineItem | null };

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
  initialRequestId?: string;
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
  activityId?: number;
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
