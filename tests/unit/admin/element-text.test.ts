import { describe, expect, it } from 'vitest';
import {
  getElementContext,
  getImageSourceEditInfo,
  isSafeEditableElementHtml,
} from '../../../src/admin/element-text';

describe('isSafeEditableElementHtml', () => {
  it('allows plain text and completed inline HTML', () => {
    expect(isSafeEditableElementHtml('Keep your AI-made landing pages')).toBe(true);
    expect(isSafeEditableElementHtml('2 < 3')).toBe(true);
    expect(
      isSafeEditableElementHtml(
        'Keep your AI-made landing pages <span class="text-gradient">inside WordPress.</span>'
      )
    ).toBe(true);
    expect(isSafeEditableElementHtml('Line one<br>Line two')).toBe(true);
    expect(isSafeEditableElementHtml('Text <!-- note --> more text')).toBe(true);
  });

  it('rejects incomplete or unsupported element HTML', () => {
    expect(isSafeEditableElementHtml('<span class="')).toBe(false);
    expect(isSafeEditableElementHtml('<span class="text-gradient">')).toBe(false);
    expect(isSafeEditableElementHtml('<span>inside')).toBe(false);
    expect(isSafeEditableElementHtml('<script>alert(1)</script>')).toBe(false);
    expect(isSafeEditableElementHtml('<strong>inside</strong>')).toBe(false);
    expect(isSafeEditableElementHtml('<div>inside</div>')).toBe(false);
  });
});

describe('getElementContext', () => {
  it('uses descendant text for selected element context', () => {
    const html = [
      '<section data-kayzart-id="section-1" class="testimonials">',
      '  <article>',
      '    <div class="testimonial-card__head">',
      '      <div>',
      '        <p class="testimonial-card__name">50代・女性</p>',
      '        <p class="testimonial-card__meta">ご家族で利用</p>',
      '      </div>',
      '      <p class="testimonial-card__rate">★★★★★</p>',
      '    </div>',
      '  </article>',
      '</section>',
    ].join('\n');

    const context = getElementContext(html, 'section-1');

    expect(context?.text).toBe('50代・女性 ご家族で利用 ★★★★★');
  });

  it('returns null text when selected element has no descendant text', () => {
    const html = '<div data-kayzart-id="empty-1"><img src="example.jpg" alt=""></div>';

    const context = getElementContext(html, 'empty-1');

    expect(context?.text).toBeNull();
  });
});

describe('getImageSourceEditInfo', () => {
  it('returns only the src value range for a selected image', () => {
    const html = '<img data-kayzart-id="image-1" src="old.jpg" alt="Sample">';
    const info = getImageSourceEditInfo(html, 'image-1');
    const from = html.indexOf('old.jpg');

    expect(info).toEqual({
      startOffset: from,
      endOffset: from + 'old.jpg'.length,
      insertPrefix: '',
      insertSuffix: '',
    });
  });

  it('returns an insertion point when the selected image has no src', () => {
    const html = '<img data-kayzart-id="image-1" alt="Sample">';
    const info = getImageSourceEditInfo(html, 'image-1');
    const offset = html.indexOf('>');

    expect(info).toEqual({
      startOffset: offset,
      endOffset: offset,
      insertPrefix: ' src="',
      insertSuffix: '"',
    });
  });

  it('returns an empty value range when the selected image has an empty src', () => {
    const html = '<img data-kayzart-id="image-1" src="" alt="Sample">';
    const info = getImageSourceEditInfo(html, 'image-1');
    const offset = html.indexOf('""') + 1;

    expect(info).toEqual({
      startOffset: offset,
      endOffset: offset,
      insertPrefix: '',
      insertSuffix: '',
    });
  });

  it('returns null for non-image elements and unknown ids', () => {
    const html = '<section data-kayzart-id="section-1"><img src="old.jpg"></section>';

    expect(getImageSourceEditInfo(html, 'section-1')).toBeNull();
    expect(getImageSourceEditInfo(html, 'missing')).toBeNull();
  });
});
