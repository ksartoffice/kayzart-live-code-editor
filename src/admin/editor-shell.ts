import { __ } from '@wordpress/i18n';

import { ImagePlus, Lightbulb, Play } from 'lucide';
import { renderLucideIcon } from './lucide-icons';

type EditorShellRefs = {
  app: HTMLDivElement;
  toolbar: HTMLDivElement;
  compactEditorTabs: HTMLDivElement;
  compactEditorActions: HTMLDivElement;
  compactHtmlTab: HTMLButtonElement;
  compactCssTab: HTMLButtonElement;
  compactJsTab: HTMLButtonElement;
  compactJsModeSelect: HTMLSelectElement;
  compactAddMediaButton: HTMLButtonElement;
  compactRunButton: HTMLButtonElement;
  compactShadowHintButton: HTMLButtonElement;
  compactTailwindHintButton: HTMLButtonElement;
  htmlHeader: HTMLDivElement;
  htmlTitle: HTMLSpanElement;
  addMediaButton: HTMLButtonElement;
  htmlWordWrapButton: HTMLButtonElement;
  htmlEditorDiv: HTMLDivElement;
  cssEditorDiv: HTMLDivElement;
  jsEditorDiv: HTMLDivElement;
  htmlPane: HTMLDivElement;
  cssPane: HTMLDivElement;
  cssTab: HTMLButtonElement;
  jsTab: HTMLButtonElement;
  jsModeSelect: HTMLSelectElement;
  jsControls: HTMLDivElement;
  runButton: HTMLButtonElement;
  shadowHintButton: HTMLButtonElement;
  tailwindHintButton: HTMLButtonElement;
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
    { value: 'classic', label: __( 'type: Classic script', 'kayzart-live-code-editor') },
    { value: 'module', label: __( 'type: Module', 'kayzart-live-code-editor') },
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
    run: renderLucideIcon(Play, {
      class: 'lucide lucide-play-icon lucide-play',
    }),
    hint: renderLucideIcon(Lightbulb, {
      class: 'lucide lucide-lightbulb-icon lucide-lightbulb',
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
  const compactJsTab = document.createElement('button');
  compactJsTab.type = 'button';
  compactJsTab.className = 'kayzart-editorTab kayzart-compactEditorTab';
  compactJsTab.textContent = __( 'JavaScript', 'kayzart-live-code-editor');
  const compactJsModeSelect = createJsModeSelect('kayzart-formSelect kayzart-jsModeSelect kayzart-compactJsModeSelect');
  compactEditorTabsList.append(compactHtmlTab, compactCssTab, compactJsTab);
  const compactAddMediaButton = createCompactActionButton(
    'kayzart-editorAction kayzart-compactEditorAction kayzart-compactEditorAction-media',
    __( 'Add Media', 'kayzart-live-code-editor'),
    compactIcons.media
  );
  const compactRunButton = createCompactActionButton(
    'kayzart-editorAction kayzart-compactEditorAction kayzart-compactEditorAction-run',
    __( 'Run', 'kayzart-live-code-editor'),
    compactIcons.run
  );
  const compactShadowHintButton = createCompactActionButton(
    'kayzart-editorAction kayzart-compactEditorAction kayzart-compactEditorAction-hint',
    __( 'Shadow DOM Hint', 'kayzart-live-code-editor'),
    compactIcons.hint
  );
  const compactTailwindHintButton = createCompactActionButton(
    'kayzart-editorAction kayzart-compactEditorAction kayzart-compactEditorAction-hint',
    __( 'Tailwind CSS Hint', 'kayzart-live-code-editor'),
    compactIcons.hint
  );
  compactEditorActions.append(
    compactAddMediaButton,
    compactJsModeSelect,
    compactShadowHintButton,
    compactRunButton,
    compactTailwindHintButton,
  );
  compactEditorTabs.append(compactEditorTabsList, compactEditorActions);

  const htmlPane = el('div', 'kayzart-editorPane kayzart-editorPane-html is-active');
  const htmlHeader = el('div', 'kayzart-editorHeader kayzart-editorHeader-tabs');
  const htmlTitle = el('span', 'kayzart-editorTitle');
  htmlTitle.textContent = __( 'HTML', 'kayzart-live-code-editor');
  const htmlActions = el('div', 'kayzart-editorActions');
  const addMediaButton = document.createElement('button');
  addMediaButton.type = 'button';
  addMediaButton.className = 'kayzart-editorAction kayzart-editorAction-media';
  addMediaButton.textContent = __( 'Add Media', 'kayzart-live-code-editor');
  const htmlWordWrapButton = document.createElement('button');
  htmlWordWrapButton.type = 'button';
  htmlWordWrapButton.className = 'kayzart-editorAction kayzart-editorAction-wrap';
  htmlWordWrapButton.textContent = __( 'Wrap: Off', 'kayzart-live-code-editor');
  htmlWordWrapButton.setAttribute('aria-label', __( 'Wrap: Off', 'kayzart-live-code-editor'));
  htmlActions.append(addMediaButton, htmlWordWrapButton);
  htmlHeader.append(htmlTitle, htmlActions);
  const htmlWrap = el('div', 'kayzart-editorWrap');
  const htmlEditorDiv = el('div', 'kayzart-editor kayzart-editor-html');
  htmlWrap.append(htmlEditorDiv);
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
  const runButton = document.createElement('button');
  runButton.type = 'button';
  runButton.className = 'kayzart-editorAction';
  runButton.textContent = __( 'Run', 'kayzart-live-code-editor');
  const shadowHintButton = document.createElement('button');
  shadowHintButton.type = 'button';
  shadowHintButton.className = 'kayzart-editorAction kayzart-editorAction-hint';
  shadowHintButton.textContent = __( 'Shadow DOM Hint', 'kayzart-live-code-editor');
  const tailwindHintButton = document.createElement('button');
  tailwindHintButton.type = 'button';
  tailwindHintButton.className = 'kayzart-editorAction kayzart-editorAction-hint';
  tailwindHintButton.textContent = __( 'Tailwind CSS Hint', 'kayzart-live-code-editor');
  jsControls.append(jsModeSelect, shadowHintButton, runButton, tailwindHintButton);

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
    compactCssTab,
    compactJsTab,
    compactJsModeSelect,
    compactAddMediaButton,
    compactRunButton,
    compactShadowHintButton,
    compactTailwindHintButton,
    htmlHeader,
    htmlTitle,
    addMediaButton,
    htmlWordWrapButton,
    htmlEditorDiv,
    cssEditorDiv,
    jsEditorDiv,
    htmlPane,
    cssPane,
    cssTab,
    jsTab,
    jsModeSelect,
    jsControls,
    runButton,
    shadowHintButton,
    tailwindHintButton,
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


