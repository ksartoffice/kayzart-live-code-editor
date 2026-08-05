import { describe, expect, it } from 'vitest';
import {
  createTailwindCompileSignature,
  extractTailwindCandidates,
} from '../../../../src/admin/logic/tailwind-candidates';

describe('extractTailwindCandidates', () => {
  it('matches TailwindPHP extraction order and removes duplicates', () => {
    const html =
      '<div className="md:grid-cols-3 flex"><span class="flex bg-[#123456] before:content-[hello]"></span></div>';

    expect(extractTailwindCandidates(html)).toEqual([
      'flex',
      'bg-[#123456]',
      'before:content-[hello]',
      'md:grid-cols-3',
    ]);
  });

  it('keeps quoted arbitrary values and the classes listed after them', () => {
    const html = `<div class="font-['Noto_Sans_JP'] flex md:grid-cols-3"></div>`;

    expect(extractTailwindCandidates(html)).toEqual([
      "font-['Noto_Sans_JP']",
      'flex',
      'md:grid-cols-3',
    ]);
  });

  it('reads double quotes inside a single-quoted attribute', () => {
    const html = `<div class='bg-[url("a.png")] p-4'></div>`;

    expect(extractTailwindCandidates(html)).toEqual(['bg-[url("a.png")]', 'p-4']);
  });

  it('skips empty class attributes', () => {
    expect(extractTailwindCandidates('<div class=""></div><span class="flex"></span>')).toEqual([
      'flex',
    ]);
  });

  it('extracts a compact candidate set from HTML larger than two megabytes', () => {
    const html = `<div class="flex text-sm">Large</div>${'x'.repeat(2 * 1024 * 1024)}`;

    expect(extractTailwindCandidates(html)).toEqual(['flex', 'text-sm']);
  });

  it('changes the compile signature only when CSS or candidates change', () => {
    const original = createTailwindCompileSignature(['flex'], '@import "tailwindcss";');

    expect(createTailwindCompileSignature(['flex'], '@import "tailwindcss";')).toBe(original);
    expect(createTailwindCompileSignature(['grid'], '@import "tailwindcss";')).not.toBe(original);
    expect(createTailwindCompileSignature(['flex'], '@theme {}')).not.toBe(original);
  });
});
