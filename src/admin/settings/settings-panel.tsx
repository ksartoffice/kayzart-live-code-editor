import { createElement, Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

type SettingsPanelProps = {
  postId: number;
  canEditJs: boolean;
  templateMode: 'default' | 'standalone' | 'frame' | 'theme';
  defaultTemplateMode: 'standalone' | 'frame' | 'theme';
  onChangeTemplateMode: (mode: 'default' | 'standalone' | 'frame' | 'theme') => void;
  shadowDomEnabled: boolean;
  onToggleShadowDom: (enabled: boolean) => void;
  shortcodeEnabled: boolean;
  onToggleShortcode: (enabled: boolean) => void;
  singlePageEnabled: boolean;
  onToggleSinglePage: (enabled: boolean) => void;
  liveHighlightEnabled: boolean;
  onToggleLiveHighlight: (enabled: boolean) => void;
  externalScripts: string[];
  onChangeExternalScripts: (scripts: string[]) => void;
  onCommitExternalScripts: (scripts: string[]) => void;
  externalScriptsMax: number;
  externalStyles: string[];
  onChangeExternalStyles: (styles: string[]) => void;
  onCommitExternalStyles: (styles: string[]) => void;
  externalStylesMax: number;
  disabled?: boolean;
  error?: string;
  externalScriptsError?: string;
  externalStylesError?: string;
};

export function SettingsPanel({
  postId,
  canEditJs,
  templateMode,
  defaultTemplateMode,
  onChangeTemplateMode,
  shadowDomEnabled,
  onToggleShadowDom,
  shortcodeEnabled,
  onToggleShortcode,
  singlePageEnabled,
  onToggleSinglePage,
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
  error,
  externalScriptsError,
  externalStylesError,
}: SettingsPanelProps) {
  const [copyState, setCopyState] = useState<'idle' | 'copied' | 'error'>('idle');
  const copyTimeoutRef = useRef<number | null>(null);
  const shortcodeInputRef = useRef<HTMLInputElement | null>(null);
  const canAddScript = !disabled && externalScripts.length < externalScriptsMax;
  const hasScripts = externalScripts.length > 0;
  const canAddStyle = !disabled && externalStyles.length < externalStylesMax;
  const hasStyles = externalStyles.length > 0;
  const shortcodeText = `[kayzart post_id="${postId}"]`;
  const templateModeLabels: Record<'standalone' | 'frame' | 'theme', string> = {
    standalone: __( 'Standalone', 'kayzart-live-code-editor'),
    frame: __( 'Frame', 'kayzart-live-code-editor'),
    theme: __( 'Theme', 'kayzart-live-code-editor'),
  };
  const resolvedDefaultTemplateMode =
    templateModeLabels[defaultTemplateMode] || templateModeLabels.theme;
  const templateHelp =
    templateMode === 'default'
      ? __( 'Use default follows the default template mode from plugin settings.', 'kayzart-live-code-editor')
      : templateMode === 'standalone'
        ? __( 'Standalone hides the theme header and footer.', 'kayzart-live-code-editor')
        : templateMode === 'frame'
          ? __( 'Frame uses the theme header and footer.', 'kayzart-live-code-editor')
          : __( 'Theme uses the active theme template.', 'kayzart-live-code-editor');

  useEffect(() => {
    return () => {
      if (copyTimeoutRef.current) {
        window.clearTimeout(copyTimeoutRef.current);
      }
    };
  }, []);

  const setCopyFeedback = (state: 'copied' | 'error') => {
    setCopyState(state);
    if (copyTimeoutRef.current) {
      window.clearTimeout(copyTimeoutRef.current);
    }
    copyTimeoutRef.current = window.setTimeout(() => {
      setCopyState('idle');
    }, 2000);
  };

  const updateScriptAt = (index: number, value: string, commit: boolean) => {
    const next = externalScripts.map((entry, idx) => (idx === index ? value : entry));
    if (commit) {
      onCommitExternalScripts(next);
    } else {
      onChangeExternalScripts(next);
    }
  };

  const handleAddScript = () => {
    if (!canAddScript) return;
    onChangeExternalScripts([...externalScripts, '']);
  };

  const handleRemoveScript = (index: number) => {
    if (disabled) return;
    const next = externalScripts.filter((_, idx) => idx !== index);
    onChangeExternalScripts(next);
    onCommitExternalScripts(next);
  };

  const updateStyleAt = (index: number, value: string, commit: boolean) => {
    const next = externalStyles.map((entry, idx) => (idx === index ? value : entry));
    if (commit) {
      onCommitExternalStyles(next);
    } else {
      onChangeExternalStyles(next);
    }
  };

  const handleAddStyle = () => {
    if (!canAddStyle) return;
    onChangeExternalStyles([...externalStyles, '']);
  };

  const handleRemoveStyle = (index: number) => {
    if (disabled) return;
    const next = externalStyles.filter((_, idx) => idx !== index);
    onChangeExternalStyles(next);
    onCommitExternalStyles(next);
  };

  const handleCopyShortcode = async () => {
    if (!shortcodeText) return;
    let copied = false;
    if (window.navigator?.clipboard?.writeText) {
      try {
        await window.navigator.clipboard.writeText(shortcodeText);
        copied = true;
      } catch {
        copied = false;
      }
    }

    if (!copied && shortcodeInputRef.current) {
      shortcodeInputRef.current.focus();
      shortcodeInputRef.current.select();
      try {
        copied = document.execCommand('copy');
      } catch {
        copied = false;
      }
      shortcodeInputRef.current.setSelectionRange(0, 0);
    }

    setCopyFeedback(copied ? 'copied' : 'error');
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
                event.target.value as 'default' | 'standalone' | 'frame' | 'theme'
              )
            }
            aria-label={__( 'Template mode', 'kayzart-live-code-editor')}
            disabled={disabled}
          >
            <option value="default">
              {sprintf(__( 'Use default (%s)', 'kayzart-live-code-editor'), resolvedDefaultTemplateMode)}
            </option>
            <option value="standalone">{templateModeLabels.standalone}</option>
            <option value="frame">{templateModeLabels.frame}</option>
            <option value="theme">{templateModeLabels.theme}</option>
          </select>
        </div>
        {templateHelp ? <div className="kayzart-settingsHelp">{templateHelp}</div> : null}
      </div>

      <div className="kayzart-settingsSection">
        <div className="kayzart-settingsSectionTitle">
          {__( 'Output settings', 'kayzart-live-code-editor')}
        </div>
        <div className="kayzart-settingsItem kayzart-settingsToggle">
          <div className="kayzart-settingsItemLabel">
            {__( 'Enable external embedding', 'kayzart-live-code-editor')}
          </div>
          <label className="kayzart-toggle">
            <input
              type="checkbox"
              checked={shortcodeEnabled}
              aria-label={__( 'Enable external embedding', 'kayzart-live-code-editor')}
              onChange={(event) => onToggleShortcode(event.target.checked)}
              disabled={disabled}
            />
            <span className="kayzart-toggleTrack" aria-hidden="true" />
          </label>
        </div>
        {shortcodeEnabled ? (
          <Fragment>
            <div className="kayzart-settingsScriptRow">
              <input
                ref={shortcodeInputRef}
                type="text"
                className="kayzart-formInput kayzart-settingsScriptInput"
                value={shortcodeText}
                readOnly
                aria-label={__( 'KayzArt embed code', 'kayzart-live-code-editor')}
              />
              <button
                className="kayzart-btn kayzart-btn-secondary"
                type="button"
                onClick={handleCopyShortcode}
                aria-label={__( 'Copy embed code', 'kayzart-live-code-editor')}
              >
                {copyState === 'copied'
                  ? __( 'Copied', 'kayzart-live-code-editor')
                  : __( 'Copy', 'kayzart-live-code-editor')}
              </button>
            </div>
            {copyState === 'copied' ? (
              <div className="kayzart-settingsHelp">{__( 'Copied.', 'kayzart-live-code-editor')}</div>
            ) : null}
            {copyState === 'error' ? (
              <div className="kayzart-settingsError">{__( 'Copy failed.', 'kayzart-live-code-editor')}</div>
            ) : null}
            <div className="kayzart-settingsItem kayzart-settingsToggle">
              <div className="kayzart-settingsItemLabel">
                {__( 'Do not publish as single page', 'kayzart-live-code-editor')}
              </div>
              <label className="kayzart-toggle">
                <input
                  type="checkbox"
                  checked={!singlePageEnabled}
                  aria-label={__( 'Do not publish as single page', 'kayzart-live-code-editor')}
                  onChange={(event) => onToggleSinglePage(!event.target.checked)}
                  disabled={disabled}
                />
                <span className="kayzart-toggleTrack" aria-hidden="true" />
              </label>
            </div>
            <div className="kayzart-settingsHelp">
              {__(
                'You can paste this embed code into a Shortcode block in Gutenberg or Elementor.', 'kayzart-live-code-editor')}
            </div>
          </Fragment>
        ) : null}
        {disabled ? (
          <div className="kayzart-settingsHelp">
            {__( 'Requires unfiltered_html capability.', 'kayzart-live-code-editor')}
          </div>
        ) : null}
        {error ? <div className="kayzart-settingsError">{error}</div> : null}
      </div>

      <div className="kayzart-settingsSection">
        <div className="kayzart-settingsSectionTitle">
          {__( 'Rendering settings', 'kayzart-live-code-editor')}
        </div>
        <div className="kayzart-settingsItem kayzart-settingsToggle">
          <div className="kayzart-settingsItemLabel">
            {__( 'Enable Shadow DOM (DSD)', 'kayzart-live-code-editor')}
          </div>
          <label className="kayzart-toggle">
            <input
              type="checkbox"
              checked={shadowDomEnabled}
              aria-label={__( 'Enable Shadow DOM (DSD)', 'kayzart-live-code-editor')}
              onChange={(event) => onToggleShadowDom(event.target.checked)}
              disabled={disabled}
            />
            <span className="kayzart-toggleTrack" aria-hidden="true" />
          </label>
        </div>
        <div className="kayzart-settingsHelp">
          {__( 'Prevents interference with existing theme CSS.', 'kayzart-live-code-editor')}
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
                {externalScripts.map((scriptUrl, index) => (
                  <div className="kayzart-settingsScriptRow" key={`script-${index}`}>
                    <input
                      type="url"
                      className="kayzart-formInput kayzart-settingsScriptInput"
                      placeholder={__( 'https://example.com/script.js', 'kayzart-live-code-editor')}
                      value={scriptUrl}
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
            {externalStyles.map((styleUrl, index) => (
              <div className="kayzart-settingsScriptRow" key={`style-${index}`}>
                <input
                  type="url"
                  className="kayzart-formInput kayzart-settingsScriptInput"
                  placeholder={__( 'https://example.com/style.css', 'kayzart-live-code-editor')}
                  value={styleUrl}
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


