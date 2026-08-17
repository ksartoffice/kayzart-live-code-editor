export type AiActivityState = 'idle' | 'running' | 'complete' | 'error';

export const resolveAiActivityNotification = (
  running: boolean,
  error: string
): AiActivityState | null => {
  if (running) return 'running';
  return error ? 'error' : null;
};

export const resolveToolbarAiActivity = (
  current: AiActivityState,
  incoming: AiActivityState | undefined,
  panelVisible: boolean
): AiActivityState => {
  const activity = incoming ?? current;
  return panelVisible && activity !== 'running' ? 'idle' : activity;
};
