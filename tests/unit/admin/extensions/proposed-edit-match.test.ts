import { describe, expect, it } from 'vitest';
import { resolveReplaceMatchRange } from '../../../../src/admin/extensions/proposed-edit-match';

describe('resolveReplaceMatchRange', () => {
  it('resolves a unique match without scope', () => {
    expect(resolveReplaceMatchRange('<div>hello</div>', 'hello')).toEqual({
      ok: true,
      startOffset: 5,
      endOffset: 10,
    });
  });

  it('resolves a unique match within scope', () => {
    expect(
      resolveReplaceMatchRange(
        '<div>first</div>\n<section>first</section>',
        'first',
        '<section>first</section>'
      )
    ).toEqual({
      ok: true,
      startOffset: 26,
      endOffset: 31,
    });
  });

  it('returns INVALID_REQUEST when beforeText is empty', () => {
    expect(resolveReplaceMatchRange('abc', '')).toMatchObject({
      ok: false,
      code: 'INVALID_REQUEST',
    });
  });

  it('returns INVALID_REQUEST when scopeText is empty', () => {
    expect(resolveReplaceMatchRange('abc', 'a', '')).toMatchObject({
      ok: false,
      code: 'INVALID_REQUEST',
    });
  });

  it('returns NOT_FOUND when beforeText does not exist', () => {
    expect(resolveReplaceMatchRange('abc', 'zzz')).toMatchObject({
      ok: false,
      code: 'NOT_FOUND',
    });
  });

  it('returns AMBIGUOUS_MATCH when beforeText exists multiple times', () => {
    expect(resolveReplaceMatchRange('foo bar foo', 'foo')).toMatchObject({
      ok: false,
      code: 'AMBIGUOUS_MATCH',
    });
  });

  it('returns SCOPE_NOT_FOUND when scopeText does not exist', () => {
    expect(resolveReplaceMatchRange('foo bar', 'foo', 'section')).toMatchObject({
      ok: false,
      code: 'SCOPE_NOT_FOUND',
    });
  });

  it('returns AMBIGUOUS_SCOPE when scopeText exists multiple times', () => {
    expect(resolveReplaceMatchRange('foo <a>x</a> foo <a>x</a>', 'x', '<a>x</a>')).toMatchObject({
      ok: false,
      code: 'AMBIGUOUS_SCOPE',
    });
  });

  it('returns NOT_FOUND when beforeText is outside the unique scope', () => {
    expect(
      resolveReplaceMatchRange('before <scope>inside</scope>', 'before', '<scope>inside</scope>')
    ).toMatchObject({
      ok: false,
      code: 'NOT_FOUND',
    });
  });
});
