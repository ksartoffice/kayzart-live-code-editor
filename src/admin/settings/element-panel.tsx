import { createElement, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { isSafeEditableElementHtml } from '../element-text';

export type ElementPanelAttribute = {
  name: string;
  value: string;
};

export type ElementPanelApi = {
  subscribeSelection: (listener: (lcId: string | null) => void) => () => void;
  subscribeContentChange: (listener: () => void) => () => void;
  getElementText: (lcId: string) => string | null;
  updateElementText: (lcId: string, text: string) => boolean;
  getElementAttributes?: (lcId: string) => ElementPanelAttribute[] | null;
  updateElementAttributes?: (lcId: string, attributes: ElementPanelAttribute[]) => boolean;
};

type ElementPanelProps = {
  api?: ElementPanelApi;
};

const LIVE_TEXT_COMMIT_DELAY_MS = 250;

export function ElementPanel({ api }: ElementPanelProps) {
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [value, setValue] = useState('');
  const [attributes, setAttributes] = useState<ElementPanelAttribute[]>([]);
  const [isVisible, setIsVisible] = useState(false);
  const [hasText, setHasText] = useState(false);
  const [hasUnsafeDraft, setHasUnsafeDraft] = useState(false);
  const valueRef = useRef('');
  const committedValueRef = useRef('');
  const isFocusedRef = useRef(false);
  const fieldId = 'kayzart-elements-text';

  const setDraftValue = useCallback((nextValue: string) => {
    valueRef.current = nextValue;
    setValue(nextValue);
  }, []);

  const setCommittedValue = useCallback((nextValue: string) => {
    committedValueRef.current = nextValue;
  }, []);

  const refreshElement = useCallback(() => {
    if (!selectedId) {
      setDraftValue('');
      setCommittedValue('');
      setAttributes([]);
      setIsVisible(false);
      setHasText(false);
      setHasUnsafeDraft(false);
      return;
    }
    const nextText = api?.getElementText ? api.getElementText(selectedId) : null;
    const nextAttributes = api?.getElementAttributes
      ? api.getElementAttributes(selectedId)
      : null;
    const hasNextText = typeof nextText === 'string';
    const hasNextAttributes = Array.isArray(nextAttributes);
    setIsVisible(hasNextText || hasNextAttributes);
    setHasText(hasNextText);
    if (hasNextText) {
      const isDirtyFocusedDraft =
        isFocusedRef.current && valueRef.current !== committedValueRef.current;
      setCommittedValue(nextText);
      if (!isDirtyFocusedDraft) {
        setDraftValue(nextText);
        setHasUnsafeDraft(false);
      }
    } else {
      setDraftValue('');
      setCommittedValue('');
      setHasUnsafeDraft(false);
    }
    setAttributes(hasNextAttributes ? nextAttributes : []);
  }, [api, selectedId, setCommittedValue, setDraftValue]);

  useEffect(() => {
    if (!api?.subscribeSelection) {
      return;
    }
    return api.subscribeSelection((lcId) => {
      isFocusedRef.current = false;
      setHasUnsafeDraft(false);
      setSelectedId(lcId);
    });
  }, [api]);

  useEffect(() => {
    refreshElement();
  }, [refreshElement]);

  useEffect(() => {
    if (!api?.subscribeContentChange) {
      return;
    }
    return api.subscribeContentChange(() => {
      refreshElement();
    });
  }, [api, refreshElement]);

  const commitText = useCallback(
    (nextValue: string) => {
      if (!selectedId || !api?.updateElementText) {
        return false;
      }
      if (!isSafeEditableElementHtml(nextValue)) {
        setHasUnsafeDraft(nextValue !== committedValueRef.current);
        return false;
      }
      if (nextValue === committedValueRef.current) {
        setHasUnsafeDraft(false);
        return true;
      }
      const didUpdate = api.updateElementText(selectedId, nextValue);
      if (didUpdate) {
        setCommittedValue(nextValue);
        setHasUnsafeDraft(false);
      }
      return didUpdate;
    },
    [api, selectedId, setCommittedValue]
  );

  useEffect(() => {
    if (!hasText || !selectedId || value === committedValueRef.current) {
      setHasUnsafeDraft(false);
      return;
    }
    const timer = window.setTimeout(() => {
      commitText(valueRef.current);
    }, LIVE_TEXT_COMMIT_DELAY_MS);
    return () => {
      window.clearTimeout(timer);
    };
  }, [commitText, hasText, selectedId, value]);

  const handleChange = (event: { target: HTMLTextAreaElement }) => {
    const nextValue = event.target.value;
    setDraftValue(nextValue);
  };

  const handleBlur = () => {
    isFocusedRef.current = false;
    commitText(valueRef.current);
  };

  const handleFocus = () => {
    isFocusedRef.current = true;
  };

  const handleKeyDown = (event: { key: string; ctrlKey: boolean; metaKey: boolean; preventDefault: () => void }) => {
    if (event.key !== 'Enter' || (!event.ctrlKey && !event.metaKey)) {
      return;
    }
    event.preventDefault();
    commitText(valueRef.current);
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
          {__( 'Select an element in the preview to edit its content and attributes.', 'kayzart-live-code-editor')}
        </div>
      </div>
    );
  }

  return (
    <div className="kayzart-settingsSection" data-kayzart-panel="elements">
      <div className="kayzart-settingsSectionTitle">{__( 'Elements', 'kayzart-live-code-editor')}</div>
      {hasText ? (
        <div className="kayzart-formGroup">
          <label className="kayzart-formLabel" htmlFor={fieldId}>
            {__( 'Inner HTML', 'kayzart-live-code-editor')}
          </label>
          <textarea
            id={fieldId}
            className="kayzart-formInput"
            rows={4}
            value={value}
            onChange={handleChange}
            onBlur={handleBlur}
            onFocus={handleFocus}
            onKeyDown={handleKeyDown}
          />
          {hasUnsafeDraft ? (
            <div className="kayzart-settingsHelp">
              {__( 'Preview will update when the HTML is complete.', 'kayzart-live-code-editor')}
            </div>
          ) : null}
        </div>
      ) : null}
      <div className="kayzart-formGroup">
        <div className="kayzart-formLabel">{__( 'Attributes', 'kayzart-live-code-editor')}</div>
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
      </div>
    </div>
  );
}
