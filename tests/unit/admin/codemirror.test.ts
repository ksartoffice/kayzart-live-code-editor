import { describe, expect, it } from 'vitest';
import { decodeKeybinding } from '../../../src/admin/codemirror';

describe('codemirror keybinding decoding', () => {
  it('decodes Shift + Alt + F for HTML formatting', () => {
    const keyModAlt = 1 << 9;
    const keyModShift = 1 << 10;
    const keyCodeF = 3;

    expect(decodeKeybinding(keyModShift | keyModAlt | keyCodeF)).toBe('Shift-Alt-f');
  });
});
