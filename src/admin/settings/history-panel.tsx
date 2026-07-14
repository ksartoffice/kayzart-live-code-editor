import { createElement, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { EditorSnapshot } from '../extensions/settings-tab-registry';
import type {
  RevisionDetailResponse,
  RevisionListResponse,
  RevisionSection,
  RevisionSummary,
} from '../types/rest';

type ApiFetch = <T>(options: { url: string; method?: string }) => Promise<T>;

type HistoryPanelProps = {
  postId: number;
  restUrl: string;
  apiFetch: ApiFetch;
  supported: boolean;
  currentVersion: string;
  canUpdateCore: boolean;
  updateCoreUrl: string;
  refreshToken: number;
  hasUnsavedChanges: () => boolean;
  onLoadSnapshot: (snapshot: EditorSnapshot) => boolean;
};

const SECTION_LABELS: Record<RevisionSection, string> = {
  html: 'HTML',
  css: 'CSS',
  javascript: 'JavaScript',
  customHead: 'Head',
};

function appendQuery(url: string, params: Record<string, string | number>) {
  const target = new URL(url, window.location.href);
  Object.entries(params).forEach(([key, value]) => target.searchParams.set(key, String(value)));
  return target.toString();
}

export function HistoryPanel(props: HistoryPanelProps) {
  const [items, setItems] = useState<RevisionSummary[]>([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(0);
  const [revisionsEnabled, setRevisionsEnabled] = useState(true);
  const [canLoad, setCanLoad] = useState(false);
  const [loading, setLoading] = useState(false);
  const [loadingRevisionId, setLoadingRevisionId] = useState<number | null>(null);
  const [error, setError] = useState('');

  const loadPage = async (nextPage: number, append: boolean) => {
    setLoading(true);
    setError('');
    try {
      const response = await props.apiFetch<RevisionListResponse>({
        url: appendQuery(props.restUrl, { post_id: props.postId, page: nextPage, per_page: 20 }),
      });
      setItems((current) => (append ? [...current, ...response.revisions] : response.revisions));
      setPage(response.pagination.page);
      setTotalPages(response.pagination.totalPages);
      setRevisionsEnabled(response.revisionsEnabled);
      setCanLoad(response.canLoad);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : __('Failed to load history.', 'kayzart-live-code-editor'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!props.supported) {
      return;
    }
    void loadPage(1, false);
    // loadPage intentionally depends on the immutable editor configuration.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [props.supported, props.refreshToken]);

  const loadRevision = async (revision: RevisionSummary) => {
    if (!canLoad || loadingRevisionId !== null) {
      return;
    }
    if (
      props.hasUnsavedChanges() &&
      !window.confirm(
        __('Loading this version will discard your current unsaved changes. Continue?', 'kayzart-live-code-editor')
      )
    ) {
      return;
    }

    setLoadingRevisionId(revision.id);
    setError('');
    try {
      const response = await props.apiFetch<RevisionDetailResponse>({
        url: appendQuery(`${props.restUrl}/${revision.id}`, { post_id: props.postId }),
      });
      if (!response.ok || !response.revision) {
        throw new Error(response.error || __('Failed to load revision.', 'kayzart-live-code-editor'));
      }
      props.onLoadSnapshot(response.revision.snapshot);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : __('Failed to load revision.', 'kayzart-live-code-editor'));
    } finally {
      setLoadingRevisionId(null);
    }
  };

  if (!props.supported) {
    return (
      <div className="kayzart-historyMessage" data-kayzart-history="unsupported">
        <strong>{__('Full-page revisions require WordPress 6.4 or later.', 'kayzart-live-code-editor')}</strong>
        <p>
          {sprintf(
            __('This site is running WordPress %s.', 'kayzart-live-code-editor'),
            props.currentVersion || __('Unknown', 'kayzart-live-code-editor')
          )}
        </p>
        {props.canUpdateCore && props.updateCoreUrl ? (
          <a className="kayzart-btn kayzart-btn-secondary" href={props.updateCoreUrl}>
            {__('Update WordPress', 'kayzart-live-code-editor')}
          </a>
        ) : (
          <p>{__('Contact a site administrator to update WordPress.', 'kayzart-live-code-editor')}</p>
        )}
      </div>
    );
  }

  if (!revisionsEnabled && !loading && items.length === 0) {
    return (
      <div className="kayzart-historyMessage" data-kayzart-history="disabled">
        <strong>{__('Revisions are disabled for this site.', 'kayzart-live-code-editor')}</strong>
        <p>{__('Enable WordPress revisions to keep full-page history.', 'kayzart-live-code-editor')}</p>
      </div>
    );
  }

  return (
    <div className="kayzart-historyPanel" data-kayzart-history="list">
      {!revisionsEnabled ? (
        <div className="kayzart-historyNotice" data-kayzart-history="disabled">
          <strong>{__('Revisions are disabled for this site.', 'kayzart-live-code-editor')}</strong>
          <p>{__('Enable WordPress revisions to keep full-page history.', 'kayzart-live-code-editor')}</p>
        </div>
      ) : null}
      {!canLoad ? (
        <div className="kayzart-historyNotice">
          {__('You can view history, but loading a complete version requires unfiltered HTML permission.', 'kayzart-live-code-editor')}
        </div>
      ) : null}
      {error ? <div className="kayzart-historyError">{error}</div> : null}
      {!loading && items.length === 0 ? (
        <div className="kayzart-historyMessage">{__('No full-page revisions yet.', 'kayzart-live-code-editor')}</div>
      ) : null}
      <div className="kayzart-historyList">
        {items.map((revision) => (
          <article className="kayzart-historyItem" key={revision.id}>
            <div className="kayzart-historyItemHeader">
              <strong>{new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(revision.date))}</strong>
              <span>{revision.author.name}</span>
            </div>
            <div className="kayzart-historyBadges">
              {revision.isFirst ? <span>{__('First snapshot', 'kayzart-live-code-editor')}</span> : null}
              {revision.changedSections.map((section) => (
                <span key={section}>{SECTION_LABELS[section]}</span>
              ))}
            </div>
            <button
              type="button"
              className="kayzart-btn kayzart-btn-secondary kayzart-historyLoad"
              disabled={!canLoad || loadingRevisionId !== null}
              onClick={() => void loadRevision(revision)}
            >
              {loadingRevisionId === revision.id
                ? __('Loading...', 'kayzart-live-code-editor')
                : __('Load this version', 'kayzart-live-code-editor')}
            </button>
          </article>
        ))}
      </div>
      {page < totalPages ? (
        <button
          type="button"
          className="kayzart-btn kayzart-btn-secondary kayzart-historyMore"
          disabled={loading}
          onClick={() => void loadPage(page + 1, true)}
        >
          {loading ? __('Loading...', 'kayzart-live-code-editor') : __('Load more', 'kayzart-live-code-editor')}
        </button>
      ) : null}
    </div>
  );
}
