import { describe, expect, it } from 'vitest';
import { getElementContext } from '../../../src/admin/element-text';

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
