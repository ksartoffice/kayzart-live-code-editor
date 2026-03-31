import {
  createElement,
  Fragment,
  createRoot,
  render,
  useEffect,
  useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
  ChevronLeft,
  ChevronDown,
  Download,
  ExternalLink,
  Monitor,
  PanelBottomClose,
  PanelBottomOpen,
  PanelLeftClose,
  PanelLeftOpen,
  PanelRight,
  Redo2,
  Save,
  Smartphone,
  Tablet,
  Undo2,
  X,
} from 'lucide';
import { renderLucideIcon } from './lucide-icons';
import {
  getExternalToolbarActions,
  subscribeExternalToolbarActions,
  type ResolvedToolbarAction,
} from './extensions/toolbar-action-registry';

export type ViewportMode = 'desktop' | 'tablet' | 'mobile';

type ToolbarState = {
  backUrl: string;
  listUrl: string;
  canUndo: boolean;
  canRedo: boolean;
  editorCollapsed: boolean;
  compactEditorMode: boolean;
  settingsOpen: boolean;
  tailwindEnabled: boolean;
  viewportMode: ViewportMode;
  hasUnsavedChanges: boolean;
  viewPostUrl: string;
  postStatus: string;
  postTitle: string;
  postSlug: string;
};

type ToolbarHandlers = {
  onUndo: () => void;
  onRedo: () => void;
  onToggleEditor: () => void;
  onSave: () => Promise<{ ok: boolean; error?: string }>;
  onExport: () => void;
  onToggleSettings: () => void;
  onViewportChange: (mode: ViewportMode) => void;
  onUpdatePostIdentity: (payload: {
    title: string;
    slug: string;
  }) => Promise<{ ok: boolean; error?: string }>;
  onUpdateStatus: (status: 'draft' | 'pending' | 'private' | 'publish') => Promise<{
    ok: boolean;
    error?: string;
  }>;
};

export type ToolbarApi = {
  update: (next: Partial<ToolbarState>) => void;
};

const ICONS = {
  back: renderLucideIcon(ChevronLeft, {
    class: 'lucide lucide-chevron-left-icon lucide-chevron-left',
  }),
  wordpress:
    '<?xml version="1.0" encoding="utf-8"?><svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 122.88 122.88" style="enable-background:new 0 0 122.88 122.88" xml:space="preserve"><style type="text/css">.st0{fill:#e6e8ea;}</style><g><path class="st0" d="M61.44,0C27.51,0,0,27.51,0,61.44c0,33.93,27.51,61.44,61.44,61.44c33.93,0,61.44-27.51,61.44-61.44 C122.88,27.51,95.37,0,61.44,0L61.44,0z M106.37,36.88c0.22,1.63,0.34,3.38,0.34,5.26c0,5.19-0.97,11.03-3.89,18.34l-15.64,45.21 c15.22-8.87,25.46-25.37,25.46-44.25C112.64,52.54,110.37,44.17,106.37,36.88L106.37,36.88z M62.34,65.92l-15.36,44.64 c4.59,1.35,9.44,2.09,14.46,2.09c5.96,0,11.68-1.03,17-2.9c-0.14-0.22-0.26-0.45-0.37-0.71L62.34,65.92L62.34,65.92z M96,58.86 c0-6.33-2.27-10.71-4.22-14.12c-2.6-4.22-5.03-7.79-5.03-12.01c0-4.71,3.57-9.09,8.6-9.09c0.23,0,0.44,0.03,0.66,0.04 c-9.11-8.35-21.25-13.44-34.57-13.44c-17.89,0-33.62,9.18-42.78,23.08c1.2,0.04,2.33,0.06,3.3,0.06c5.35,0,13.65-0.65,13.65-0.65 c2.76-0.16,3.08,3.89,0.33,4.22c0,0-2.77,0.32-5.86,0.49l18.64,55.46l11.21-33.6l-7.98-21.86c-2.76-0.16-5.37-0.49-5.37-0.49 c-2.76-0.16-2.44-4.38,0.32-4.22c0,0,8.45,0.65,13.48,0.65c5.35,0,13.65-0.65,13.65-0.65c2.76-0.16,3.08,3.89,0.33,4.22 c0,0-2.78,0.32-5.86,0.49L87,92.47l5.28-16.74C94.63,68.42,96,63.24,96,58.86L96,58.86z M10.24,61.44 c0,20.27,11.78,37.78,28.86,46.08L14.67,40.6C11.83,46.97,10.24,54.01,10.24,61.44L10.24,61.44z M61.44,3.69 c7.8,0,15.36,1.53,22.48,4.54c3.42,1.45,6.72,3.24,9.81,5.32c3.06,2.07,5.94,4.44,8.55,7.05c2.61,2.61,4.99,5.49,7.05,8.55 c2.09,3.09,3.88,6.39,5.32,9.81c3.01,7.12,4.54,14.68,4.54,22.48c0,7.8-1.53,15.36-4.54,22.48c-1.45,3.42-3.24,6.72-5.32,9.81 c-2.07,3.06-4.44,5.94-7.05,8.55c-2.61,2.61-5.49,4.99-8.55,7.05c-3.09,2.09-6.39,3.88-9.81,5.32c-7.12,3.01-14.68,4.54-22.48,4.54 c-7.8,0-15.36-1.53-22.48-4.54c-3.42-1.45-6.72-3.24-9.81-5.32c-3.06-2.07-5.94-4.44-8.55-7.05c-2.61-2.61-4.99-5.49-7.05-8.55 c-2.09-3.09-3.88-6.39-5.32-9.81C5.21,76.8,3.69,69.24,3.69,61.44c0-7.8,1.53-15.36,4.54-22.48c1.45-3.42,3.24-6.72,5.32-9.81 c2.07-3.06,4.44-5.94,7.05-8.55c2.61-2.61,5.49-4.99,8.55-7.05c3.09-2.09,6.39-3.88,9.81-5.32C46.08,5.21,53.64,3.69,61.44,3.69 L61.44,3.69z"/></g></svg>',
  undo: renderLucideIcon(Undo2, {
    class: 'lucide lucide-undo2-icon lucide-undo-2',
  }),
  redo: renderLucideIcon(Redo2, {
    class: 'lucide lucide-redo2-icon lucide-redo-2',
  }),
  save: renderLucideIcon(Save, {
    class: 'lucide lucide-save-icon lucide-save',
  }),
  export: renderLucideIcon(Download, {
    class: 'lucide lucide-download-icon lucide-download',
  }),
  viewPost: renderLucideIcon(ExternalLink, {
    class: 'lucide lucide-external-link-icon lucide-external-link',
  }),
  panelClose: renderLucideIcon(PanelLeftClose, {
    class: 'lucide lucide-panel-left-close-icon lucide-panel-left-close',
  }),
  panelOpen: renderLucideIcon(PanelLeftOpen, {
    class: 'lucide lucide-panel-left-open-icon lucide-panel-left-open',
  }),
  panelBottomClose: renderLucideIcon(PanelBottomClose, {
    class: 'lucide lucide-panel-bottom-close-icon lucide-panel-bottom-close',
  }),
  panelBottomOpen: renderLucideIcon(PanelBottomOpen, {
    class: 'lucide lucide-panel-bottom-open-icon lucide-panel-bottom-open',
  }),
  desktop: renderLucideIcon(Monitor, {
    class: 'lucide lucide-monitor-icon lucide-monitor',
  }),
  tablet: renderLucideIcon(Tablet, {
    class: 'lucide lucide-tablet-icon lucide-tablet',
  }),
  mobile: renderLucideIcon(Smartphone, {
    class: 'lucide lucide-smartphone-icon lucide-smartphone',
  }),
  settings: renderLucideIcon(PanelRight, {
    class: 'lucide lucide-panel-right-icon lucide-panel-right',
  }),
  chevronDown: renderLucideIcon(ChevronDown, {
    class: 'lucide lucide-chevron-down-icon lucide-chevron-down',
  }),
  close: renderLucideIcon(X, {
    class: 'lucide lucide-x-icon lucide-x',
  }),
};

function IconLabel({ label, svg }: { label: string; svg: string }) {
  return (
    <Fragment>
      <span className="kayzart-btnIcon" dangerouslySetInnerHTML={{ __html: svg }} />
      <span className="kayzart-btnLabel">{label}</span>
    </Fragment>
  );
}

function Toolbar({
  backUrl,
  listUrl,
  canUndo,
  canRedo,
  editorCollapsed,
  compactEditorMode,
  settingsOpen,
  tailwindEnabled,
  hasUnsavedChanges,
  viewPostUrl,
  postStatus,
  postTitle,
  postSlug,
  viewportMode,
  onUndo,
  onRedo,
  onToggleEditor,
  onSave,
  onExport,
  onToggleSettings,
  onViewportChange,
  onUpdatePostIdentity,
  onUpdateStatus,
}: ToolbarState & ToolbarHandlers) {
  const [titleModalOpen, setTitleModalOpen] = useState(false);
  const [titleDraft, setTitleDraft] = useState('');
  const [slugDraft, setSlugDraft] = useState('');
  const [titleError, setTitleError] = useState('');
  const [titleSaving, setTitleSaving] = useState(false);
  const [saveMenuOpen, setSaveMenuOpen] = useState(false);
  const [statusSaving, setStatusSaving] = useState(false);
  const [externalToolbarActions, setExternalToolbarActions] = useState<ResolvedToolbarAction[]>(
    () => getExternalToolbarActions()
  );
  const toggleLabel = editorCollapsed
    ? __( 'Show code', 'kayzart-live-code-editor')
    : __( 'Hide code', 'kayzart-live-code-editor');
  const toggleIcon = compactEditorMode
    ? editorCollapsed
      ? ICONS.panelBottomClose
      : ICONS.panelBottomOpen
    : editorCollapsed
      ? ICONS.panelOpen
      : ICONS.panelClose;
  const isPublished = postStatus === 'publish' || postStatus === 'private';
  const isDraft = postStatus === 'draft' || postStatus === 'auto-draft';
  const viewPostLabel = isPublished ? __( 'View post', 'kayzart-live-code-editor') : __( 'Preview', 'kayzart-live-code-editor');
  const settingsTitle = settingsOpen ? '右パネルを閉じる' : '右パネル';
  const viewportDesktopLabel = __( 'Desktop', 'kayzart-live-code-editor');
  const viewportTabletLabel = __( 'Tablet', 'kayzart-live-code-editor');
  const viewportMobileLabel = __( 'Mobile', 'kayzart-live-code-editor');
  const isViewportDesktop = viewportMode === 'desktop';
  const isViewportTablet = viewportMode === 'tablet';
  const isViewportMobile = viewportMode === 'mobile';
  const previewLink = buildPreviewUrl(viewPostUrl);
  const targetUrl = isPublished ? viewPostUrl : previewLink;
  const showViewPost = Boolean(targetUrl);
  const showListLink = Boolean(listUrl);
  const backLabel = __( 'Back to WordPress', 'kayzart-live-code-editor');
  const showBackMenu = Boolean(backUrl) || showListLink;
  const resolvedTitle = postTitle?.trim() || __( 'Untitled', 'kayzart-live-code-editor');
  const draftSuffix = isDraft ? __( '(Draft)', 'kayzart-live-code-editor') : '';
  const titleText = draftSuffix ? `${resolvedTitle} ${draftSuffix}` : resolvedTitle;
  const titleTooltip = resolvedTitle;
  const normalizedStatus = postStatus === 'auto-draft' ? 'draft' : postStatus;
  const tailwindBadgeLabel = __( 'Tailwind CSS', 'kayzart-live-code-editor');
  const tailwindTooltip = __( 'Editing in Tailwind CSS mode', 'kayzart-live-code-editor');
  const listLabel = __( 'KayzArt pages', 'kayzart-live-code-editor');
  const saveLabel =
    normalizedStatus === 'draft'
      ? __( 'Save draft', 'kayzart-live-code-editor')
      : normalizedStatus === 'pending'
        ? __( 'Save for review', 'kayzart-live-code-editor')
        : normalizedStatus === 'private'
          ? __( 'Update as private', 'kayzart-live-code-editor')
          : __( 'Update', 'kayzart-live-code-editor');
  const exportLabel = __( 'Export', 'kayzart-live-code-editor');
  const statusActions = [
    { value: 'publish' as const, label: __( 'Publish', 'kayzart-live-code-editor') },
    { value: 'pending' as const, label: __( 'Move to review', 'kayzart-live-code-editor') },
    { value: 'private' as const, label: __( 'Make private', 'kayzart-live-code-editor') },
    { value: 'draft' as const, label: __( 'Revert to draft', 'kayzart-live-code-editor') },
  ];
  useEffect(() => {
    const syncActions = () => {
      setExternalToolbarActions(getExternalToolbarActions());
    };
    const unsubscribe = subscribeExternalToolbarActions(syncActions);
    syncActions();
    return unsubscribe;
  }, []);

  useEffect(() => {
    if (!titleModalOpen) {
      setTitleDraft(resolvedTitle);
      setSlugDraft(postSlug);
      setTitleError('');
    }
  }, [resolvedTitle, postSlug, titleModalOpen]);

  const openTitleModal = () => {
    setTitleDraft(resolvedTitle);
    setSlugDraft(postSlug);
    setTitleError('');
    setTitleModalOpen(true);
  };

  const closeTitleModal = () => {
    if (titleSaving) {
      return;
    }
    setTitleModalOpen(false);
  };

  const handleTitleSave = async () => {
    if (titleSaving) {
      return;
    }
    setTitleSaving(true);
    setTitleError('');
    const result = await onUpdatePostIdentity({
      title: titleDraft.trim(),
      slug: slugDraft.trim(),
    });
    if (result.ok) {
      setTitleModalOpen(false);
    } else {
      setTitleError(result.error || __( 'Update failed.', 'kayzart-live-code-editor'));
    }
    setTitleSaving(false);
  };

  const handleTitleKeyDown = (event: { key: string; preventDefault: () => void }) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      openTitleModal();
    }
  };

  const handleTitleInputKeyDown = (event: { key: string; preventDefault: () => void }) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      handleTitleSave();
    }
    if (event.key === 'Escape') {
      event.preventDefault();
      closeTitleModal();
    }
  };

  useEffect(() => {
    if (!saveMenuOpen) {
      return;
    }
    const handleDocClick = () => setSaveMenuOpen(false);
    document.addEventListener('click', handleDocClick);
    return () => {
      document.removeEventListener('click', handleDocClick);
    };
  }, [saveMenuOpen]);

  const toggleSaveMenu = (event: { stopPropagation: () => void }) => {
    event.stopPropagation();
    setSaveMenuOpen((prev) => !prev);
  };

  const handleStatusSelect = async (
    event: { stopPropagation: () => void },
    nextStatus: 'draft' | 'pending' | 'private' | 'publish'
  ) => {
    event.stopPropagation();
    if (statusSaving || nextStatus === normalizedStatus) {
      return;
    }
    setStatusSaving(true);
    if (hasUnsavedChanges) {
      const saveResult = await onSave();
      if (!saveResult.ok) {
        setStatusSaving(false);
        return;
      }
    }
    const result = await onUpdateStatus(nextStatus);
    setStatusSaving(false);
    if (result.ok) {
      setSaveMenuOpen(false);
    }
  };
  const beforeSettingsActions = externalToolbarActions.filter(
    (action) => action.placement === 'before-settings'
  );
  const afterSettingsActions = externalToolbarActions.filter(
    (action) => action.placement === 'after-settings'
  );
  const renderExternalToolbarAction = (action: ResolvedToolbarAction) => {
    const tooltip = action.tooltip ? action.tooltip.trim() : '';
    const className = action.className ? ` ${action.className}` : '';
    return (
      <button
        key={action.id}
        className={`kayzart-btn kayzart-btn-toolbarAction${className}`}
        type="button"
        onClick={() => action.onClick()}
        aria-label={action.label}
        data-tooltip={tooltip || undefined}
      >
        {action.icon ? (
          <span
            className="kayzart-btnIcon"
            dangerouslySetInnerHTML={{ __html: action.icon }}
          />
        ) : null}
        <span className="kayzart-btnLabel">{action.label}</span>
      </button>
    );
  };
  return (
    <Fragment>
      <div className="kayzart-toolbarGroup kayzart-toolbarLeft">
        <div className="kayzart-backMenu">
          <a
            className="kayzart-btn kayzart-btn-back"
            href={backUrl}
            aria-label={backLabel}
          >
            <span className="kayzart-btnIcon" dangerouslySetInnerHTML={{ __html: ICONS.back }} />
            <span
              className="kayzart-btnIcon kayzart-btnIcon-wordpress"
              dangerouslySetInnerHTML={{ __html: ICONS.wordpress }}
            />
          </a>
          {showBackMenu ? (
            <div className="kayzart-backMenuDropdown">
              <a className="kayzart-backMenuItem" href={backUrl}>
                {backLabel}
              </a>
              {showListLink ? (
                <a className="kayzart-backMenuItem" href={listUrl}>
                  {listLabel}
                </a>
              ) : null}
            </div>
          ) : null}
        </div>
        <button
          className={`kayzart-btn kayzart-btn-muted kayzart-btn-icon${canUndo ? ' is-active' : ''}`}
          type="button"
          onClick={onUndo}
          disabled={!canUndo}
          aria-label={__( 'Undo', 'kayzart-live-code-editor')}
          data-tooltip={__( 'Undo', 'kayzart-live-code-editor')}
        >
          <span className="kayzart-btnIcon" dangerouslySetInnerHTML={{ __html: ICONS.undo }} />
        </button>
        <button
          className={`kayzart-btn kayzart-btn-muted kayzart-btn-icon${canRedo ? ' is-active' : ''}`}
          type="button"
          onClick={onRedo}
          disabled={!canRedo}
          aria-label={__( 'Redo', 'kayzart-live-code-editor')}
          data-tooltip={__( 'Redo', 'kayzart-live-code-editor')}
        >
          <span className="kayzart-btnIcon" dangerouslySetInnerHTML={{ __html: ICONS.redo }} />
        </button>
      </div>
      <div className="kayzart-toolbarGroup kayzart-toolbarCenter">
        <div
          className="kayzart-toolbarTitle"
          data-tooltip={titleTooltip}
          aria-label={titleText}
          role="button"
          tabIndex={0}
          onClick={openTitleModal}
          onKeyDown={handleTitleKeyDown}
        >
          <span className="kayzart-toolbarTitleText">{resolvedTitle}</span>
          {draftSuffix ? (
            <span className="kayzart-toolbarTitleSuffix">{draftSuffix}</span>
          ) : null}
        </div>
        <div className="kayzart-toolbarCluster kayzart-toolbarCluster-viewports">
          <button
            className={`kayzart-btn kayzart-btn-icon kayzart-btn-viewport${isViewportDesktop ? ' is-active' : ''}`}
            type="button"
            aria-label={viewportDesktopLabel}
            aria-pressed={isViewportDesktop}
            data-tooltip={viewportDesktopLabel}
            onClick={() => onViewportChange('desktop')}
          >
            <span className="kayzart-btnIcon" dangerouslySetInnerHTML={{ __html: ICONS.desktop }} />
          </button>
          <button
            className={`kayzart-btn kayzart-btn-icon kayzart-btn-viewport${isViewportTablet ? ' is-active' : ''}`}
            type="button"
            aria-label={viewportTabletLabel}
            aria-pressed={isViewportTablet}
            data-tooltip={viewportTabletLabel}
            onClick={() => onViewportChange('tablet')}
          >
            <span className="kayzart-btnIcon" dangerouslySetInnerHTML={{ __html: ICONS.tablet }} />
          </button>
          <button
            className={`kayzart-btn kayzart-btn-icon kayzart-btn-viewport${isViewportMobile ? ' is-active' : ''}`}
            type="button"
            aria-label={viewportMobileLabel}
            aria-pressed={isViewportMobile}
            data-tooltip={viewportMobileLabel}
            onClick={() => onViewportChange('mobile')}
          >
            <span className="kayzart-btnIcon" dangerouslySetInnerHTML={{ __html: ICONS.mobile }} />
          </button>
        </div>
        <div className="kayzart-toolbarCluster kayzart-toolbarCluster-divider">
          <button
            className="kayzart-btn kayzart-btn-icon"
            type="button"
            onClick={onToggleEditor}
            aria-label={toggleLabel}
            data-tooltip={toggleLabel}
          >
            <span className="kayzart-btnIcon" dangerouslySetInnerHTML={{ __html: toggleIcon }} />
          </button>
        </div>
      </div>
      {titleModalOpen ? (
        <div className="kayzart-modal">
          <div className="kayzart-modalBackdrop" onClick={closeTitleModal} />
          <div className="kayzart-modalDialog" role="dialog" aria-modal="true">
            <div className="kayzart-modalHeader">
              <div className="kayzart-modalTitle">{__( 'Title', 'kayzart-live-code-editor')}</div>
              <button
                className="kayzart-modalClose"
                type="button"
                onClick={closeTitleModal}
                aria-label={__( 'Close', 'kayzart-live-code-editor')}
              >
                <span aria-hidden="true" dangerouslySetInnerHTML={{ __html: ICONS.close }} />
              </button>
            </div>
            <div className="kayzart-modalBody">
              <form
                className="kayzart-modalForm"
                onSubmit={(event) => {
                  event.preventDefault();
                  handleTitleSave();
                }}
              >
                <div className="kayzart-formGroup">
                  <label className="kayzart-formLabel" htmlFor="kayzart-title-modal-input">
                    {__( 'Title', 'kayzart-live-code-editor')}
                  </label>
                  <input
                    id="kayzart-title-modal-input"
                    className="kayzart-formInput"
                    type="text"
                    value={titleDraft}
                    onChange={(event) => setTitleDraft(event.target.value)}
                    onKeyDown={handleTitleInputKeyDown}
                    autoFocus
                  />
                </div>
                <div className="kayzart-formGroup">
                  <label className="kayzart-formLabel" htmlFor="kayzart-slug-modal-input">
                    {__( 'Slug', 'kayzart-live-code-editor')}
                  </label>
                  <input
                    id="kayzart-slug-modal-input"
                    className="kayzart-formInput"
                    type="text"
                    value={slugDraft}
                    onChange={(event) => setSlugDraft(event.target.value)}
                    onKeyDown={handleTitleInputKeyDown}
                  />
                </div>
                {titleError ? <div className="kayzart-modalError">{titleError}</div> : null}
                <div className="kayzart-modalActions">
                  <button
                    className="kayzart-btn kayzart-btn-secondary"
                    type="button"
                    onClick={closeTitleModal}
                  >
                    {__( 'Cancel', 'kayzart-live-code-editor')}
                  </button>
                  <button className="kayzart-btn kayzart-btn-primary" type="submit" disabled={titleSaving}>
                    {titleSaving ? __( 'Saving...', 'kayzart-live-code-editor') : __( 'Save', 'kayzart-live-code-editor')}
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      ) : null}
      <div className="kayzart-toolbarGroup kayzart-toolbarRight">
        <div className="kayzart-toolbarCluster kayzart-toolbarCluster-rightPrimary">
          <div className="kayzart-splitButton kayzart-splitButton-save">
            <button
              className={`kayzart-btn kayzart-btn-save kayzart-splitButton-main${hasUnsavedChanges ? ' is-unsaved' : ''}`}
              type="button"
              onClick={onSave}
              aria-label={saveLabel}
            >
              <IconLabel label={saveLabel} svg={ICONS.save} />
            </button>
            <button
              className={`kayzart-btn kayzart-btn-save kayzart-btn-icon kayzart-splitButton-toggle${hasUnsavedChanges ? ' is-unsaved' : ''}`}
              type="button"
              aria-haspopup="menu"
              aria-expanded={saveMenuOpen}
              aria-label={__( 'Save options', 'kayzart-live-code-editor')}
              data-tooltip={__( 'Save options', 'kayzart-live-code-editor')}
              onClick={toggleSaveMenu}
            >
              <span className="kayzart-btnIcon" dangerouslySetInnerHTML={{ __html: ICONS.chevronDown }} />
            </button>
            {saveMenuOpen ? (
              <div
                className="kayzart-splitMenu"
                role="menu"
                onClick={(event) => event.stopPropagation()}
              >
                <div className="kayzart-splitMenuTitle">
                  {/* translators: %s: current status label. */}
                  {sprintf(
                    __( 'Status: %s', 'kayzart-live-code-editor'),
                    normalizedStatus === 'draft'
                      ? __( 'Draft', 'kayzart-live-code-editor')
                      : normalizedStatus === 'pending'
                        ? __( 'Pending', 'kayzart-live-code-editor')
                        : normalizedStatus === 'private'
                          ? __( 'Private', 'kayzart-live-code-editor')
                          : normalizedStatus === 'future'
                            ? __( 'Scheduled', 'kayzart-live-code-editor')
                            : __( 'Published', 'kayzart-live-code-editor')
                  )}
                </div>
                <div className="kayzart-splitMenuList">
                  {statusActions
                    .filter((option) => option.value !== normalizedStatus)
                    .map((option) => (
                      <button
                        key={option.value}
                        className="kayzart-splitMenuItem"
                        type="button"
                        role="menuitem"
                        onClick={(event) => handleStatusSelect(event, option.value)}
                        disabled={statusSaving}
                      >
                        <span className="kayzart-splitMenuLabel">{option.label}</span>
                      </button>
                    ))}
                </div>
              </div>
            ) : null}
          </div>
        </div>
        <div className="kayzart-toolbarCluster kayzart-toolbarCluster-rightSecondary">
          {tailwindEnabled ? (
            <span
              className="kayzart-tailwindBadge"
              title={tailwindTooltip}
              aria-label={tailwindTooltip}
              data-tooltip={tailwindTooltip}
            >
              {tailwindBadgeLabel}
            </span>
          ) : null}
          {showViewPost ? (
            <a
              className="kayzart-btn kayzart-btn-icon kayzart-btn-view"
              href={targetUrl}
              target="_blank"
              rel="noopener noreferrer"
              aria-label={viewPostLabel}
              data-tooltip={viewPostLabel}
            >
              <span className="kayzart-btnIcon" dangerouslySetInnerHTML={{ __html: ICONS.viewPost }} />
            </a>
          ) : null}
          {beforeSettingsActions.map(renderExternalToolbarAction)}
          <button
            className={`kayzart-btn kayzart-btn-settings kayzart-btn-icon${settingsOpen ? ' is-active' : ''}`}
            type="button"
            onClick={onToggleSettings}
            aria-label={settingsTitle}
            aria-expanded={settingsOpen}
            aria-controls="kayzart-settings"
            data-tooltip={settingsTitle}
          >
            <span className="kayzart-btnIcon" dangerouslySetInnerHTML={{ __html: ICONS.settings }} />
          </button>
          {afterSettingsActions.map(renderExternalToolbarAction)}
          <button
            className="kayzart-btn kayzart-btn-export"
            type="button"
            onClick={onExport}
            aria-label={exportLabel}
            data-tooltip={exportLabel}
          >
            <IconLabel label={exportLabel} svg={ICONS.export} />
          </button>
        </div>
      </div>
    </Fragment>
  );
}

function buildPreviewUrl(url: string) {
  if (!url) {
    return '';
  }

  try {
    const previewUrl = new URL(url, window.location.origin);
    previewUrl.searchParams.set('preview', 'true');
    return previewUrl.toString();
  } catch {
    const hashIndex = url.indexOf('#');
    const hasQuery = url.includes('?');
    const suffix = (hasQuery ? '&' : '?') + 'preview=true';
    if (hashIndex === -1) {
      return url + suffix;
    }
    return url.slice(0, hashIndex) + suffix + url.slice(hashIndex);
  }
}

export function mountToolbar(
  container: HTMLElement,
  initialState: ToolbarState,
  handlers: ToolbarHandlers
): ToolbarApi {
  let state: ToolbarState = { ...initialState };
  const root = typeof createRoot === 'function' ? createRoot(container) : null;
  const doRender = () => {
    const node = <Toolbar {...state} {...handlers} />;
    if (root) {
      root.render(node);
    } else {
      render(node, container);
    }
  };

  doRender();

  return {
    update(next: Partial<ToolbarState>) {
      state = { ...state, ...next };
      doRender();
    },
  };
}

