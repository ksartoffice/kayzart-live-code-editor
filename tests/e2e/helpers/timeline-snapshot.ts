import type { Page } from '@playwright/test';

/**
 * Fetches a snapshot the AI already produced, straight from the timeline REST
 * route, so the freeze can be replayed against the exact payload that caused it
 * without paying for another model run. Synthetic fixtures did not reproduce
 * the hang: the real output carries very long arbitrary-value Tailwind classes
 * that a hand-written page does not.
 */

export type ReplaySnapshot = {
  html: string;
  customHead: string;
  css: string;
  js: string;
  jsMode: string;
  editorMode?: string;
  baseHash: string;
};

/**
 * @param activityId Timeline activity to replay, or 0 for the newest AI edit.
 */
export async function fetchStoredAiSnapshot(
  page: Page,
  activityId = 0
): Promise<ReplaySnapshot | null> {
  return page.evaluate(async (wantedId) => {
    const scope = window as any;
    const ai = scope.KAYZART?.ai;
    const nonce = scope.KAYZART?.restNonce || '';
    const postId = Number(scope.KAYZART?.post_id || 0);
    if (!ai?.timelineUrl || !ai?.timelineBaseUrl || !postId) return null;

    const request = async (url: string) => {
      const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': nonce },
      });
      if (!response.ok) return null;
      return response.json();
    };

    let id = wantedId;
    if (!id) {
      const timelineUrl = new URL(ai.timelineUrl, window.location.origin);
      timelineUrl.searchParams.set('post_id', String(postId));
      const timeline = await request(timelineUrl.toString());
      const items: any[] = Array.isArray(timeline?.items) ? timeline.items : [];
      const newest = items
        .filter((item) => item.type === 'ai_edit' && item.executionStatus === 'completed')
        .pop();
      if (!newest) return null;
      id = Number(newest.id);
    }

    const snapshotUrl = new URL(`${ai.timelineBaseUrl}${id}/snapshot`, window.location.origin);
    snapshotUrl.searchParams.set('target', 'after');
    const payload = await request(snapshotUrl.toString());
    const snapshot = payload?.snapshot;
    if (!snapshot || typeof snapshot.html !== 'string') return null;

    return {
      html: snapshot.html,
      customHead: snapshot.customHead ?? '',
      css: snapshot.css ?? '',
      js: snapshot.js ?? '',
      jsMode: snapshot.jsMode ?? 'classic',
      editorMode: snapshot.editorMode,
      baseHash: snapshot.baseHash ?? '',
    };
  }, activityId);
}
