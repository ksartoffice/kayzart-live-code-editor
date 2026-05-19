import type { ExportPayload } from './types';
import type { SettingsData } from './settings';
import { __ } from '@wordpress/i18n';
import type { ApiFetch } from './types/api-fetch';
import type { SaveResponse } from './types/rest';
import type { JsMode } from './types/js-mode';

const resolveUnknownErrorMessage = (error: unknown, fallbackMessage: string): string => {
  if (error instanceof Error && error.message.trim()) {
    return error.message;
  }

  if (typeof error === 'string' && error.trim()) {
    return error;
  }

  if (error && typeof error === 'object') {
    const maybeError = error as Record<string, unknown>;
    if (typeof maybeError.message === 'string' && maybeError.message.trim()) {
      return maybeError.message;
    }
    if (typeof maybeError.error === 'string' && maybeError.error.trim()) {
      return maybeError.error;
    }
  }

  const message = String(error);
  if (message && message !== '[object Object]') {
    return message;
  }

  return fallbackMessage;
};

type SaveParams = {
  apiFetch: ApiFetch;
  restUrl: string;
  postId: number;
  html: string;
  css: string;
  canEditJs: boolean;
  js: string;
  jsMode: JsMode;
  settingsUpdates?: Record<string, unknown>;
};

export async function saveKayzArt(
  params: SaveParams
): Promise<{ ok: boolean; error?: string; settings?: SettingsData }> {
  try {
    const payload: Record<string, unknown> = {
      post_id: params.postId,
      html: params.html,
      css: params.css,
    };
    if (params.canEditJs) {
      payload.js = params.js;
      payload.jsMode = params.jsMode;
    }
    if (params.settingsUpdates && Object.keys(params.settingsUpdates).length > 0) {
      payload.settingsUpdates = params.settingsUpdates;
    }
    const res = await params.apiFetch<SaveResponse>({
      url: params.restUrl,
      method: 'POST',
      data: payload,
    });

    if (res?.ok) {
      const settings =
        res?.settings && typeof res.settings === 'object'
          ? (res.settings as SettingsData)
          : undefined;
      return { ok: true, settings };
    }
    if (typeof res?.error === 'string' && res.error.trim()) {
      return { ok: false, error: res.error };
    }
    return { ok: false };
  } catch (error: unknown) {
    return {
      ok: false,
      error: resolveUnknownErrorMessage(error, __('Save failed.', 'kayzart-live-code-editor')),
    };
  }
}

type ExportParams = {
  postId: number;
  html: string;
  css: string;
  js: string;
  jsMode: JsMode;
  externalScripts: string[];
  externalStyles: string[];
  liveHighlightEnabled: boolean;
};

export async function exportKayzArt(
  params: ExportParams
): Promise<{ ok: boolean; error?: string }> {
  try {
    const payload: ExportPayload = {
      version: 1,
      html: params.html,
      css: params.css,
      js: params.js,
      jsMode: params.jsMode,
      externalScripts: [...params.externalScripts],
      externalStyles: [...params.externalStyles],
      liveHighlightEnabled: params.liveHighlightEnabled,
    };

    const blob = new Blob([JSON.stringify(payload, null, 2)], {
      type: 'application/json',
    });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `kayzart-${params.postId}.json`;
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => {
      window.URL.revokeObjectURL(url);
    }, 500);

    return { ok: true };
  } catch (error: unknown) {
    return {
      ok: false,
      error: resolveUnknownErrorMessage(error, __('Export failed.', 'kayzart-live-code-editor')),
    };
  }
}
