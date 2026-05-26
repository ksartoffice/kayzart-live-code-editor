import { describe, expect, it } from 'vitest';
import {
  normalizeExternalResources,
  serializeExternalResources,
} from '../../../../src/admin/types/external-resource';

describe('external resource helpers', () => {
  it('normalizes legacy string urls and structured resources', () => {
    expect(
      normalizeExternalResources([
        'https://example.com/a.js',
        {
          url: ' https://example.com/b.js ',
          attrs: { defer: true, integrity: ' sha384-test ' },
        },
      ])
    ).toEqual([
      { url: 'https://example.com/a.js', attrs: {} },
      {
        url: 'https://example.com/b.js',
        attrs: { defer: true, integrity: 'sha384-test' },
      },
    ]);
  });

  it('deduplicates by url and serializes structured resources', () => {
    expect(
      serializeExternalResources([
        { url: 'https://example.com/a.js', attrs: { defer: true } },
        { url: 'https://example.com/a.js', attrs: { async: true } },
      ])
    ).toEqual([{ url: 'https://example.com/a.js', attrs: { defer: true } }]);
  });
});
