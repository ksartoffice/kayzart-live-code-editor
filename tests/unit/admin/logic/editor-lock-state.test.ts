import { describe, expect, it } from 'vitest';
import {
  isEditorLockRequestCurrent,
  nextEditorLockGeneration,
  resolveEditorLockState,
} from '../../../../src/admin/logic/editor-lock-state';

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

  it('invalidates async requests after a lock and unlock transition', () => {
    const requestGeneration = 0;
    const lockedGeneration = nextEditorLockGeneration(false, true, requestGeneration);
    const unlockedGeneration = nextEditorLockGeneration(true, false, lockedGeneration);

    expect(isEditorLockRequestCurrent(false, requestGeneration, requestGeneration)).toBe(true);
    expect(isEditorLockRequestCurrent(true, lockedGeneration, requestGeneration)).toBe(false);
    expect(isEditorLockRequestCurrent(false, unlockedGeneration, requestGeneration)).toBe(false);
  });

  it('does not invalidate requests when the lock state is repeated', () => {
    expect(nextEditorLockGeneration(false, false, 4)).toBe(4);
    expect(nextEditorLockGeneration(true, true, 7)).toBe(7);
  });
});
