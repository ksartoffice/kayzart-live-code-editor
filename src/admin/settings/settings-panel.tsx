import { createElement, Fragment } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

type SettingsPanelProps = {
  canEditJs: boolean;
  templateMode: 'default' | 'standalone' | 'theme';
  defaultTemplateMode: 'standalone' | 'theme';
  onChangeTemplateMode: (mode: 'default' | 'standalone' | 'theme') => void;
  liveHighlightEnabled: boolean;
  onToggleLiveHighlight: (enabled: boolean) => void;
  disabled?: boolean;
};

export function SettingsPanel({
  canEditJs,
  templateMode,
  defaultTemplateMode,
  onChangeTemplateMode,
  liveHighlightEnabled,
  onToggleLiveHighlight,
  disabled = false,
}: SettingsPanelProps) {
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
            disabled={disabled || !canEditJs}
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
              disabled={disabled}
            />
            <span className="kayzart-toggleTrack" aria-hidden="true" />
          </label>
        </div>
      </div>
    </Fragment>
  );
}
