import { describe, expect, it } from 'vitest';
import { resolveDefaultTemplateMode, resolveTemplateMode } from '../../../../src/admin/logic/template-mode';

describe('template mode logic', () => {
  it('resolves valid template mode values', () => {
    expect(resolveTemplateMode('default')).toBe('default');
    expect(resolveTemplateMode('standalone')).toBe('standalone');
    expect(resolveTemplateMode('theme')).toBe('theme');
  });

  it('falls back to default mode for invalid values', () => {
    expect(resolveTemplateMode('')).toBe('default');
    expect(resolveTemplateMode('frame')).toBe('default');
    expect(resolveTemplateMode('unknown')).toBe('default');
    expect(resolveTemplateMode(undefined)).toBe('default');
  });

  it('resolves valid default template mode values', () => {
    expect(resolveDefaultTemplateMode('standalone')).toBe('standalone');
    expect(resolveDefaultTemplateMode('theme')).toBe('theme');
  });

  it('falls back to theme for invalid default template mode values', () => {
    expect(resolveDefaultTemplateMode('')).toBe('theme');
    expect(resolveDefaultTemplateMode('frame')).toBe('theme');
    expect(resolveDefaultTemplateMode('default')).toBe('theme');
    expect(resolveDefaultTemplateMode(undefined)).toBe('theme');
  });
});

