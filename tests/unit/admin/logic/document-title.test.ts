import { beforeEach, describe, expect, it } from 'vitest';
import {
  buildEditorDocumentTitleLabel,
  createDocumentTitleSync,
  extractAdminTitleSuffix,
} from '../../../../src/admin/logic/document-title';

describe('document title logic', () => {
  beforeEach(() => {
    document.title = 'KayzArt < Test Site - WordPress';
  });

  it('builds editor title label with fallback', () => {
    expect(buildEditorDocumentTitleLabel('My Page')).toBe('KayzArt Landing Page Editor: My Page');
    expect(buildEditorDocumentTitleLabel('')).toBe('KayzArt Landing Page Editor: Untitled');
  });

  it('extracts admin suffix using configured separators', () => {
    expect(extractAdminTitleSuffix('KayzArt < Test Site', [' < '])).toBe(' < Test Site');
    expect(extractAdminTitleSuffix('KayzArt &lsaquo; Test Site', [' &lsaquo; '])).toBe(
      ' &lsaquo; Test Site'
    );
  });

  it('syncs document title while preserving suffix', () => {
    const sync = createDocumentTitleSync('KayzArt < Test Site - WordPress', [' < ']);
    sync('Landing');
    expect(document.title).toBe('KayzArt Landing Page Editor: Landing < Test Site - WordPress');
  });
});
