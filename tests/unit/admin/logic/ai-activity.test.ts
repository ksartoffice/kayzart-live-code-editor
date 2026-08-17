import { describe, expect, it } from 'vitest';
import {
  resolveAiActivityNotification,
  resolveToolbarAiActivity,
} from '../../../../src/admin/logic/ai-activity';

describe('AI activity state', () => {
  it('keeps running visible while the AI panel is open or closed', () => {
    expect(resolveToolbarAiActivity('idle', 'running', true)).toBe('running');
    expect(resolveToolbarAiActivity('running', undefined, false)).toBe('running');
  });

  it('shows terminal activity while hidden and acknowledges it when the AI panel opens', () => {
    expect(resolveToolbarAiActivity('idle', 'complete', false)).toBe('complete');
    expect(resolveToolbarAiActivity('complete', undefined, true)).toBe('idle');
    expect(resolveToolbarAiActivity('idle', 'error', false)).toBe('error');
    expect(resolveToolbarAiActivity('error', undefined, true)).toBe('idle');
  });

  it('prioritizes a running job over a transient polling error', () => {
    expect(resolveAiActivityNotification(true, 'Connection lost. Retrying.')).toBe('running');
    expect(resolveAiActivityNotification(false, 'AI edit failed.')).toBe('error');
    expect(resolveAiActivityNotification(false, '')).toBeNull();
  });
});
