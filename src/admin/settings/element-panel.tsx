import { createElement, useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export type ElementPanelAttribute = {
  name: string;
  value: string;
};

export type ElementPanelTextSegment = {
  id: string;
  text: string;
  labelHint: string;
};

export type ElementPanelActionInfo = {
  kind: 'link' | 'button';
  tagName: string;
  href: string;
  targetBlank: boolean;
  rel: string;
  disabled: boolean;
};

type ElementPanelActionDraft = {
  href: string;
  targetBlank: boolean;
  disabled: boolean;
};

export type ElementPanelApi = {
  subscribeSelection: (listener: (lcId: string | null) => void) => () => void;
  subscribeContentChange: (listener: () => void) => () => void;
  getTextSegments?: (lcId: string) => ElementPanelTextSegment[];
  updateTextSegment?: (lcId: string, segmentId: string, text: string) => boolean;
  getElementActionInfo?: (lcId: string) => ElementPanelActionInfo | null;
  updateElementActionInfo?: (
    lcId: string,
    action: Partial<ElementPanelActionDraft>
  ) => boolean;
  getElementText?: (lcId: string) => string | null;
  updateElementText?: (lcId: string, text: string) => boolean;
  getElementAttributes?: (lcId: string) => ElementPanelAttribute[] | null;
  updateElementAttributes?: (lcId: string, attributes: ElementPanelAttribute[]) => boolean;
};

type ElementPanelProps = {
  api?: ElementPanelApi;
};

const LIVE_TEXT_COMMIT_DELAY_MS = 250;
const LIVE_ACTION_COMMIT_DELAY_MS = 250;

function translateSegmentLabel(label: string) {
  switch (label) {
    case 'Button text':
      return __( 'Button text', 'kayzart-live-code-editor');
    case 'Link text':
      return __( 'Link text', 'kayzart-live-code-editor');
    case 'Heading':
      return __( 'Heading', 'kayzart-live-code-editor');
    case 'Text':
      return __( 'Text', 'kayzart-live-code-editor');
    default:
      return label;
  }
}

function getSegmentLabel(segment: ElementPanelTextSegment, index: number, total: number) {
  const fallback = total > 1
    ? `${__( 'Text', 'kayzart-live-code-editor')} ${index + 1}`
    : __( 'Text', 'kayzart-live-code-editor');
  return segment.labelHint ? translateSegmentLabel(segment.labelHint) : fallback;
}

function adjustTextareaHeight(textarea: HTMLTextAreaElement | null) {
  if (!textarea) {
    return;
  }
  textarea.style.height = 'auto';
  textarea.style.height = `${textarea.scrollHeight}px`;
}

export function ElementPanel({ api }: ElementPanelProps) {
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [segments, setSegments] = useState<ElementPanelTextSegment[]>([]);
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [actionInfo, setActionInfo] = useState<ElementPanelActionInfo | null>(null);
  const [actionDraft, setActionDraft] = useState<ElementPanelActionDraft>({
    href: '',
    targetBlank: false,
    disabled: false,
  });
  const [attributes, setAttributes] = useState<ElementPanelAttribute[]>([]);
  const [isVisible, setIsVisible] = useState(false);
  const committedDraftsRef = useRef<Record<string, string>>({});
  const draftsRef = useRef<Record<string, string>>({});
  const focusedSegmentIdRef = useRef<string | null>(null);
  const textareaRefs = useRef<Record<string, HTMLTextAreaElement | null>>({});
  const committedActionDraftRef = useRef<ElementPanelActionDraft>({
    href: '',
    targetBlank: false,
    disabled: false,
  });
  const actionDraftRef = useRef<ElementPanelActionDraft>({
    href: '',
    targetBlank: false,
    disabled: false,
  });
  const focusedActionFieldRef = useRef<keyof ElementPanelActionDraft | null>(null);

  const selectedLabel = useMemo(() => {
    if (actionInfo?.kind === 'button') {
      return __( 'Selected: Button', 'kayzart-live-code-editor');
    }
    if (actionInfo?.kind === 'link') {
      return __( 'Selected: Link', 'kayzart-live-code-editor');
    }
    if (segments.some((segment) => segment.labelHint === 'Button text')) {
      return __( 'Selected: Button', 'kayzart-live-code-editor');
    }
    if (segments.some((segment) => segment.labelHint === 'Link text')) {
      return __( 'Selected: Link', 'kayzart-live-code-editor');
    }
    if (segments.some((segment) => segment.labelHint === 'Heading')) {
      return __( 'Selected: Heading', 'kayzart-live-code-editor');
    }
    if (segments.some((segment) => segment.labelHint === 'Text')) {
      return __( 'Selected: Text', 'kayzart-live-code-editor');
    }
    return __( 'Selected: Element', 'kayzart-live-code-editor');
  }, [actionInfo, segments]);

  const refreshElement = useCallback(() => {
    if (!selectedId) {
      setSegments([]);
      setDrafts({});
      draftsRef.current = {};
      committedDraftsRef.current = {};
      setActionInfo(null);
      setActionDraft({ href: '', targetBlank: false, disabled: false });
      actionDraftRef.current = { href: '', targetBlank: false, disabled: false };
      committedActionDraftRef.current = { href: '', targetBlank: false, disabled: false };
      setAttributes([]);
      setIsVisible(false);
      return;
    }

    const nextSegments = api?.getTextSegments ? api.getTextSegments(selectedId) : [];
    const nextActionInfo = api?.getElementActionInfo
      ? api.getElementActionInfo(selectedId)
      : null;
    const nextAttributes = api?.getElementAttributes
      ? api.getElementAttributes(selectedId)
      : null;
    const hasNextAttributes = Array.isArray(nextAttributes);
    const focusedSegmentId = focusedSegmentIdRef.current;
    const nextCommitted: Record<string, string> = {};
    const nextDrafts: Record<string, string> = {};

    nextSegments.forEach((segment) => {
      const previousCommitted = committedDraftsRef.current[segment.id] ?? '';
      const previousDraft = draftsRef.current[segment.id] ?? segment.text;
      const isDirtyFocusedDraft =
        focusedSegmentId === segment.id && previousDraft !== previousCommitted;
      nextCommitted[segment.id] = segment.text;
      nextDrafts[segment.id] = isDirtyFocusedDraft ? previousDraft : segment.text;
    });

    const nextCommittedActionDraft = {
      href: nextActionInfo?.href ?? '',
      targetBlank: Boolean(nextActionInfo?.targetBlank),
      disabled: Boolean(nextActionInfo?.disabled),
    };
    const previousActionDraft = actionDraftRef.current;
    const focusedActionField = focusedActionFieldRef.current;
    const nextActionDraft =
      focusedActionField &&
      previousActionDraft[focusedActionField] !==
        committedActionDraftRef.current[focusedActionField]
        ? {
            ...nextCommittedActionDraft,
            [focusedActionField]: previousActionDraft[focusedActionField],
          }
        : nextCommittedActionDraft;

    committedDraftsRef.current = nextCommitted;
    draftsRef.current = nextDrafts;
    committedActionDraftRef.current = nextCommittedActionDraft;
    actionDraftRef.current = nextActionDraft;
    setDrafts(nextDrafts);
    setSegments(nextSegments);
    setActionInfo(nextActionInfo);
    setActionDraft(nextActionDraft);
    setAttributes(hasNextAttributes ? nextAttributes : []);
    setIsVisible(nextSegments.length > 0 || Boolean(nextActionInfo) || hasNextAttributes);
  }, [api, selectedId]);

  useEffect(() => {
    if (!api?.subscribeSelection) {
      return;
    }
    return api.subscribeSelection((lcId) => {
      focusedSegmentIdRef.current = null;
      focusedActionFieldRef.current = null;
      setSelectedId(lcId);
    });
  }, [api]);

  useEffect(() => {
    refreshElement();
  }, [refreshElement]);

  useEffect(() => {
    segments.forEach((segment) => {
      adjustTextareaHeight(textareaRefs.current[segment.id] ?? null);
    });
  }, [drafts, segments]);

  useEffect(() => {
    if (!api?.subscribeContentChange) {
      return;
    }
    return api.subscribeContentChange(() => {
      refreshElement();
    });
  }, [api, refreshElement]);

  const commitSegment = useCallback(
    (segmentId: string, nextValue: string) => {
      if (!selectedId || !api?.updateTextSegment) {
        return false;
      }
      if (nextValue === committedDraftsRef.current[segmentId]) {
        return true;
      }
      const didUpdate = api.updateTextSegment(selectedId, segmentId, nextValue);
      if (didUpdate) {
        committedDraftsRef.current = {
          ...committedDraftsRef.current,
          [segmentId]: nextValue,
        };
      }
      return didUpdate;
    },
    [api, selectedId]
  );

  const commitActionDraft = useCallback(() => {
    if (!selectedId || !actionInfo || !api?.updateElementActionInfo) {
      return false;
    }
    const current = actionDraftRef.current;
    const committed = committedActionDraftRef.current;
    const changed: Partial<ElementPanelActionDraft> = {};
    if (actionInfo.tagName === 'a' && current.href !== committed.href) {
      changed.href = current.href;
    }
    if (actionInfo.tagName === 'a' && current.targetBlank !== committed.targetBlank) {
      changed.targetBlank = current.targetBlank;
    }
    if (actionInfo.tagName === 'button' && current.disabled !== committed.disabled) {
      changed.disabled = current.disabled;
    }
    if (Object.keys(changed).length === 0) {
      return true;
    }
    const didUpdate = api.updateElementActionInfo(selectedId, changed);
    if (didUpdate) {
      committedActionDraftRef.current = current;
    }
    return didUpdate;
  }, [actionInfo, api, selectedId]);

  useEffect(() => {
    const timers = segments
      .filter((segment) => drafts[segment.id] !== committedDraftsRef.current[segment.id])
      .map((segment) =>
        window.setTimeout(() => {
          commitSegment(segment.id, drafts[segment.id] ?? '');
        }, LIVE_TEXT_COMMIT_DELAY_MS)
      );
    return () => {
      timers.forEach((timer) => window.clearTimeout(timer));
    };
  }, [commitSegment, drafts, segments]);

  useEffect(() => {
    if (
      !actionInfo ||
      (actionDraft.href === committedActionDraftRef.current.href &&
        actionDraft.targetBlank === committedActionDraftRef.current.targetBlank &&
        actionDraft.disabled === committedActionDraftRef.current.disabled)
    ) {
      return;
    }
    const timer = window.setTimeout(() => {
      commitActionDraft();
    }, LIVE_ACTION_COMMIT_DELAY_MS);
    return () => {
      window.clearTimeout(timer);
    };
  }, [actionDraft, actionInfo, commitActionDraft]);

  const handleSegmentChange = (segmentId: string, value: string) => {
    const nextDrafts = {
      ...draftsRef.current,
      [segmentId]: value,
    };
    draftsRef.current = nextDrafts;
    setDrafts(nextDrafts);
    adjustTextareaHeight(textareaRefs.current[segmentId] ?? null);
  };

  const handleSegmentBlur = (segmentId: string) => {
    focusedSegmentIdRef.current = null;
    commitSegment(segmentId, drafts[segmentId] ?? '');
  };

  const handleSegmentFocus = (segmentId: string) => {
    focusedSegmentIdRef.current = segmentId;
  };

  const handleSegmentKeyDown = (
    segmentId: string,
    event: { key: string; ctrlKey: boolean; metaKey: boolean; preventDefault: () => void }
  ) => {
    if (event.key !== 'Enter' || (!event.ctrlKey && !event.metaKey)) {
      return;
    }
    event.preventDefault();
    commitSegment(segmentId, drafts[segmentId] ?? '');
  };

  const handleActionDraftChange = <Key extends keyof ElementPanelActionDraft>(
    key: Key,
    value: ElementPanelActionDraft[Key]
  ) => {
    const nextDraft = {
      ...actionDraftRef.current,
      [key]: value,
    };
    actionDraftRef.current = nextDraft;
    setActionDraft(nextDraft);
  };

  const handleActionBlur = () => {
    focusedActionFieldRef.current = null;
    commitActionDraft();
  };

  const commitAttributes = useCallback(
    (nextAttributes: ElementPanelAttribute[]) => {
      setAttributes(nextAttributes);
      if (!selectedId || !api?.updateElementAttributes) {
        return;
      }
      const payload = nextAttributes
        .map((attr) => ({ name: attr.name.trim(), value: attr.value }))
        .filter((attr) => attr.name !== '');
      api.updateElementAttributes(selectedId, payload);
    },
    [api, selectedId]
  );

  const handleAttributeNameChange = (index: number, name: string) => {
    const sanitized = name.replace(/[^A-Za-z0-9:_.-]/g, '');
    const next = attributes.map((attr, idx) =>
      idx === index ? { ...attr, name: sanitized } : attr
    );
    commitAttributes(next);
  };

  const handleAttributeValueChange = (index: number, value: string) => {
    const next = attributes.map((attr, idx) =>
      idx === index ? { ...attr, value } : attr
    );
    commitAttributes(next);
  };

  const handleRemoveAttribute = (index: number) => {
    const next = attributes.filter((_, idx) => idx !== index);
    commitAttributes(next);
  };

  const handleAddAttribute = () => {
    setAttributes((prev) => [...prev, { name: '', value: '' }]);
  };

  if (!isVisible) {
    return (
      <div className="kayzart-settingsSection" data-kayzart-panel="elements">
        <div className="kayzart-settingsSectionTitle">{__( 'Elements', 'kayzart-live-code-editor')}</div>
        <div className="kayzart-settingsHelp">
          {__( 'Select an element in the preview to edit its text and settings.', 'kayzart-live-code-editor')}
        </div>
      </div>
    );
  }

  return (
    <div className="kayzart-settingsSection" data-kayzart-panel="elements">
      <div className="kayzart-settingsSectionTitle">{__( 'Elements', 'kayzart-live-code-editor')}</div>
      <div className="kayzart-settingsHelp">{selectedLabel}</div>
      {actionInfo ? (
        <div className="kayzart-formGroup kayzart-elementsActionGroup">
          <div className="kayzart-formLabel">
            {actionInfo.kind === 'button'
              ? __( 'Button', 'kayzart-live-code-editor')
              : __( 'Link', 'kayzart-live-code-editor')}
          </div>
          {actionInfo.tagName === 'a' ? (
            <div className="kayzart-formGroup">
              <label className="kayzart-formLabel" htmlFor="kayzart-elements-link-destination">
                {__( 'Link destination', 'kayzart-live-code-editor')}
              </label>
              <input
                id="kayzart-elements-link-destination"
                type="text"
                className="kayzart-formInput"
                value={actionDraft.href}
                onChange={(event) => handleActionDraftChange('href', event.target.value)}
                onFocus={() => {
                  focusedActionFieldRef.current = 'href';
                }}
                onBlur={handleActionBlur}
              />
              <label className="kayzart-elementsToggle">
                <input
                  type="checkbox"
                  checked={actionDraft.targetBlank}
                  onChange={(event) =>
                    handleActionDraftChange('targetBlank', event.target.checked)
                  }
                  onFocus={() => {
                    focusedActionFieldRef.current = 'targetBlank';
                  }}
                  onBlur={handleActionBlur}
                />
                <span>{__( 'Open in new tab', 'kayzart-live-code-editor')}</span>
              </label>
            </div>
          ) : null}
          {actionInfo.tagName === 'button' ? (
            <label className="kayzart-elementsToggle">
              <input
                type="checkbox"
                checked={actionDraft.disabled}
                onChange={(event) => handleActionDraftChange('disabled', event.target.checked)}
                onFocus={() => {
                  focusedActionFieldRef.current = 'disabled';
                }}
                onBlur={handleActionBlur}
              />
              <span>{__( 'Disabled', 'kayzart-live-code-editor')}</span>
            </label>
          ) : null}
        </div>
      ) : null}
      {segments.length > 0 ? (
        <div className="kayzart-formGroup">
          {segments.map((segment, index) => {
            const fieldId = `kayzart-elements-text-${segment.id}`;
            return (
              <div className="kayzart-formGroup" key={segment.id}>
                <label className="kayzart-formLabel" htmlFor={fieldId}>
                  {getSegmentLabel(segment, index, segments.length)}
                </label>
                <textarea
                  id={fieldId}
                  ref={(node) => {
                    textareaRefs.current[segment.id] = node;
                    adjustTextareaHeight(node);
                  }}
                  className="kayzart-formInput kayzart-elementsTextInput"
                  rows={2}
                  value={drafts[segment.id] ?? ''}
                  onChange={(event) => handleSegmentChange(segment.id, event.target.value)}
                  onBlur={() => handleSegmentBlur(segment.id)}
                  onFocus={() => handleSegmentFocus(segment.id)}
                  onKeyDown={(event) => handleSegmentKeyDown(segment.id, event)}
                />
              </div>
            );
          })}
          <div className="kayzart-settingsHelp">
            {__( 'Only text changes here. Existing HTML, icons, and styles are preserved.', 'kayzart-live-code-editor')}
          </div>
        </div>
      ) : null}

      <details className="kayzart-formGroup">
        <summary className="kayzart-formLabel">{__( 'Advanced settings', 'kayzart-live-code-editor')}</summary>
        <div className="kayzart-settingsScriptList">
          {attributes.map((attr, index) => (
            <div className="kayzart-settingsScriptRow kayzart-elementsAttrRow" key={`attr-${index}`}>
              <input
                type="text"
                className="kayzart-formInput kayzart-settingsAttrNameInput"
                placeholder={__( 'Attribute name', 'kayzart-live-code-editor')}
                value={attr.name}
                onChange={(event) => handleAttributeNameChange(index, event.target.value)}
              />
              <input
                type="text"
                className="kayzart-formInput kayzart-settingsScriptInput kayzart-settingsAttrValueInput"
                placeholder={__( 'Value', 'kayzart-live-code-editor')}
                value={attr.value}
                onChange={(event) => handleAttributeValueChange(index, event.target.value)}
              />
              <button
                className="kayzart-btn kayzart-btn-danger kayzart-settingsScriptButton"
                type="button"
                onClick={() => handleRemoveAttribute(index)}
                aria-label={__( 'Remove attribute', 'kayzart-live-code-editor')}
              >
                {__( 'Remove', 'kayzart-live-code-editor')}
              </button>
            </div>
          ))}
          <button
            className="kayzart-btn kayzart-btn-secondary kayzart-settingsScriptAdd"
            type="button"
            onClick={handleAddAttribute}
          >
            {__( 'Add attribute', 'kayzart-live-code-editor')}
          </button>
        </div>
      </details>
    </div>
  );
}
