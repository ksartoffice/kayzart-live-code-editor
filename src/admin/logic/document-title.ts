import { __, sprintf } from '@wordpress/i18n';

const DEFAULT_ADMIN_TITLE_SEPARATORS = [' \u2039 ', ' &lsaquo; '];

export function buildEditorDocumentTitleLabel(postTitle: string): string {
  const resolvedTitle = postTitle.trim() || __('Untitled', 'kayzart-live-code-editor');
  /* translators: %s: post title. */
  return sprintf(__('KayzArt Landing Page Editor: %s', 'kayzart-live-code-editor'), resolvedTitle);
}

export function extractAdminTitleSuffix(
  title: string,
  separators?: string[]
): string {
  for (const separator of separators || DEFAULT_ADMIN_TITLE_SEPARATORS) {
    const position = title.indexOf(separator);
    if (position >= 0) {
      return title.slice(position);
    }
  }
  return '';
}

export function createDocumentTitleSync(
  initialTitle: string,
  separators?: string[]
): (postTitle: string) => void {
  const suffix = extractAdminTitleSuffix(initialTitle, separators);
  return (postTitle: string) => {
    const nextLabel = buildEditorDocumentTitleLabel(postTitle);
    document.title = suffix ? `${nextLabel}${suffix}` : nextLabel;
  };
}
