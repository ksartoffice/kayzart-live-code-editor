/** Matches Ai_Tools::compute_base_hash(), including UTF-16 surrogate pairs. */
export function computeEditorBaseHash(html: string, customHead: string, css: string, js: string): string {
  const source = `${html}\n\u0000${customHead}\n\u0000${css}\n\u0000${js}`;
  let hash = 0x811c9dc5;
  for (let i = 0; i < source.length; i += 1) {
    hash ^= source.charCodeAt(i);
    hash = Math.imul(hash, 0x01000193);
    hash >>>= 0;
  }
  return hash.toString(16).padStart(8, '0');
}
