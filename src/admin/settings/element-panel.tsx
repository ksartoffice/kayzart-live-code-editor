import { createElement, useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

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

export function ElementPanel({ api }: ElementPanelProps) {
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [value, setValue] = useState('');
  const [attributes, setAttributes] = useState<ElementPanelAttribute[]>([]);
  const [isVisible, setIsVisible] = useState(false);
  const [hasText, setHasText] = useState(false);
  const fieldId = 'kayzart-elements-text';

  const refreshElement = useCallback(() => {
    if (!selectedId) {
      setValue('');
      setAttributes([]);
      setIsVisible(false);
      setHasText(false);
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
      setValue((prev) => (prev === nextText ? prev : nextText));
    } else {
      setValue('');
    }
    setAttributes(hasNextAttributes ? nextAttributes : []);
  }, [api, selectedId]);

  useEffect(() => {
    if (!api?.subscribeSelection) {
      return;
    }
    return api.subscribeSelection((lcId) => {
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

  const handleChange = (event: { target: HTMLTextAreaElement }) => {
    const nextValue = event.target.value;
    setValue(nextValue);
    if (selectedId && api?.updateElementText) {
      api.updateElementText(selectedId, nextValue);
    }
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
      <div className="kayzart-settingsSection">
        <div className="kayzart-settingsSectionTitle">{__( 'Elements', 'kayzart-live-code-editor')}</div>
        <div className="kayzart-settingsHelp">
          {__( 'Select an element in the preview to edit its content and attributes.', 'kayzart-live-code-editor')}
        </div>
      </div>
    );
  }

  return (
    <div className="kayzart-settingsSection">
      <div className="kayzart-settingsSectionTitle">{__( 'Elements', 'kayzart-live-code-editor')}</div>
      {hasText ? (
        <div className="kayzart-formGroup">
          <label className="kayzart-formLabel" htmlFor={fieldId}>
            {__( 'Text', 'kayzart-live-code-editor')}
          </label>
          <textarea
            id={fieldId}
            className="kayzart-formInput"
            rows={4}
            value={value}
            onChange={handleChange}
          />
        </div>
      ) : null}
      <div className="kayzart-formGroup">
        <div className="kayzart-formLabel">{__( 'Attributes', 'kayzart-live-code-editor')}</div>
        <div className="kayzart-settingsScriptList">
          {attributes.map((attr, index) => (
            <div className="kayzart-settingsScriptRow" key={`attr-${index}`}>
              <input
                type="text"
                className="kayzart-formInput kayzart-settingsAttrNameInput"
                placeholder={__( 'Attribute name', 'kayzart-live-code-editor')}
                value={attr.name}
                onChange={(event) => handleAttributeNameChange(index, event.target.value)}
              />
              <input
                type="text"
                className="kayzart-formInput kayzart-settingsScriptInput"
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

