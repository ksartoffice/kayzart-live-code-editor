import { __ } from '@wordpress/i18n';
import type { ImportPayload } from '../types';
import { normalizeJsMode } from '../types/js-mode';

type ValidationResult = { data?: ImportPayload; error?: string };

const isStringArray = (value: unknown): value is string[] =>
  Array.isArray(value) && value.every((item) => typeof item === 'string');

export function validateImportPayload(raw: unknown): ValidationResult {
  if (!raw || typeof raw !== 'object') {
    return { error: __('Import file is not a valid JSON object.', 'kayzart-live-code-editor') };
  }

  const payload = raw as Record<string, unknown>;

  if (payload.version !== 1) {
    return { error: __('Unsupported import version.', 'kayzart-live-code-editor') };
  }

  if (typeof payload.html !== 'string') {
    return { error: __('Invalid HTML value.', 'kayzart-live-code-editor') };
  }

  if (typeof payload.css !== 'string') {
    return { error: __('Invalid CSS value.', 'kayzart-live-code-editor') };
  }

  if (payload.tailwindEnabled !== undefined && typeof payload.tailwindEnabled !== 'boolean') {
    return { error: __('Invalid tailwindEnabled value.', 'kayzart-live-code-editor') };
  }

  if (payload.generatedCss !== undefined && typeof payload.generatedCss !== 'string') {
    return { error: __('Invalid generatedCss value.', 'kayzart-live-code-editor') };
  }

  if (payload.js !== undefined && typeof payload.js !== 'string') {
    return { error: __('Invalid JavaScript value.', 'kayzart-live-code-editor') };
  }

  if (payload.jsMode !== undefined && typeof payload.jsMode !== 'string') {
    return { error: __('Invalid jsMode value.', 'kayzart-live-code-editor') };
  }

  if (payload.jsMode !== undefined) {
    const mode = payload.jsMode.trim().toLowerCase();
    if (mode !== 'classic' && mode !== 'module' && mode !== 'auto') {
      return { error: __('Invalid jsMode value.', 'kayzart-live-code-editor') };
    }
  }

  if (payload.liveHighlightEnabled !== undefined && typeof payload.liveHighlightEnabled !== 'boolean') {
    return { error: __('Invalid liveHighlightEnabled value.', 'kayzart-live-code-editor') };
  }

  if (payload.externalScripts !== undefined && !isStringArray(payload.externalScripts)) {
    return { error: __('Invalid externalScripts value.', 'kayzart-live-code-editor') };
  }

  if (payload.externalStyles !== undefined && !isStringArray(payload.externalStyles)) {
    return { error: __('Invalid externalStyles value.', 'kayzart-live-code-editor') };
  }

  const importCss =
    payload.tailwindEnabled === true && typeof payload.generatedCss === 'string' && payload.generatedCss !== ''
      ? payload.generatedCss
      : payload.css;

  return {
    data: {
      version: 1,
      html: payload.html,
      css: importCss,
      js: payload.js ?? '',
      jsMode: normalizeJsMode(payload.jsMode),
      externalScripts: payload.externalScripts ?? [],
      externalStyles: payload.externalStyles ?? [],
      liveHighlightEnabled: payload.liveHighlightEnabled as boolean | undefined,
    },
  };
}
