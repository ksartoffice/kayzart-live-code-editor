import { describe, expect, it } from 'vitest';
import { resolveQuotedValueReplacementRange } from '../../../../src/admin/logic/media-insertion';

describe('media insertion logic', () => {
  it('replaces the whole quoted value when the cursor is inside it', () => {
    const content = '<img src="old">';
    const cursor = content.indexOf('old') + 1;

    expect(resolveQuotedValueReplacementRange(content, { from: cursor, to: cursor })).toEqual({
      from: content.indexOf('old'),
      to: content.indexOf('old') + 'old'.length,
    });
  });

  it('inserts inside empty quotes without replacing the quotes', () => {
    const content = '<img src="">';
    const cursor = content.indexOf('">');

    expect(resolveQuotedValueReplacementRange(content, { from: cursor, to: cursor })).toEqual({
      from: cursor,
      to: cursor,
    });
  });

  it('replaces a selected value surrounded by quotes', () => {
    const content = '<img src="old">';
    const from = content.indexOf('old');
    const to = from + 'old'.length;

    expect(resolveQuotedValueReplacementRange(content, { from, to })).toEqual({ from, to });
  });

  it('does not replace when the cursor is outside quoted text', () => {
    const content = '<p>Insert here</p>';
    const cursor = content.indexOf('here');

    expect(resolveQuotedValueReplacementRange(content, { from: cursor, to: cursor })).toBeNull();
  });

  it('does not replace text between two separate quoted attributes', () => {
    const content = '<img src="old" alt="description">';
    const cursor = content.indexOf(' alt=');

    expect(resolveQuotedValueReplacementRange(content, { from: cursor, to: cursor })).toBeNull();
  });
});
