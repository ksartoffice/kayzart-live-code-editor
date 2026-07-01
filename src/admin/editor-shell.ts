import { __ } from '@wordpress/i18n';

import { AlignLeft, ImagePlus } from 'lucide';
import { renderLucideIcon } from './lucide-icons';

type EditorShellRefs = {
  app: HTMLDivElement;
  toolbar: HTMLDivElement;
  compactEditorTabs: HTMLDivElement;
  compactEditorActions: HTMLDivElement;
  compactHtmlTab: HTMLButtonElement;
  compactCustomHeadTab: HTMLButtonElement;
  compactCssTab: HTMLButtonElement;
  compactJsTab: HTMLButtonElement;
  compactJsModeSelect: HTMLSelectElement;
  compactAddMediaButton: HTMLButtonElement;
  compactFormatButton: HTMLButtonElement;
  compactReloadPendingNotice: HTMLSpanElement;
  htmlHeader: HTMLDivElement;
  htmlTitle: HTMLSpanElement;
  htmlTab: HTMLButtonElement;
  customHeadTab: HTMLButtonElement;
  addMediaButton: HTMLButtonElement;
  htmlFormatButton: HTMLButtonElement;
  htmlWordWrapButton: HTMLButtonElement;
  htmlEditorDiv: HTMLDivElement;
  customHeadEditorDiv: HTMLDivElement;
  customHeadHelp: HTMLDivElement;
  customHeadPendingNotice: HTMLDivElement;
  cssEditorDiv: HTMLDivElement;
  jsEditorDiv: HTMLDivElement;
  htmlPane: HTMLDivElement;
  cssPane: HTMLDivElement;
  cssTab: HTMLButtonElement;
  jsTab: HTMLButtonElement;
  cssFormatButton: HTMLButtonElement;
  jsModeSelect: HTMLSelectElement;
  jsPendingNotice: HTMLSpanElement;
  jsControls: HTMLDivElement;
  editorResizer: HTMLDivElement;
  main: HTMLDivElement;
  left: HTMLDivElement;
  right: HTMLDivElement;
  resizer: HTMLDivElement;
  settingsResizer: HTMLDivElement;
  iframe: HTMLIFrameElement;
  previewBadge: HTMLDivElement;
  settings: HTMLElement;
  settingsHeader: HTMLDivElement;
  settingsBody: HTMLDivElement;
};

function el<K extends keyof HTMLElementTagNameMap>(tag: K, cls?: string) {
  const element = document.createElement(tag);
  if (cls) element.className = cls;
  return element;
}

function createCompactActionButton(
  className: string,
  label: string,
  iconSvg: string
): HTMLButtonElement {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = className;
  button.setAttribute('aria-label', label);
  button.setAttribute('title', label);

  const icon = el('span', 'kayzart-compactEditorActionIcon');
  icon.setAttribute('aria-hidden', 'true');
  icon.innerHTML = iconSvg;

  const text = el('span', 'kayzart-compactEditorActionLabel');
  text.textContent = label;

  button.append(icon, text);
  return button;
}

function createJsModeSelect(className: string): HTMLSelectElement {
  const select = document.createElement('select');
  select.className = className;
  select.setAttribute('aria-label', __( 'JavaScript mode', 'kayzart-live-code-editor'));

  const options: Array<{ value: string; label: string }> = [
    { value: 'classic', label: __( 'type: classic script', 'kayzart-live-code-editor') },
    { value: 'module', label: __( 'type: module', 'kayzart-live-code-editor') },
  ];

  options.forEach((optionData) => {
    const option = document.createElement('option');
    option.value = optionData.value;
    option.textContent = optionData.label;
    select.append(option);
  });

  return select;
}

export function buildEditorShell(root: HTMLElement): EditorShellRefs {
  const app = el('div', 'kayzart-app');

  // Toolbar (React mount point)
  const toolbar = el('div', 'kayzart-toolbar');

  // Main split
  const main = el('div', 'kayzart-main');
  const left = el('div', 'kayzart-left');
  const resizer = el('div', 'kayzart-resizer');
  const right = el('div', 'kayzart-right');
  const settingsResizer = el('div', 'kayzart-settingsResizer');
  const settings = el('aside', 'kayzart-settings');
  settings.id = 'kayzart-settings';
  const settingsInner = el('div', 'kayzart-settingsInner');
  const settingsHeader = el('div', 'kayzart-settingsHeader');
  const settingsBody = el('div', 'kayzart-settingsBody');
  settingsInner.append(settingsHeader, settingsBody);
  settings.append(settingsInner);

  const compactIcons = {
    media: renderLucideIcon(ImagePlus, {
      class: 'lucide lucide-image-plus-icon lucide-image-plus',
    }),
    format: renderLucideIcon(AlignLeft, {
      class: 'lucide lucide-align-left-icon lucide-align-left',
    }),
  };

  const compactEditorTabs = el('div', 'kayzart-compactEditorTabs');
  const compactEditorTabsList = el('div', 'kayzart-editorTabs kayzart-compactEditorTabsList');
  const compactEditorActions = el('div', 'kayzart-compactEditorActions');
  const compactHtmlTab = document.createElement('button');
  compactHtmlTab.type = 'button';
  compactHtmlTab.className = 'kayzart-editorTab kayzart-compactEditorTab is-active';
  compactHtmlTab.textContent = __( 'HTML', 'kayzart-live-code-editor');
  const compactCssTab = document.createElement('button');
  compactCssTab.type = 'button';
  compactCssTab.className = 'kayzart-editorTab kayzart-compactEditorTab';
  compactCssTab.textContent = __( 'CSS', 'kayzart-live-code-editor');
  const compactCustomHeadTab = document.createElement('button');
  compactCustomHeadTab.type = 'button';
  compactCustomHeadTab.className = 'kayzart-editorTab kayzart-compactEditorTab';
  compactCustomHeadTab.textContent = __( 'head', 'kayzart-live-code-editor');
  const compactJsTab = document.createElement('button');
  compactJsTab.type = 'button';
  compactJsTab.className = 'kayzart-editorTab kayzart-compactEditorTab';
  compactJsTab.textContent = __( 'JavaScript', 'kayzart-live-code-editor');
  const compactJsModeSelect = createJsModeSelect('kayzart-formSelect kayzart-jsModeSelect kayzart-compactJsModeSelect');
  compactEditorTabsList.append(compactHtmlTab, compactCustomHeadTab, compactCssTab, compactJsTab);
  const compactAddMediaButton = createCompactActionButton(
    'kayzart-editorAction kayzart-compactEditorAction kayzart-compactEditorAction-media',
    __( 'Add Media', 'kayzart-live-code-editor'),
    compactIcons.media
  );
  const compactFormatButton = createCompactActionButton(
    'kayzart-editorAction kayzart-compactEditorAction kayzart-compactEditorAction-format',
    __( 'Format HTML', 'kayzart-live-code-editor'),
    compactIcons.format
  );
  const compactReloadPendingNotice = el('span', 'kayzart-reloadPendingNotice kayzart-compactReloadPendingNotice');
  compactEditorActions.append(
    compactAddMediaButton,
    compactFormatButton,
    compactJsModeSelect,
    compactReloadPendingNotice,
  );
  compactEditorTabs.append(compactEditorTabsList, compactEditorActions);

  const htmlPane = el('div', 'kayzart-editorPane kayzart-editorPane-html is-active');
  const htmlHeader = el('div', 'kayzart-editorHeader kayzart-editorHeader-tabs');
  const htmlTabs = el('div', 'kayzart-editorTabs');
  const htmlTitle = el('span', 'kayzart-editorTitle');
  htmlTitle.textContent = __( 'HTML', 'kayzart-live-code-editor');
  const htmlTab = document.createElement('button');
  htmlTab.type = 'button';
  htmlTab.className = 'kayzart-editorTab is-active';
  htmlTab.textContent = __( 'HTML', 'kayzart-live-code-editor');
  const customHeadTab = document.createElement('button');
  customHeadTab.type = 'button';
  customHeadTab.className = 'kayzart-editorTab';
  customHeadTab.textContent = __( 'head', 'kayzart-live-code-editor');
  htmlTabs.append(htmlTab, customHeadTab);
  const htmlActions = el('div', 'kayzart-editorActions');
  const addMediaButton = document.createElement('button');
  addMediaButton.type = 'button';
  addMediaButton.className = 'kayzart-editorAction kayzart-editorAction-media';
  addMediaButton.textContent = __( 'Add Media', 'kayzart-live-code-editor');
  const htmlFormatButton = document.createElement('button');
  htmlFormatButton.type = 'button';
  htmlFormatButton.className = 'kayzart-editorAction kayzart-editorAction-format';
  htmlFormatButton.textContent = __( 'Format', 'kayzart-live-code-editor');
  htmlFormatButton.setAttribute('aria-label', __( 'Format HTML', 'kayzart-live-code-editor'));
  htmlFormatButton.setAttribute('title', __( 'Format HTML', 'kayzart-live-code-editor'));
  const htmlWordWrapButton = document.createElement('button');
  htmlWordWrapButton.type = 'button';
  htmlWordWrapButton.className = 'kayzart-editorAction kayzart-editorAction-wrap';
  htmlWordWrapButton.textContent = __( 'Wrap: Off', 'kayzart-live-code-editor');
  htmlWordWrapButton.setAttribute('aria-label', __( 'Wrap: Off', 'kayzart-live-code-editor'));
  htmlActions.append(addMediaButton, htmlFormatButton, htmlWordWrapButton);
  htmlHeader.append(htmlTabs, htmlActions);
  const htmlWrap = el('div', 'kayzart-editorWrap kayzart-editorWrap-tabs');
  const htmlEditorDiv = el('div', 'kayzart-editor kayzart-editor-html is-active');
  const customHeadPanel = el('div', 'kayzart-editor kayzart-editor-customHead');
  const customHeadHelp = el('div', 'kayzart-customHeadHelp');
  const customHeadPendingNotice = el('div', 'kayzart-reloadPendingNotice kayzart-customHeadPendingNotice');
  customHeadPendingNotice.textContent = __( 'Reload preview to apply head and JavaScript changes.', 'kayzart-live-code-editor');
  const customHeadHelpLines = [
    __( 'Enter code to add inside the head.', 'kayzart-live-code-editor'),
    __( 'Use this for external CSS, Google Fonts, OGP, structured data, and similar head markup.', 'kayzart-live-code-editor'),
    __( 'Write external JS here, or add it as <script src=""> at the bottom of the HTML editor.', 'kayzart-live-code-editor'),
    __( 'Note: <title>, <meta charset>, viewport, and <base> cannot be used.', 'kayzart-live-code-editor'),
  ];
  customHeadHelp.replaceChildren(
    ...customHeadHelpLines.flatMap((line, index) =>
      index === 0 ? [document.createTextNode(line)] : [document.createElement('br'), document.createTextNode(line)]
    )
  );
  const customHeadEditorDiv = el('div', 'kayzart-customHeadEditor');
  customHeadPanel.append(customHeadHelp, customHeadPendingNotice, customHeadEditorDiv);
  htmlWrap.append(htmlEditorDiv, customHeadPanel);
  htmlPane.append(htmlHeader, htmlWrap);

  const cssPane = el('div', 'kayzart-editorPane kayzart-editorPane-css');
  const cssHeader = el('div', 'kayzart-editorHeader kayzart-editorHeader-tabs');
  const cssTabs = el('div', 'kayzart-editorTabs');
  const cssTab = document.createElement('button');
  cssTab.type = 'button';
  cssTab.className = 'kayzart-editorTab is-active';
  cssTab.textContent = __( 'CSS', 'kayzart-live-code-editor');
  const jsTab = document.createElement('button');
  jsTab.type = 'button';
  jsTab.className = 'kayzart-editorTab';
  jsTab.textContent = __( 'JavaScript', 'kayzart-live-code-editor');
  const jsModeSelect = createJsModeSelect('kayzart-formSelect kayzart-jsModeSelect');
  cssTabs.append(cssTab, jsTab);

  const jsControls = el('div', 'kayzart-editorActions');
  const cssFormatButton = document.createElement('button');
  cssFormatButton.type = 'button';
  cssFormatButton.className = 'kayzart-editorAction kayzart-editorAction-format';
  cssFormatButton.textContent = __( 'Format', 'kayzart-live-code-editor');
  cssFormatButton.setAttribute('aria-label', __( 'Format CSS', 'kayzart-live-code-editor'));
  cssFormatButton.setAttribute('title', __( 'Format CSS', 'kayzart-live-code-editor'));
  const jsPendingNotice = el('span', 'kayzart-reloadPendingNotice kayzart-jsPendingNotice');
  jsPendingNotice.textContent = __( 'Reload preview to apply head and JavaScript changes.', 'kayzart-live-code-editor');
  compactReloadPendingNotice.textContent = jsPendingNotice.textContent;
  jsControls.append(cssFormatButton, jsPendingNotice, jsModeSelect);

  cssHeader.append(cssTabs, jsControls);
  const cssWrap = el('div', 'kayzart-editorWrap kayzart-editorWrap-tabs');
  const cssEditorDiv = el('div', 'kayzart-editor kayzart-editor-css is-active');
  const jsEditorDiv = el('div', 'kayzart-editor kayzart-editor-js');
  cssWrap.append(cssEditorDiv, jsEditorDiv);
  cssPane.append(cssHeader, cssWrap);

  const editorResizer = el('div', 'kayzart-editorResizer');

  left.append(compactEditorTabs, htmlPane, editorResizer, cssPane);

  // Preview
  const iframe = document.createElement('iframe');
  iframe.className = 'kayzart-iframe';
  iframe.referrerPolicy = 'strict-origin-when-cross-origin';
  iframe.setAttribute(
    'sandbox',
    'allow-scripts allow-same-origin allow-forms allow-modals allow-popups allow-downloads allow-popups-to-escape-sandbox'
  );
  const previewBadge = el('div', 'kayzart-previewBadge');
  previewBadge.setAttribute('role', 'status');
  previewBadge.setAttribute('aria-live', 'polite');
  previewBadge.setAttribute('aria-atomic', 'true');
  right.append(iframe, previewBadge);

  main.append(left, resizer, right, settingsResizer, settings);
  app.append(toolbar, main);
  root.append(app);

  return {
    app,
    toolbar,
    compactEditorTabs,
    compactEditorActions,
    compactHtmlTab,
    compactCustomHeadTab,
    compactCssTab,
    compactJsTab,
    compactJsModeSelect,
    compactAddMediaButton,
    compactFormatButton,
    compactReloadPendingNotice,
    htmlHeader,
    htmlTitle,
    htmlTab,
    customHeadTab,
    addMediaButton,
    htmlFormatButton,
    htmlWordWrapButton,
    htmlEditorDiv,
    customHeadEditorDiv,
    customHeadHelp,
    customHeadPendingNotice,
    cssEditorDiv,
    jsEditorDiv,
    htmlPane,
    cssPane,
    cssTab,
    jsTab,
    cssFormatButton,
    jsModeSelect,
    jsPendingNotice,
    jsControls,
    editorResizer,
    main,
    left,
    right,
    resizer,
    settingsResizer,
    iframe,
    previewBadge,
    settings,
    settingsHeader,
    settingsBody,
  };
}

export type { EditorShellRefs };


