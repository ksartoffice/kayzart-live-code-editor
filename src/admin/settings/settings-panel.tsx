import { createElement, Fragment } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ExternalResource } from '../types/external-resource';

type SettingsPanelProps = {
  canEditJs: boolean;
  templateMode: 'default' | 'standalone' | 'theme';
  defaultTemplateMode: 'standalone' | 'theme';
  onChangeTemplateMode: (mode: 'default' | 'standalone' | 'theme') => void;
  liveHighlightEnabled: boolean;
  onToggleLiveHighlight: (enabled: boolean) => void;
  externalScripts: ExternalResource[];
  onChangeExternalScripts: (scripts: ExternalResource[]) => void;
  onCommitExternalScripts: (scripts: ExternalResource[]) => void;
  externalScriptsMax: number;
  externalStyles: ExternalResource[];
  onChangeExternalStyles: (styles: ExternalResource[]) => void;
  onCommitExternalStyles: (styles: ExternalResource[]) => void;
  externalStylesMax: number;
  disabled?: boolean;
  externalScriptsError?: string;
  externalStylesError?: string;
};

export function SettingsPanel({
  canEditJs,
  templateMode,
  defaultTemplateMode,
  onChangeTemplateMode,
  liveHighlightEnabled,
  onToggleLiveHighlight,
  externalScripts,
  onChangeExternalScripts,
  onCommitExternalScripts,
  externalScriptsMax,
  externalStyles,
  onChangeExternalStyles,
  onCommitExternalStyles,
  externalStylesMax,
  disabled = false,
  externalScriptsError,
  externalStylesError,
}: SettingsPanelProps) {
  const canAddScript = !disabled && externalScripts.length < externalScriptsMax;
  const hasScripts = externalScripts.length > 0;
  const canAddStyle = !disabled && externalStyles.length < externalStylesMax;
  const hasStyles = externalStyles.length > 0;
  const templateModeLabels: Record<'standalone' | 'theme', string> = {
    standalone: __( 'Standalone', 'kayzart-live-code-editor'),
    theme: __( 'Theme', 'kayzart-live-code-editor'),
  };
  const resolvedDefaultTemplateMode =
    templateModeLabels[defaultTemplateMode] || templateModeLabels.standalone;
  const templateHelp =
    templateMode === 'default'
      ? __( 'Use default follows the default template mode from plugin settings.', 'kayzart-live-code-editor')
      : templateMode === 'standalone'
        ? __( 'Standalone hides the theme header and footer.', 'kayzart-live-code-editor')
        : __( 'Theme uses the active theme template.', 'kayzart-live-code-editor');

  const updateResourceUrl = (
    list: ExternalResource[],
    index: number,
    value: string
  ): ExternalResource[] =>
    list.map((entry, idx) => (idx === index ? { ...entry, url: value } : entry));

  const updateResourceAttr = (
    list: ExternalResource[],
    index: number,
    oldKey: string,
    nextKey: string,
    value: string
  ): ExternalResource[] =>
    list.map((entry, idx) => {
      if (idx !== index) {
        return entry;
      }
      const attrs = { ...entry.attrs };
      if (oldKey) {
        delete attrs[oldKey];
      }
      const key = nextKey.trim().toLowerCase();
      if (key) {
        attrs[key] = value;
      }
      return { ...entry, attrs };
    });

  const removeResourceAttr = (
    list: ExternalResource[],
    index: number,
    key: string
  ): ExternalResource[] =>
    list.map((entry, idx) => {
      if (idx !== index) {
        return entry;
      }
      const attrs = { ...entry.attrs };
      delete attrs[key];
      return { ...entry, attrs };
    });

  const addResourceAttr = (list: ExternalResource[], index: number): ExternalResource[] =>
    list.map((entry, idx) => {
      if (idx !== index) {
        return entry;
      }
      const attrs = { ...entry.attrs };
      let suffix = 1;
      let key = 'data-attr';
      while (Object.prototype.hasOwnProperty.call(attrs, key)) {
        suffix += 1;
        key = `data-attr-${suffix}`;
      }
      attrs[key] = '';
      return { ...entry, attrs };
    });

  const updateScriptAt = (index: number, value: string, commit: boolean) => {
    const next = updateResourceUrl(externalScripts, index, value);
    if (commit) {
      onCommitExternalScripts(next);
    } else {
      onChangeExternalScripts(next);
    }
  };

  const handleAddScript = () => {
    if (!canAddScript) return;
    onChangeExternalScripts([...externalScripts, { url: '', attrs: {} }]);
  };

  const handleRemoveScript = (index: number) => {
    if (disabled) return;
    const next = externalScripts.filter((_, idx) => idx !== index);
    onChangeExternalScripts(next);
    onCommitExternalScripts(next);
  };

  const updateStyleAt = (index: number, value: string, commit: boolean) => {
    const next = updateResourceUrl(externalStyles, index, value);
    if (commit) {
      onCommitExternalStyles(next);
    } else {
      onChangeExternalStyles(next);
    }
  };

  const handleAddStyle = () => {
    if (!canAddStyle) return;
    onChangeExternalStyles([...externalStyles, { url: '', attrs: {} }]);
  };

  const handleRemoveStyle = (index: number) => {
    if (disabled) return;
    const next = externalStyles.filter((_, idx) => idx !== index);
    onChangeExternalStyles(next);
    onCommitExternalStyles(next);
  };

  const renderAttrs = (
    list: ExternalResource[],
    index: number,
    onChange: (next: ExternalResource[]) => void,
    onCommit: (next: ExternalResource[]) => void
  ) => {
    const attrs = Object.entries(list[index].attrs || {});
    return (
      <details className="kayzart-settingsAttrs">
        <summary>{__('Attributes', 'kayzart-live-code-editor')}</summary>
        {attrs.length ? (
          <div className="kayzart-settingsAttrList">
            {attrs.map(([key, value]) => (
              <div className="kayzart-settingsAttrRow" key={key}>
                <input
                  type="text"
                  className="kayzart-formInput kayzart-settingsAttrKey"
                  value={key}
                  onChange={(event) =>
                    onChange(updateResourceAttr(list, index, key, event.target.value, String(value)))
                  }
                  onBlur={(event) =>
                    onCommit(updateResourceAttr(list, index, key, event.target.value, String(value)))
                  }
                  disabled={disabled}
                  aria-label={__('Attribute name', 'kayzart-live-code-editor')}
                />
                <input
                  type="text"
                  className="kayzart-formInput kayzart-settingsAttrValue"
                  value={value === true ? '' : String(value)}
                  placeholder={value === true ? __('boolean', 'kayzart-live-code-editor') : ''}
                  onChange={(event) =>
                    onChange(updateResourceAttr(list, index, key, key, event.target.value))
                  }
                  onBlur={(event) =>
                    onCommit(updateResourceAttr(list, index, key, key, event.target.value))
                  }
                  disabled={disabled}
                  aria-label={__('Attribute value', 'kayzart-live-code-editor')}
                />
                <button
                  className="kayzart-btn kayzart-btn-danger kayzart-settingsAttrButton"
                  type="button"
                  onClick={() => {
                    const next = removeResourceAttr(list, index, key);
                    onChange(next);
                    onCommit(next);
                  }}
                  disabled={disabled}
                  aria-label={__('Remove attribute', 'kayzart-live-code-editor')}
                >
                  {__('Remove', 'kayzart-live-code-editor')}
                </button>
              </div>
            ))}
          </div>
        ) : null}
        <button
          className="kayzart-btn kayzart-btn-secondary kayzart-settingsAttrAdd"
          type="button"
          onClick={() => onChange(addResourceAttr(list, index))}
          disabled={disabled}
        >
          {`+ ${__('Add attribute', 'kayzart-live-code-editor')}`}
        </button>
      </details>
    );
  };

  return (
    <Fragment>
      <div className="kayzart-settingsSection">
        <div className="kayzart-settingsSectionTitle">{__( 'Page template', 'kayzart-live-code-editor')}</div>
        <div className="kayzart-settingsItem">
          <select
            className="kayzart-formSelect"
            value={templateMode}
            onChange={(event) =>
              onChangeTemplateMode(
                event.target.value as 'default' | 'standalone' | 'theme'
              )
            }
            aria-label={__( 'Template mode', 'kayzart-live-code-editor')}
            disabled={disabled}
          >
            <option value="default">
              {sprintf(__( 'Use default (%s)', 'kayzart-live-code-editor'), resolvedDefaultTemplateMode)}
            </option>
            <option value="standalone">{templateModeLabels.standalone}</option>
            <option value="theme">{templateModeLabels.theme}</option>
          </select>
        </div>
        {templateHelp ? <div className="kayzart-settingsHelp">{templateHelp}</div> : null}
        <div className="kayzart-settingsHelp">
          {__(
            'Standalone reflects body attributes directly. Theme can add only class values to body_class; some body attributes may not be reflected in theme display. テーマ表示では、body属性の一部が反映されない場合があります。',
            'kayzart-live-code-editor'
          )}
        </div>
      </div>

      <div className="kayzart-settingsSection">
        <div className="kayzart-settingsSectionTitle">
          {__( 'External resource settings', 'kayzart-live-code-editor')}
        </div>
        <div className="kayzart-settingsHelp">
          {__(
            'These files are requested from third-party servers in preview and front-end output. Add only trusted URLs.', 'kayzart-live-code-editor')}
        </div>
        {canEditJs ? (
          <Fragment>
            <div className="kayzart-settingsItemLabel">{__( 'External scripts', 'kayzart-live-code-editor')}</div>
            {hasScripts ? (
              <div className="kayzart-settingsScriptList">
                {externalScripts.map((scriptResource, index) => (
                  <div className="kayzart-settingsScriptRow" key={`script-${index}`}>
                    <input
                      type="url"
                      className="kayzart-formInput kayzart-settingsScriptInput"
                      placeholder={__( 'https://example.com/script.js', 'kayzart-live-code-editor')}
                      value={scriptResource.url}
                      onChange={(event) => updateScriptAt(index, event.target.value, false)}
                      onBlur={(event) => updateScriptAt(index, event.target.value, true)}
                      onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                          event.preventDefault();
                          updateScriptAt(index, (event.target as HTMLInputElement).value, true);
                        }
                      }}
                      disabled={disabled}
                    />
                    <button
                      className="kayzart-btn kayzart-btn-danger kayzart-settingsScriptButton"
                      type="button"
                      onClick={() => handleRemoveScript(index)}
                      disabled={disabled}
                      aria-label={__( 'Remove external script', 'kayzart-live-code-editor')}
                    >
                      {__( 'Remove', 'kayzart-live-code-editor')}
                    </button>
                    {renderAttrs(
                      externalScripts,
                      index,
                      onChangeExternalScripts,
                      onCommitExternalScripts
                    )}
                  </div>
                ))}
                <button
                  className="kayzart-btn kayzart-btn-secondary kayzart-settingsScriptAdd"
                  type="button"
                  onClick={handleAddScript}
                  disabled={!canAddScript}
                  aria-label={__( 'Add external script', 'kayzart-live-code-editor')}
                >
                  {`+ ${__( 'Add', 'kayzart-live-code-editor')}`}
                </button>
              </div>
            ) : (
              <button
                className="kayzart-btn kayzart-btn-secondary"
                type="button"
                onClick={handleAddScript}
                disabled={!canAddScript}
              >
                {__( 'Add external script', 'kayzart-live-code-editor')}
              </button>
            )}
            <div className="kayzart-settingsHelp">
              {/* translators: %d: maximum number of items. */}
              {sprintf(
                __(
                  'Only URLs starting with https:// are allowed. You can add up to %d items.', 'kayzart-live-code-editor'),
                externalScriptsMax
              )}
            </div>
            {externalScriptsError ? (
              <div className="kayzart-settingsError">{externalScriptsError}</div>
            ) : null}
          </Fragment>
        ) : null}
        <div className="kayzart-settingsItemLabel">{__( 'External styles', 'kayzart-live-code-editor')}</div>
        {hasStyles ? (
          <div className="kayzart-settingsScriptList">
            {externalStyles.map((styleResource, index) => (
              <div className="kayzart-settingsScriptRow" key={`style-${index}`}>
                <input
                  type="url"
                  className="kayzart-formInput kayzart-settingsScriptInput"
                  placeholder={__( 'https://example.com/style.css', 'kayzart-live-code-editor')}
                  value={styleResource.url}
                  onChange={(event) => updateStyleAt(index, event.target.value, false)}
                  onBlur={(event) => updateStyleAt(index, event.target.value, true)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                      event.preventDefault();
                      updateStyleAt(index, (event.target as HTMLInputElement).value, true);
                    }
                  }}
                  disabled={disabled}
                />
                <button
                  className="kayzart-btn kayzart-btn-danger kayzart-settingsScriptButton"
                  type="button"
                  onClick={() => handleRemoveStyle(index)}
                  disabled={disabled}
                  aria-label={__( 'Remove external style', 'kayzart-live-code-editor')}
                >
                  {__( 'Remove', 'kayzart-live-code-editor')}
                </button>
                {renderAttrs(
                  externalStyles,
                  index,
                  onChangeExternalStyles,
                  onCommitExternalStyles
                )}
              </div>
            ))}
            <button
              className="kayzart-btn kayzart-btn-secondary kayzart-settingsScriptAdd"
              type="button"
              onClick={handleAddStyle}
              disabled={!canAddStyle}
              aria-label={__( 'Add external style', 'kayzart-live-code-editor')}
            >
              {`+ ${__( 'Add', 'kayzart-live-code-editor')}`}
            </button>
          </div>
        ) : (
          <button
            className="kayzart-btn kayzart-btn-secondary"
            type="button"
            onClick={handleAddStyle}
            disabled={!canAddStyle}
          >
            {__( 'Add external style', 'kayzart-live-code-editor')}
          </button>
        )}
        <div className="kayzart-settingsHelp">
          {/* translators: %d: maximum number of items. */}
          {sprintf(
            __( 'Only URLs starting with https:// are allowed. You can add up to %d items.', 'kayzart-live-code-editor'),
            externalStylesMax
          )}
        </div>
        {externalStylesError ? (
          <div className="kayzart-settingsError">{externalStylesError}</div>
        ) : null}
      </div>

      <div className="kayzart-settingsSection">
        <div className="kayzart-settingsSectionTitle">
          {__( 'Display settings', 'kayzart-live-code-editor')}
        </div>
        <div className="kayzart-settingsItem kayzart-settingsToggle">
          <div className="kayzart-settingsItemLabel">
            {__( 'Enable live edit highlight', 'kayzart-live-code-editor')}
          </div>
          <label className="kayzart-toggle">
            <input
              type="checkbox"
              checked={liveHighlightEnabled}
              aria-label={__( 'Enable live edit highlight', 'kayzart-live-code-editor')}
              onChange={(event) => onToggleLiveHighlight(event.target.checked)}
            />
            <span className="kayzart-toggleTrack" aria-hidden="true" />
          </label>
        </div>
      </div>
    </Fragment>
  );
}


