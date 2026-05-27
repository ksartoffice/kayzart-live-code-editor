import type { SettingsData } from './settings';
import { __, sprintf } from '@wordpress/i18n';
import type { ApiFetch } from './types/api-fetch';
import type { CompileTailwindResponse, SaveResponse } from './types/rest';
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

type TailwindCompilerDeps = {
  apiFetch: ApiFetch;
  restCompileUrl: string;
  postId: number;
  getHtml: () => string;
  getCss: () => string;
  isTailwindEnabled: () => boolean;
  onCssCompiled: (css: string) => void;
  onStatus: (text: string) => void;
  onStatusClear: () => void;
};

export type TailwindCompiler = {
  compile: () => Promise<void>;
  isInFlight: () => boolean;
};

export function createTailwindCompiler(deps: TailwindCompilerDeps): TailwindCompiler {
  let tailwindCompileToken = 0;
  let tailwindCompileInFlight = false;
  let tailwindCompileQueued = false;

  const compile = async () => {
    if (!deps.isTailwindEnabled()) return;
    if (tailwindCompileInFlight) {
      tailwindCompileQueued = true;
      return;
    }
    tailwindCompileInFlight = true;
    tailwindCompileQueued = false;
    const currentToken = ++tailwindCompileToken;

    try {
      const res = await deps.apiFetch<CompileTailwindResponse>({
        url: deps.restCompileUrl,
        method: 'POST',
        data: {
          post_id: deps.postId,
          html: deps.getHtml(),
          css: deps.getCss(),
        },
      });

      if (currentToken !== tailwindCompileToken || !deps.isTailwindEnabled()) {
        return;
      }

      if (res?.ok && typeof res.css === 'string') {
        deps.onCssCompiled(res.css);
        deps.onStatusClear();
      } else {
        deps.onStatus(__( 'Tailwind compile failed.', 'kayzart-live-code-editor'));
      }
    } catch (error: unknown) {
      if (currentToken !== tailwindCompileToken) {
        return;
      }
      const message = resolveUnknownErrorMessage(
        error,
        __('Tailwind compile failed.', 'kayzart-live-code-editor')
      );
      /* translators: %s: error message. */
      deps.onStatus(sprintf(__( 'Tailwind error: %s', 'kayzart-live-code-editor'), message));
    } finally {
      if (currentToken === tailwindCompileToken) {
        tailwindCompileInFlight = false;
      }
      if (deps.isTailwindEnabled() && tailwindCompileQueued) {
        tailwindCompileQueued = false;
        compile();
      }
    }
  };

  return {
    compile,
    isInFlight: () => tailwindCompileInFlight,
  };
}

type SaveParams = {
  apiFetch: ApiFetch;
  restUrl: string;
  postId: number;
  html: string;
  customHead: string;
  css: string;
  tailwindEnabled: boolean;
  canEditJs: boolean;
  js: string;
  jsMode: JsMode;
  settingsUpdates?: Record<string, unknown>;
};

export async function saveKayzArt(
  params: SaveParams
): Promise<{ ok: boolean; error?: string; customHead?: string; customHeadRemovedTags?: string[]; settings?: SettingsData }> {
  try {
    const payload: Record<string, unknown> = {
      post_id: params.postId,
      html: params.html,
      css: params.css,
      tailwindEnabled: params.tailwindEnabled,
    };
    if (params.canEditJs) {
      payload.customHead = params.customHead;
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
      const customHead = typeof res?.customHead === 'string' ? res.customHead : undefined;
      const customHeadRemovedTags = Array.isArray(res?.customHeadRemovedTags)
        ? res.customHeadRemovedTags.filter((tag): tag is string => typeof tag === 'string')
        : undefined;
      return { ok: true, customHead, customHeadRemovedTags, settings };
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
