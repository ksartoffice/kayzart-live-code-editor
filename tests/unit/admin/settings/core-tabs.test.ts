import { describe, expect, it } from 'vitest';
import { getCoreSettingsTabs } from '../../../../src/admin/settings';

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
});
