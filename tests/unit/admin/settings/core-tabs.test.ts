import { describe, expect, it } from 'vitest';
import { getCoreSettingsTabs, getKeyboardTabIndex } from '../../../../src/admin/settings';

describe('core settings tabs', () => {
  it('places AI first for users who can edit with AI', () => {
    expect(getCoreSettingsTabs(true).map((tab) => tab.id)).toEqual([
      'kayzart-ai',
      'elements',
      'history',
      'settings',
    ]);
  });

  it('starts non-AI users in the elements-first core tab order', () => {
    expect(getCoreSettingsTabs(false).map((tab) => tab.id)).toEqual([
      'elements',
      'history',
      'settings',
    ]);
  });

  it('supports wrapping arrow navigation plus Home and End', () => {
    expect(getKeyboardTabIndex('ArrowRight', 2, 3)).toBe(0);
    expect(getKeyboardTabIndex('ArrowLeft', 0, 3)).toBe(2);
    expect(getKeyboardTabIndex('Home', 2, 3)).toBe(0);
    expect(getKeyboardTabIndex('End', 0, 3)).toBe(2);
    expect(getKeyboardTabIndex('Escape', 1, 3)).toBeNull();
  });
});
