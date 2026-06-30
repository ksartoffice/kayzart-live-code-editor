import { html_beautify } from 'js-beautify';

export function formatHtmlCode(source: string): string {
  if (!source.trim()) {
    return source;
  }

  return html_beautify(source, {
    indent_size: 2,
    indent_char: ' ',
    wrap_line_length: 0,
    preserve_newlines: true,
  });
}
