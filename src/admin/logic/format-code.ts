import { css_beautify, html_beautify, js_beautify } from 'js-beautify';

const BASE_FORMAT_OPTIONS = {
  indent_size: 2,
  indent_char: ' ',
  wrap_line_length: 0,
  preserve_newlines: true,
};

export function formatHtmlCode(source: string): string {
  if (!source.trim()) {
    return source;
  }

  return html_beautify(source, {
    ...BASE_FORMAT_OPTIONS,
  });
}

export function formatCssCode(source: string): string {
  if (!source.trim()) {
    return source;
  }

  return css_beautify(source, {
    ...BASE_FORMAT_OPTIONS,
  });
}

export function formatJavaScriptCode(source: string): string {
  if (!source.trim()) {
    return source;
  }

  return js_beautify(source, {
    ...BASE_FORMAT_OPTIONS,
  });
}
