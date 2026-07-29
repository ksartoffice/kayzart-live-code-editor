import { describe, expect, it } from 'vitest';
import { resolveEditorLockState } from '../../../../src/admin/logic/editor-lock-state';

describe('editor lock state', () => {
  it('locks only HTML and CSS while a CSS mode change is compiling', () => {
    expect(resolveEditorLockState(false, true)).toEqual({
      htmlAndCss: true,
      otherEditors: false,
    });
  });

  it('locks every editor while an extension owns the editor lock', () => {
    expect(resolveEditorLockState(true, false)).toEqual({
      htmlAndCss: true,
      otherEditors: true,
    });
  });

  it('retains the extension lock after a CSS mode change finishes', () => {
    expect(resolveEditorLockState(true, false)).toEqual({
      htmlAndCss: true,
      otherEditors: true,
    });
  });

  it('unlocks every editor when neither operation is active', () => {
    expect(resolveEditorLockState(false, false)).toEqual({
      htmlAndCss: false,
      otherEditors: false,
    });
  });
});
