export type CustomHeadSanitizeResult = {
  html: string;
  removedTags: string[];
};

const unique = (items: string[]) => Array.from(new Set(items));

export function sanitizeCustomHeadInput(source: string): CustomHeadSanitizeResult {
  const removedTags: string[] = [];
  const parser = new DOMParser();
  const doc = parser.parseFromString(`<!doctype html><html><head>${source || ''}</head><body></body></html>`, 'text/html');
  const head = doc.head;

  head.querySelectorAll('title').forEach((node) => {
    removedTags.push('title');
    node.remove();
  });
  head.querySelectorAll('base').forEach((node) => {
    removedTags.push('base');
    node.remove();
  });
  head.querySelectorAll('meta').forEach((node) => {
    if (node.hasAttribute('charset')) {
      removedTags.push('meta charset');
      node.remove();
      return;
    }
    if ((node.getAttribute('name') || '').trim().toLowerCase() === 'viewport') {
      removedTags.push('meta viewport');
      node.remove();
    }
  });

  return {
    html: head.innerHTML.trim(),
    removedTags: unique(removedTags),
  };
}
