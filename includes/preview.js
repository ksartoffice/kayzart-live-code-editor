(function () {
  const styleId = 'kayzart-style';
  const shortcodeStyleId = 'kayzart-shortcode-preview-style';
  const customHeadId = 'kayzart-custom-head';
  const customHeadStartMarker = 'kayzart-custom-head-start';
  const customHeadEndMarker = 'kayzart-custom-head-end';
  const scriptId = 'kayzart-script';
  const KAYZART_ATTR_NAME = 'data-kayzart-id';
  const config = window.KAYZART_PREVIEW || {};
  const postId = config.post_id || null;
  const markerAttr =
    config.markers && config.markers.attr ? String(config.markers.attr) : 'data-kayzart-marker';
  const markerPostAttr =
    config.markers && config.markers.postAttr
      ? String(config.markers.postAttr)
      : 'data-kayzart-post-id';
  const markerPostId = postId === null ? '' : String(postId);
  const markerStart = config.markers && config.markers.start ? String(config.markers.start) : 'start';
  const markerEnd = config.markers && config.markers.end ? String(config.markers.end) : 'end';
  const labels = resolveLabels(config.labels);
  const allowedOrigin = getAllowedOrigin();
  let isReady = false;
  let hoverTarget = null;
  let highlightBox = null;
  let selectTarget = null;
  let selectBox = null;
  let selectActionGroup = null;
  let selectActionAddonButton = null;
  let selectActionEditButton = null;
  let selectActionMenuButton = null;
  let selectActionMenu = null;
  let selectActionParentMenuItem = null;
  let selectActionCopyMenuItem = null;
  let selectActionDeleteMenuItem = null;
  let elementsTabOpen = false;
  let markerNodes = null;
  let htmlScriptsReady = Promise.resolve();
  let customHeadToken = 0;
  let customHeadNodes = [];
  let jsRunToken = 0;
  let jsCleanupCallbacks = [];
  let activeModuleUrl = '';
  let jsEnabled = false;
  let missingMarkersNotified = false;
  let pendingRenderPayload = null;
  let markerRetryTimer = 0;
  let markerRetryStartedAt = 0;
  let initialBodyAttrs = null;
  let appliedBodyAttrNames = [];
  let scrollRestoreToken = 0;
  let applyingScrollRestoreToken = 0;
  let scrollSaveTimer = 0;
  let initialSavedScrollRestorePending = true;
  let capturedScrollSnapshot = null;
  let capturedScrollRestoreBlockedUntil = 0;
  let scrollSaveSuppressedUntil = 0;
  let pendingScrollRestoreSnapshot = null;
  const markerRetryDelayMs = 50;
  const markerRetryMaxWaitMs = 10000;
  const scrollStorageKey = postId ? 'kayzart:preview-scroll:' + String(postId) : '';
  const overlayActionConfig = resolveOverlayActionConfig(config.overlayAction);
  let domSelectorEnabled =
    config.liveHighlightEnabled === undefined ? true : Boolean(config.liveHighlightEnabled);

  function getAllowedOrigin() {
    if (!config.allowedOrigin) {
      return '';
    }
    try {
      return new URL(String(config.allowedOrigin)).origin;
    } catch (e) {
      return '';
    }
  }

  function getStyleRoot() {
    return document.head || document.body;
  }

  function getInlineScriptHost() {
    return document.body || document.head;
  }

  function setDomSelectorEnabled(enabled) {
    const next = Boolean(enabled);
    if (domSelectorEnabled === next) return;
    domSelectorEnabled = next;
    if (!domSelectorEnabled) {
      clearHighlight();
      clearSelection();
    }
  }

  function ensureHighlightBox() {
    if (highlightBox) return highlightBox;
    highlightBox = document.createElement('div');
    highlightBox.id = 'kayzart-highlight-box';
    Object.assign(highlightBox.style, {
      position: 'fixed',
      border: '2px solid #3b82f6',
      background: 'rgba(59, 130, 246, 0.12)',
      pointerEvents: 'none',
      zIndex: 2147483646,
      top: '0px',
      left: '0px',
      width: '0px',
      height: '0px',
      boxSizing: 'border-box',
      transition: 'all 60ms ease-out',
      display: 'none',
    });
    document.body.appendChild(highlightBox);
    return highlightBox;
  }

  function ensureSelectBox() {
    if (selectBox) return selectBox;
    selectBox = document.createElement('div');
    selectBox.id = 'kayzart-select-box';
    Object.assign(selectBox.style, {
      position: 'fixed',
      border: '2px solid #a855f7',
      background: 'rgba(168, 85, 247, 0.12)',
      pointerEvents: 'none',
      zIndex: 2147483645,
      top: '0px',
      left: '0px',
      width: '0px',
      height: '0px',
      boxSizing: 'border-box',
      transition: 'all 80ms ease-out',
      display: 'none',
    });
    document.body.appendChild(selectBox);
    return selectBox;
  }

  function createSelectActionButton(args) {
    const button = document.createElement('button');
    button.id = args.id;
    button.type = 'button';
    button.setAttribute('aria-label', args.ariaLabel);
    Object.assign(button.style, {
      width: '32px',
      height: '32px',
      borderRadius: '999px',
      background: args.background,
      color: '#fff',
      border: 'none',
      padding: '7px',
      margin: '0',
      boxSizing: 'border-box',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      boxShadow: '0 4px 8px rgba(0, 0, 0, 0.25)',
      cursor: 'pointer',
      pointerEvents: 'auto',
      zIndex: 2147483647,
      transition: 'transform 80ms ease-out, opacity 80ms ease-out',
    });
    button.innerHTML = args.iconSvg;
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      args.onClick();
    });
    return button;
  }

  function resolveOverlayActionConfig(raw) {
    if (!raw || typeof raw !== 'object') {
      return null;
    }
    const actionId = typeof raw.actionId === 'string' ? raw.actionId.trim() : '';
    const iconSvg = typeof raw.iconSvg === 'string' ? raw.iconSvg : '';
    if (!actionId || !iconSvg) {
      return null;
    }
    const ariaLabel =
      typeof raw.ariaLabel === 'string' && raw.ariaLabel.trim()
        ? raw.ariaLabel.trim()
        : 'Run overlay action';
    const background =
      typeof raw.background === 'string' && raw.background.trim() ? raw.background.trim() : '#7c3aed';
    return {
      actionId: actionId,
      ariaLabel: ariaLabel,
      iconSvg: iconSvg,
      background: background,
      showWhenElementsTabOpen: raw.showWhenElementsTabOpen === true,
    };
  }

  function ensureSelectActionButtons() {
    if (selectActionGroup) return selectActionGroup;

    const group = document.createElement('div');
    group.id = 'kayzart-select-actions';
    Object.assign(group.style, {
      position: 'fixed',
      display: 'none',
      alignItems: 'center',
      gap: '6px',
      top: '0px',
      left: '0px',
      zIndex: 2147483647,
    });

    if (overlayActionConfig) {
      selectActionAddonButton = createSelectActionButton({
        id: 'kayzart-select-overlay-action',
        ariaLabel: overlayActionConfig.ariaLabel,
        background: overlayActionConfig.background,
        iconSvg: overlayActionConfig.iconSvg,
        onClick: () =>
          reply('KAYZART_OVERLAY_ACTION', {
            actionId: overlayActionConfig.actionId,
          }),
      });
      group.appendChild(selectActionAddonButton);
    }

    selectActionEditButton = createSelectActionButton({
      id: 'kayzart-select-action',
      ariaLabel: 'Open element settings',
      background: '#a855f7',
      iconSvg:
        '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-pencil-icon lucide-pencil"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/></svg>',
      onClick: () => reply('KAYZART_OPEN_ELEMENTS_TAB'),
    });
    group.appendChild(selectActionEditButton);

    selectActionMenuButton = createSelectActionButton({
      id: 'kayzart-select-menu-action',
      ariaLabel: 'Open selection menu',
      background: '#a855f7',
      iconSvg:
        '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>',
      onClick: toggleSelectContextMenu,
    });
    selectActionMenuButton.setAttribute('aria-haspopup', 'menu');
    selectActionMenuButton.setAttribute('aria-expanded', 'false');
    group.appendChild(selectActionMenuButton);

    document.body.appendChild(group);
    selectActionGroup = group;
    return group;
  }

  function ensureSelectContextMenu() {
    if (selectActionMenu) return selectActionMenu;

    const menu = document.createElement('div');
    menu.id = 'kayzart-select-context-menu';
    menu.setAttribute('role', 'menu');
    Object.assign(menu.style, {
      position: 'fixed',
      display: 'none',
      minWidth: '168px',
      padding: '6px',
      margin: '0',
      borderRadius: '8px',
      border: '1px solid rgba(17, 24, 39, 0.12)',
      background: '#fff',
      color: '#111827',
      boxShadow: '0 12px 28px rgba(15, 23, 42, 0.22)',
      boxSizing: 'border-box',
      zIndex: 2147483647,
      pointerEvents: 'auto',
      fontFamily:
        '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
      fontSize: '13px',
      lineHeight: '1.4',
    });

    selectActionParentMenuItem = createSelectMenuItem(
      'kayzart-select-parent-menu-item',
      labels.moveToParent
    );
    selectActionParentMenuItem.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (!getSelectableParent(selectTarget)) {
        return;
      }
      selectParentElement();
      closeSelectContextMenu();
    });
    menu.appendChild(selectActionParentMenuItem);

    selectActionCopyMenuItem = createSelectMenuItem(
      'kayzart-select-copy-html-menu-item',
      labels.copyHtml
    );
    selectActionCopyMenuItem.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const lcId = getSelectedLcId();
      if (!lcId) {
        return;
      }
      reply('KAYZART_COPY_ELEMENT_HTML', { lcId: lcId });
      closeSelectContextMenu();
    });
    menu.appendChild(selectActionCopyMenuItem);

    selectActionDeleteMenuItem = createSelectMenuItem(
      'kayzart-select-delete-menu-item',
      labels.delete
    );
    selectActionDeleteMenuItem.style.color = '#b91c1c';
    selectActionDeleteMenuItem.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const lcId = getSelectedLcId();
      if (!lcId) {
        return;
      }
      reply('KAYZART_DELETE_ELEMENT', { lcId: lcId });
      closeSelectContextMenu();
    });
    menu.appendChild(selectActionDeleteMenuItem);

    document.body.appendChild(menu);
    selectActionMenu = menu;
    return menu;
  }

  function resolveLabels(rawLabels) {
    const source = rawLabels && typeof rawLabels === 'object' ? rawLabels : {};
    return {
      moveToParent: source.moveToParent ? String(source.moveToParent) : 'Move to parent element',
      copyHtml: source.copyHtml ? String(source.copyHtml) : 'Copy HTML',
      delete: source.delete ? String(source.delete) : 'Delete',
      shortcodeLabel: source.shortcodeLabel ? String(source.shortcodeLabel) : 'Shortcode',
      shortcodeUnavailable: source.shortcodeUnavailable
        ? String(source.shortcodeUnavailable)
        : 'Not available in preview. It will render on the front end.',
    };
  }

  function createSelectMenuItem(id, label) {
    const item = document.createElement('button');
    item.id = id;
    item.type = 'button';
    item.setAttribute('role', 'menuitem');
    item.textContent = label;
    Object.assign(item.style, {
      display: 'block',
      width: '100%',
      border: 'none',
      borderRadius: '6px',
      background: 'transparent',
      color: '#111827',
      padding: '8px 10px',
      margin: '0',
      boxSizing: 'border-box',
      textAlign: 'left',
      cursor: 'pointer',
      font: 'inherit',
    });
    return item;
  }

  function getSelectedLcId() {
    return selectTarget && selectTarget.getAttribute
      ? selectTarget.getAttribute(KAYZART_ATTR_NAME)
      : '';
  }

  function getSelectableParent(el) {
    let parent = el && el.parentElement ? el.parentElement : null;
    while (parent && parent !== document.body && parent !== document.documentElement) {
      if (parent.hasAttribute(KAYZART_ATTR_NAME)) {
        return parent;
      }
      parent = parent.parentElement;
    }
    return null;
  }

  function selectElement(el) {
    if (!el || !(el instanceof Element)) {
      return;
    }
    drawSelection(el);
    const lcId = el.getAttribute(KAYZART_ATTR_NAME);
    if (lcId) {
      reply('KAYZART_SELECT', { lcId: lcId });
    }
  }

  function selectParentElement() {
    const parent = getSelectableParent(selectTarget);
    if (!parent) {
      return;
    }
    selectElement(parent);
  }

  function isSelectContextMenuOpen() {
    return Boolean(selectActionMenu && selectActionMenu.style.display !== 'none');
  }

  function updateSelectContextMenuState() {
    if (!selectActionParentMenuItem) {
      return;
    }
    const hasParent = Boolean(getSelectableParent(selectTarget));
    selectActionParentMenuItem.setAttribute('aria-disabled', hasParent ? 'false' : 'true');
    selectActionParentMenuItem.style.color = hasParent ? '#111827' : '#9ca3af';
    selectActionParentMenuItem.style.cursor = hasParent ? 'pointer' : 'default';
  }

  function positionSelectContextMenu() {
    if (!selectActionMenu || !selectActionMenuButton || selectActionMenu.style.display === 'none') {
      return;
    }
    const buttonRect = selectActionMenuButton.getBoundingClientRect();
    const menuRect = selectActionMenu.getBoundingClientRect();
    const gap = 6;
    const padding = 6;
    const maxTop = window.innerHeight - menuRect.height - padding;
    const maxLeft = window.innerWidth - menuRect.width - padding;
    let top = buttonRect.bottom + gap;
    let left = buttonRect.right - menuRect.width;
    top = Math.max(padding, Math.min(top, maxTop));
    left = Math.max(padding, Math.min(left, maxLeft));
    selectActionMenu.style.top = top + 'px';
    selectActionMenu.style.left = left + 'px';
  }

  function openSelectContextMenu() {
    if (!selectTarget) {
      return;
    }
    const menu = ensureSelectContextMenu();
    updateSelectContextMenuState();
    menu.style.display = 'block';
    if (selectActionMenuButton) {
      selectActionMenuButton.setAttribute('aria-expanded', 'true');
    }
    positionSelectContextMenu();
  }

  function closeSelectContextMenu() {
    if (selectActionMenu) {
      selectActionMenu.style.display = 'none';
    }
    if (selectActionMenuButton) {
      selectActionMenuButton.setAttribute('aria-expanded', 'false');
    }
  }

  function toggleSelectContextMenu() {
    if (isSelectContextMenuOpen()) {
      closeSelectContextMenu();
      return;
    }
    openSelectContextMenu();
  }

  function hideSelectActionButtons() {
    closeSelectContextMenu();
    if (selectActionGroup) {
      selectActionGroup.style.display = 'none';
    }
  }

  function updateSelectActionPosition() {
    if (!selectTarget) {
      hideSelectActionButtons();
      return;
    }
    const rect = selectTarget.getBoundingClientRect();
    const group = ensureSelectActionButtons();
    const size = 32;
    const gap = 6;
    const padding = 6;
    const showAddonButton =
      Boolean(selectActionAddonButton) &&
      (!elementsTabOpen ||
        Boolean(overlayActionConfig && overlayActionConfig.showWhenElementsTabOpen));
    const showEditButton = !elementsTabOpen && Boolean(selectActionEditButton);
    const showMenuButton = Boolean(selectActionMenuButton);
    const buttonCount =
      (showAddonButton ? 1 : 0) + (showEditButton ? 1 : 0) + (showMenuButton ? 1 : 0);
    if (buttonCount === 0) {
      hideSelectActionButtons();
      return;
    }
    if (selectActionAddonButton) {
      selectActionAddonButton.style.display = showAddonButton ? 'flex' : 'none';
    }
    if (selectActionEditButton) {
      selectActionEditButton.style.display = showEditButton ? 'flex' : 'none';
    }
    if (selectActionMenuButton) {
      selectActionMenuButton.style.display = showMenuButton ? 'flex' : 'none';
    }
    const groupWidth = size * buttonCount + gap * (buttonCount - 1);
    const maxTop = window.innerHeight - size - padding;
    const maxLeft = window.innerWidth - groupWidth - padding;
    let top = rect.top - size - gap;
    const rightButtonLeft = rect.right - size + gap;
    let left = rightButtonLeft - (groupWidth - size);
    top = Math.max(padding, Math.min(top, maxTop));
    left = Math.max(padding, Math.min(left, maxLeft));
    group.style.top = top + 'px';
    group.style.left = left + 'px';
    group.style.display = 'flex';
    updateSelectContextMenuState();
    positionSelectContextMenu();
  }

  function clearHighlight() {
    hoverTarget = null;
    if (highlightBox) {
      highlightBox.style.display = 'none';
    }
  }

  function clearSelection() {
    selectTarget = null;
    if (selectBox) {
      selectBox.style.display = 'none';
    }
    hideSelectActionButtons();
  }

  function drawHighlight(el) {
    if (!el || !(el instanceof Element)) {
      clearHighlight();
      return;
    }
    hoverTarget = el;
    const rect = el.getBoundingClientRect();
    const box = ensureHighlightBox();
    box.style.display = 'block';
    box.style.top = rect.top + 'px';
    box.style.left = rect.left + 'px';
    box.style.width = rect.width + 'px';
    box.style.height = rect.height + 'px';
  }

  function drawSelection(el) {
    if (!el || !(el instanceof Element)) {
      clearSelection();
      return;
    }
    selectTarget = el;
    const rect = el.getBoundingClientRect();
    const box = ensureSelectBox();
    box.style.display = 'block';
    box.style.top = rect.top + 'px';
    box.style.left = rect.left + 'px';
    box.style.width = rect.width + 'px';
    box.style.height = rect.height + 'px';
    updateSelectActionPosition();
  }

  function setElementsTabOpen(open) {
    elementsTabOpen = Boolean(open);
    updateSelectActionPosition();
  }

  function getComposedTarget(event) {
    if (typeof event.composedPath === 'function') {
      const path = event.composedPath();
      for (const node of path) {
        if (node instanceof Element && node.hasAttribute(KAYZART_ATTR_NAME)) {
          return node;
        }
      }
    }
    if (!event.target || !(event.target instanceof Element)) {
      return null;
    }
    return event.target.closest('[' + KAYZART_ATTR_NAME + ']');
  }

  function handleSelectMenuDocumentClick(event) {
    if (!isSelectContextMenuOpen()) {
      return;
    }
    const target = event.target;
    if (
      target instanceof Node &&
      ((selectActionMenu && selectActionMenu.contains(target)) ||
        (selectActionMenuButton && selectActionMenuButton.contains(target)))
    ) {
      return;
    }
    closeSelectContextMenu();
  }

  function handleSelectMenuKeydown(event) {
    if (event.key === 'Escape' && isSelectContextMenuOpen()) {
      closeSelectContextMenu();
      if (selectActionMenuButton) {
        selectActionMenuButton.focus();
      }
    }
  }

  function handlePointerMove(event) {
    if (!domSelectorEnabled) return;
    const target = getComposedTarget(event);
    if (!target) {
      clearHighlight();
      return;
    }
    drawHighlight(target);
  }

  function handleClick(event) {
    if (!domSelectorEnabled) return;
    const target = getComposedTarget(event);
    if (!target) return;
    event.preventDefault();
    event.stopPropagation();
    selectElement(target);
  }

  function attachDomSelector() {
    document.addEventListener('mousemove', handlePointerMove, { passive: true });
    document.addEventListener('mouseover', handlePointerMove, { passive: true });
    document.addEventListener('mouseleave', clearHighlight, { capture: true });
    document.addEventListener(
      'scroll',
      () => {
        if (hoverTarget) {
          drawHighlight(hoverTarget);
        }
        if (selectTarget) {
          drawSelection(selectTarget);
        }
      },
      true
    );
    window.addEventListener('resize', () => {
      if (hoverTarget) {
        drawHighlight(hoverTarget);
      }
      if (selectTarget) {
        drawSelection(selectTarget);
      }
    });
    document.addEventListener('click', handleSelectMenuDocumentClick, true);
    document.addEventListener('keydown', handleSelectMenuKeydown, true);
    document.addEventListener('click', handleClick, true);
  }

  function ensureStyleElement() {
    const root = getStyleRoot();
    if (!root) return null;
    let styleEl = root.querySelector('#' + styleId);
    if (!styleEl) {
      styleEl = document.createElement('style');
      styleEl.id = styleId;
      root.appendChild(styleEl);
    }
    return styleEl;
  }

  function ensureShortcodePreviewStyle() {
    const root = getStyleRoot();
    if (!root) return;
    if (root.querySelector('#' + shortcodeStyleId)) return;
    const styleEl = document.createElement('style');
    styleEl.id = shortcodeStyleId;
    styleEl.textContent = [
      '.kayzart-shortcode-placeholder{',
      'display:inline-flex;',
      'align-items:center;',
      'gap:6px;',
      'max-width:100%;',
      'padding:4px 8px;',
      'margin:0 2px;',
      'border:1px dashed #94a3b8;',
      'border-radius:6px;',
      'background:#f8fafc;',
      'color:#334155;',
      'font:12px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;',
      'vertical-align:baseline;',
      'box-sizing:border-box;',
      '}',
      '.kayzart-shortcode-placeholder__name{',
      'font-weight:600;',
      'color:#0f172a;',
      'white-space:nowrap;',
      '}',
      '.kayzart-shortcode-placeholder__message{',
      'color:#64748b;',
      'white-space:normal;',
      '}',
    ].join('');
    root.appendChild(styleEl);
  }

  function removeScriptElement() {
    const scriptEl = document.querySelector('#' + scriptId);
    if (scriptEl) {
      scriptEl.remove();
    }
  }

  function readInitialBodyAttrs() {
    if (initialBodyAttrs !== null) {
      return initialBodyAttrs;
    }
    initialBodyAttrs = {};
    if (!document.body) {
      return initialBodyAttrs;
    }
    Array.prototype.forEach.call(document.body.attributes, (attr) => {
      initialBodyAttrs[attr.name] = attr.value;
    });
    return initialBodyAttrs;
  }

  function splitClasses(value) {
    return String(value || '')
      .split(/\s+/)
      .map((item) => item.trim())
      .filter(Boolean);
  }

  function uniqueClasses(list) {
    const seen = {};
    return list.filter((item) => {
      if (seen[item]) return false;
      seen[item] = true;
      return true;
    });
  }

  function restoreInitialBodyAttrs() {
    if (!document.body) return;
    const initial = readInitialBodyAttrs();
    appliedBodyAttrNames.forEach((name) => {
      if (Object.prototype.hasOwnProperty.call(initial, name)) {
        document.body.setAttribute(name, initial[name]);
      } else {
        document.body.removeAttribute(name);
      }
    });
    appliedBodyAttrNames = [];
  }

  function applyBodyAttrs(attrs, hasBody, templateMode) {
    if (!document.body) return;
    const initial = readInitialBodyAttrs();
    restoreInitialBodyAttrs();

    if (!hasBody || !attrs || typeof attrs !== 'object') {
      return;
    }

    const nextNames = [];
    const bodyClass = typeof attrs.class === 'string' ? attrs.class : '';
    if (bodyClass) {
      const classes = uniqueClasses([...(splitClasses(initial.class)), ...splitClasses(bodyClass)]);
      document.body.setAttribute('class', classes.join(' '));
      nextNames.push('class');
    }

    if (templateMode !== 'theme') {
      Object.keys(attrs).forEach((name) => {
        if (name === 'class') return;
        document.body.setAttribute(name, String(attrs[name]));
        nextNames.push(name);
      });
    }

    appliedBodyAttrNames = nextNames;
  }

  function removeStyleElement() {
    const styleEl = document.querySelector('#' + styleId);
    if (styleEl) {
      styleEl.remove();
    }
  }

  function ensureCustomHeadMarker() {
    const root = document.head || document.documentElement;
    if (!root) return null;
    let marker = root.querySelector('#' + customHeadId);
    if (!marker) {
      marker = document.createElement('template');
      marker.id = customHeadId;
      root.appendChild(marker);
    }
    return marker;
  }

  function setCustomHead(html) {
    const root = document.head || document.documentElement;
    if (!root) return Promise.resolve();
    const token = ++customHeadToken;
    customHeadNodes.forEach((node) => {
      if (node && node.isConnected) {
        node.remove();
      }
    });
    customHeadNodes = [];
    const oldNodes = root.querySelectorAll('[data-kayzart-custom-head]');
    oldNodes.forEach((node) => node.remove());
    removeServerCustomHead(root);
    const marker = ensureCustomHeadMarker();
    const wrapper = document.createElement('template');
    wrapper.innerHTML = String(html || '');
    return insertCustomHeadNodes(root, marker, wrapper.content, token);
  }

  function cloneExecutableScript(node) {
    const script = document.createElement('script');
    const hasAsyncAttr = node.hasAttribute('async');
    Array.prototype.forEach.call(node.attributes, (attr) => {
      script.setAttribute(attr.name, attr.value);
    });
    if (!hasAsyncAttr) {
      script.async = false;
    }
    script.text = node.text || node.textContent || '';
    script.setAttribute('data-kayzart-custom-head', '1');
    return script;
  }

  function waitForScript(script) {
    if (!script.src) {
      return Promise.resolve();
    }
    return new Promise((resolve) => {
      script.addEventListener('load', resolve, { once: true });
      script.addEventListener('error', resolve, { once: true });
    });
  }

  async function insertCustomHeadNodes(root, marker, fragment, token) {
    const nodes = Array.prototype.slice.call(fragment.childNodes);
    const pendingAsyncScripts = [];
    for (let index = 0; index < nodes.length; index += 1) {
      if (token !== customHeadToken) {
        return;
      }
      const sourceNode = nodes[index];
      const isScript =
        sourceNode.nodeType === Node.ELEMENT_NODE &&
        String(sourceNode.tagName || '').toLowerCase() === 'script';
      const node = isScript ? cloneExecutableScript(sourceNode) : sourceNode;
      if (node.nodeType === Node.ELEMENT_NODE) {
        node.setAttribute('data-kayzart-custom-head', '1');
      }
      root.insertBefore(node, marker);
      customHeadNodes.push(node);
      if (!isScript || !node.src) {
        continue;
      }

      const scriptReady = waitForScript(node);
      if (sourceNode.hasAttribute('async')) {
        pendingAsyncScripts.push(scriptReady);
        continue;
      }
      await scriptReady;
    }

    if (pendingAsyncScripts.length) {
      await Promise.all(pendingAsyncScripts);
    }
  }

  function removeServerCustomHead(root) {
    let removing = false;
    Array.prototype.slice.call(root.childNodes).forEach((node) => {
      if (node.nodeType === Node.COMMENT_NODE && String(node.nodeValue || '').trim() === customHeadStartMarker) {
        removing = true;
        node.remove();
        return;
      }
      if (node.nodeType === Node.COMMENT_NODE && String(node.nodeValue || '').trim() === customHeadEndMarker) {
        removing = false;
        node.remove();
        return;
      }
      if (removing) {
        node.remove();
      }
    });
  }

  function normalizeJsMode(mode) {
    const value = String(mode || '').trim().toLowerCase();
    if (value === 'module') {
      return 'module';
    }
    if (value === 'classic' || value === 'auto') {
      return 'classic';
    }
    return 'classic';
  }

  function buildModuleRuntimeContext() {
    return {
      root: document,
      document: document,
      host: null,
      onCleanup: (fn) => {
        if (typeof fn === 'function') {
          jsCleanupCallbacks.push(fn);
        }
      },
    };
  }

  function runRegisteredJsCleanup() {
    if (!jsCleanupCallbacks.length) {
      return;
    }
    const callbacks = jsCleanupCallbacks.slice();
    jsCleanupCallbacks = [];
    callbacks.forEach((cleanup) => {
      try {
        cleanup();
      } catch (error) {
        console.error('[Kayzart] onCleanup callback failed.', error);
      }
    });
  }

  function revokeActiveModuleUrl() {
    if (!activeModuleUrl) {
      return;
    }
    try {
      URL.revokeObjectURL(activeModuleUrl);
    } catch (error) {
      // noop
    }
    activeModuleUrl = '';
  }

  function resetJsRuntime() {
    runRegisteredJsCleanup();
    removeScriptElement();
    revokeActiveModuleUrl();
  }

  function stopJsRuntime() {
    jsRunToken += 1;
    resetJsRuntime();
  }

  function runClassicJs(jsText) {
    const restoreDomReadyShim = document.readyState !== 'loading' ? applyDomReadyShim() : null;
    try {
      const scriptEl = document.createElement('script');
      scriptEl.id = scriptId;
      scriptEl.type = 'text/javascript';
      scriptEl.text = String(jsText);
      getInlineScriptHost().appendChild(scriptEl);
    } finally {
      if (restoreDomReadyShim) {
        restoreDomReadyShim();
      }
    }
  }

  async function runModuleJs(jsText, runToken) {
    const moduleUrl = URL.createObjectURL(
      new Blob([String(jsText)], { type: 'text/javascript' })
    );
    activeModuleUrl = moduleUrl;
    try {
      const moduleExports = await import(moduleUrl);
      if (runToken !== jsRunToken) {
        return;
      }
      const entry = moduleExports && moduleExports.default;
      if (typeof entry === 'function') {
        const context = buildModuleRuntimeContext();
        const maybeCleanup = entry(context);
        if (typeof maybeCleanup === 'function') {
          jsCleanupCallbacks.push(maybeCleanup);
        }
      }
    } catch (error) {
      if (runToken === jsRunToken) {
        console.error('[Kayzart] Module JS execution failed.', error);
      }
    } finally {
      if (activeModuleUrl === moduleUrl) {
        activeModuleUrl = '';
      }
      try {
        URL.revokeObjectURL(moduleUrl);
      } catch (error) {
        // noop
      }
    }
  }

  function runJs(jsText, jsMode) {
    const nextJsText = String(jsText || '');
    const runToken = ++jsRunToken;
    resetJsRuntime();
    if (!nextJsText.trim()) {
      return;
    }
    const resolvedMode = normalizeJsMode(jsMode);

    const currentReady = htmlScriptsReady;
    currentReady.then(() => {
      if (runToken !== jsRunToken || currentReady !== htmlScriptsReady) return;
      if (resolvedMode === 'module') {
        void runModuleJs(nextJsText, runToken);
        return;
      }
      runClassicJs(nextJsText);
    });
  }

  function applyDomReadyShim() {
    const docAdd = document.addEventListener;
    const winAdd = window.addEventListener;
    const schedule =
      typeof window.queueMicrotask === 'function'
        ? window.queueMicrotask.bind(window)
        : (fn) => window.setTimeout(fn, 0);
    const callListener = (target, type, listener) => {
      if (!listener) return;
      schedule(() => {
        try {
          if (typeof listener === 'function') {
            listener.call(target, new Event(type));
          } else if (typeof listener.handleEvent === 'function') {
            listener.handleEvent(new Event(type));
          }
        } catch (e) {
          // noop
        }
      });
    };
    const wrap = (target, original) => (type, listener, options) => {
      original.call(target, type, listener, options);
      if (type === 'DOMContentLoaded' || type === 'load') {
        callListener(target, type, listener);
      }
    };
    document.addEventListener = wrap(document, docAdd);
    window.addEventListener = wrap(window, winAdd);
    return () => {
      document.addEventListener = docAdd;
      window.addEventListener = winAdd;
    };
  }

  function findMarkers() {
    if (
      markerNodes &&
      markerNodes.start &&
      markerNodes.end &&
      markerNodes.start.isConnected &&
      markerNodes.end.isConnected
    ) {
      return markerNodes;
    }
    markerNodes = null;

    const root = document.documentElement || document.body;
    if (!root) return null;

    const matchesMarker = (node, type) => {
      if (node.getAttribute(markerAttr) !== type) {
        return false;
      }
      if (!markerPostId) {
        return true;
      }
      return node.getAttribute(markerPostAttr) === markerPostId;
    };

    const markers = [];
    const candidates = root.querySelectorAll('[' + markerAttr + ']');
    candidates.forEach((node) => {
      if (!(node instanceof Element)) {
        return;
      }
      const type = node.getAttribute(markerAttr);
      if (type !== markerStart && type !== markerEnd) {
        return;
      }
      if (markerPostId && node.getAttribute(markerPostAttr) !== markerPostId) {
        return;
      }
      markers.push(node);
    });

    if (!markers.length) {
      return null;
    }

    const body = document.body;
    const bodyMarkers = body ? markers.filter((node) => body.contains(node)) : [];

    const resolvePair = (list) => {
      if (!list.length) return null;

      // Normal case: start marker followed by end marker in document order.
      for (let i = 0; i < list.length; i += 1) {
        const start = list[i];
        if (!matchesMarker(start, markerStart)) continue;
        for (let j = i + 1; j < list.length; j += 1) {
          const end = list[j];
          if (!matchesMarker(end, markerEnd)) continue;
          return { start: start, end: end };
        }
      }

      // Fallback: parser recovery may reorder invalid HTML differently by browser.
      // Use first/last marker as a boundary pair when typed pair isn't available.
      if (list.length >= 2) {
        return { start: list[0], end: list[list.length - 1] };
      }

      return null;
    };

    // Prefer markers placed inside <body>; fallback to the whole document.
    const pair = resolvePair(bodyMarkers) || resolvePair(markers);
    if (pair) {
      markerNodes = pair;
      return markerNodes;
    }

    return null;
  }

  function reviveScripts(root) {
    const pending = [];
    const scripts = root.querySelectorAll ? Array.prototype.slice.call(root.querySelectorAll('script')) : [];
    scripts.forEach((node) => {
      const script = document.createElement('script');
      const hasAsyncAttr = node.hasAttribute('async');
      Array.prototype.forEach.call(node.attributes, (attr) => {
        script.setAttribute(attr.name, attr.value);
      });
      if (!hasAsyncAttr) {
        script.async = false;
      }
      script.text = node.text || node.textContent || '';
      if (script.src) {
        pending.push(
          new Promise((resolve) => {
            script.addEventListener('load', resolve, { once: true });
            script.addEventListener('error', resolve, { once: true });
          })
        );
      }
      node.replaceWith(script);
    });
    return pending.length ? Promise.all(pending).then(() => undefined) : Promise.resolve();
  }

  function queryLazyMedia(root, selector) {
    const matches = [];
    if (!root || !selector) {
      return matches;
    }
    if (root.nodeType === Node.ELEMENT_NODE && root.matches && root.matches(selector)) {
      matches.push(root);
    }
    if (root.querySelectorAll) {
      return matches.concat(Array.prototype.slice.call(root.querySelectorAll(selector)));
    }
    return matches;
  }

  function copyAttrIfMissing(root, selector, sourceAttr, targetAttr) {
    queryLazyMedia(root, selector).forEach((node) => {
      if (!node || !node.getAttribute || !node.setAttribute) {
        return;
      }
      if (node.getAttribute(targetAttr)) {
        return;
      }
      const value = node.getAttribute(sourceAttr);
      if (value) {
        node.setAttribute(targetAttr, value);
      }
    });
  }

  function formatBackgroundImage(value) {
    const trimmed = String(value || '').trim();
    if (!trimmed) {
      return '';
    }
    if (/^url\(/i.test(trimmed)) {
      return trimmed;
    }
    return 'url("' + trimmed.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '")';
  }

  function hasPreviewBackgroundImage(value) {
    const normalized = String(value || '').trim().toLowerCase();
    if (!normalized || normalized === 'none') {
      return false;
    }
    return !/^url\(\s*(?:""|''|)\s*\)$/.test(normalized);
  }

  function revealLazyBackgrounds(root) {
    queryLazyMedia(root, '[data-bg], [data-background], [data-background-image]').forEach((node) => {
      if (!node || !node.getAttribute || !node.style || hasPreviewBackgroundImage(node.style.backgroundImage)) {
        return;
      }
      const value =
        node.getAttribute('data-bg') ||
        node.getAttribute('data-background') ||
        node.getAttribute('data-background-image');
      const backgroundImage = formatBackgroundImage(value);
      if (backgroundImage) {
        node.style.backgroundImage = backgroundImage;
      }
    });
  }

  function revealLazyMedia(root) {
    copyAttrIfMissing(root, 'img[data-src], iframe[data-src], video[data-src], audio[data-src]', 'data-src', 'src');
    copyAttrIfMissing(root, 'img[data-srcset], source[data-srcset]', 'data-srcset', 'srcset');
    copyAttrIfMissing(root, 'img[data-lazy-src], iframe[data-lazy-src]', 'data-lazy-src', 'src');
    copyAttrIfMissing(root, 'img[data-lazy-srcset], source[data-lazy-srcset]', 'data-lazy-srcset', 'srcset');
    copyAttrIfMissing(root, 'img[data-original], iframe[data-original]', 'data-original', 'src');
    revealLazyBackgrounds(root);
  }

  function isShortcodeTextExcluded(node) {
    let parent = node && node.parentElement ? node.parentElement : node.parentNode;
    while (parent && parent.nodeType === 1) {
      const tagName = parent.tagName ? parent.tagName.toLowerCase() : '';
      if (
        tagName === 'script' ||
        tagName === 'style' ||
        tagName === 'textarea' ||
        tagName === 'template' ||
        tagName === 'code' ||
        tagName === 'pre'
      ) {
        return true;
      }
      if (parent.classList && parent.classList.contains('kayzart-shortcode-placeholder')) {
        return true;
      }
      parent = parent.parentElement;
    }
    return false;
  }

  function readShortcodeAt(text, start) {
    if (text.charAt(start) !== '[' || text.charAt(start + 1) === '[') {
      return null;
    }
    let index = start + 1;
    if (text.charAt(index) === '/') {
      return null;
    }
    const tagMatch = text.slice(index).match(/^([A-Za-z0-9_-]+)/);
    if (!tagMatch) {
      return null;
    }
    const tagName = tagMatch[1];
    index += tagName.length;
    const nextChar = text.charAt(index);
    if (nextChar && !/[\s/\]]/.test(nextChar)) {
      return null;
    }
    let quote = '';
    for (; index < text.length; index += 1) {
      const char = text.charAt(index);
      if (quote) {
        if (char === quote) {
          quote = '';
        }
        continue;
      }
      if (char === '"' || char === "'") {
        quote = char;
        continue;
      }
      if (char === ']') {
        const openEnd = index + 1;
        const openingRaw = text.slice(start, openEnd);
        const isSelfClosing = /\/\s*\]$/.test(openingRaw);
        if (isSelfClosing) {
          return {
            end: openEnd,
            tagName: tagName,
            raw: openingRaw,
          };
        }
        const closeMatch = findClosingShortcode(text, openEnd, tagName);
        if (closeMatch) {
          return {
            end: closeMatch.end,
            tagName: tagName,
            raw: text.slice(start, closeMatch.end),
          };
        }
        return {
          end: openEnd,
          tagName: tagName,
          raw: openingRaw,
        };
      }
    }
    return null;
  }

  function findClosingShortcode(text, start, tagName) {
    const closePattern = new RegExp('\\[/\\s*' + escapeRegExp(tagName) + '\\s*\\]', 'i');
    const match = closePattern.exec(text.slice(start));
    if (!match) {
      return null;
    }
    return {
      end: start + match.index + match[0].length,
    };
  }

  function escapeRegExp(value) {
    return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  function createShortcodePlaceholder(match) {
    const placeholder = document.createElement('span');
    placeholder.className = 'kayzart-shortcode-placeholder';
    placeholder.setAttribute('data-kayzart-shortcode', match.tagName);
    placeholder.setAttribute('title', match.raw);

    const name = document.createElement('span');
    name.className = 'kayzart-shortcode-placeholder__name';
    name.textContent = labels.shortcodeLabel + ': ' + match.tagName;

    const message = document.createElement('span');
    message.className = 'kayzart-shortcode-placeholder__message';
    message.textContent = labels.shortcodeUnavailable;

    placeholder.appendChild(name);
    placeholder.appendChild(message);
    return placeholder;
  }

  function buildShortcodeFragment(text) {
    const fragment = document.createDocumentFragment();
    let index = 0;
    let changed = false;

    while (index < text.length) {
      const openIndex = text.indexOf('[', index);
      if (openIndex === -1) {
        fragment.appendChild(document.createTextNode(text.slice(index)));
        break;
      }
      if (text.charAt(openIndex + 1) === '[') {
        fragment.appendChild(document.createTextNode(text.slice(index, openIndex + 2)));
        index = openIndex + 2;
        continue;
      }
      const match = readShortcodeAt(text, openIndex);
      if (!match) {
        fragment.appendChild(document.createTextNode(text.slice(index, openIndex + 1)));
        index = openIndex + 1;
        continue;
      }
      fragment.appendChild(document.createTextNode(text.slice(index, openIndex)));
      fragment.appendChild(createShortcodePlaceholder(match));
      index = match.end;
      changed = true;
    }

    return changed ? fragment : null;
  }

  function visualizeShortcodes(root) {
    if (!root || !document.createTreeWalker) return;
    ensureShortcodePreviewStyle();
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: (node) => {
        if (!node.nodeValue || node.nodeValue.indexOf('[') === -1) {
          return NodeFilter.FILTER_REJECT;
        }
        return isShortcodeTextExcluded(node) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
      },
    });
    const nodes = [];
    while (walker.nextNode()) {
      nodes.push(walker.currentNode);
    }
    nodes.forEach((node) => {
      const fragment = buildShortcodeFragment(node.nodeValue || '');
      if (fragment && node.parentNode) {
        node.parentNode.replaceChild(fragment, node);
      }
    });
  }

  function replaceEditableContent(html) {
    const markers = findMarkers();
    if (!markers) return Promise.resolve();

    const range = document.createRange();
    range.setStartAfter(markers.start);
    range.setEndBefore(markers.end);
    range.deleteContents();

    const wrapper = document.createElement('div');
    wrapper.innerHTML = html || '';
    visualizeShortcodes(wrapper);
    const scriptsReady = reviveScripts(wrapper);
    revealLazyMedia(wrapper);
    const frag = document.createDocumentFragment();
    while (wrapper.firstChild) {
      frag.appendChild(wrapper.firstChild);
    }
    range.insertNode(frag);
    range.detach();
    return scriptsReady;
  }

  function getMaxScrollLeft() {
    const doc = document.documentElement;
    const body = document.body;
    const scrollWidth = Math.max(
      doc ? doc.scrollWidth : 0,
      body ? body.scrollWidth : 0
    );
    const viewportWidth = window.innerWidth || (doc ? doc.clientWidth : 0) || 0;
    return Math.max(0, scrollWidth - viewportWidth);
  }

  function getMaxScrollTop() {
    const doc = document.documentElement;
    const body = document.body;
    const scrollHeight = Math.max(
      doc ? doc.scrollHeight : 0,
      body ? body.scrollHeight : 0
    );
    const viewportHeight = window.innerHeight || (doc ? doc.clientHeight : 0) || 0;
    return Math.max(0, scrollHeight - viewportHeight);
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(value, max));
  }

  function getScrollX() {
    return window.scrollX || window.pageXOffset || 0;
  }

  function getScrollY() {
    return window.scrollY || window.pageYOffset || 0;
  }

  function escapeAttrValue(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }
    return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  function isVisibleRect(rect) {
    return Boolean(
      rect &&
      rect.width > 0 &&
      rect.height > 0 &&
      rect.bottom > 0 &&
      rect.right > 0 &&
      rect.top < (window.innerHeight || 0) &&
      rect.left < (window.innerWidth || 0)
    );
  }

  function hasRenderableRect(rect) {
    return Boolean(rect && rect.width > 0 && rect.height > 0);
  }

  function getScrollAnchorLine() {
    const viewportHeight = window.innerHeight || 0;
    if (viewportHeight <= 1) {
      return 0;
    }
    return Math.min(Math.max(24, viewportHeight * 0.25), viewportHeight - 1);
  }

  function findScrollAnchor() {
    const markers = findMarkers();
    if (!markers) {
      return null;
    }
    const candidates = document.querySelectorAll
      ? Array.prototype.slice.call(document.querySelectorAll('[' + KAYZART_ATTR_NAME + ']'))
      : [];
    const anchorLine = getScrollAnchorLine();
    let best = null;

    candidates.forEach((node) => {
      if (!(node instanceof Element)) {
        return;
      }
      const lcId = node.getAttribute(KAYZART_ATTR_NAME);
      if (!lcId) {
        return;
      }
      const beforeStart = Boolean(
        markers.start.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_PRECEDING
      );
      const afterEnd = Boolean(
        markers.end.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_FOLLOWING
      );
      if (beforeStart || afterEnd) {
        return;
      }
      const rect = node.getBoundingClientRect();
      if (!isVisibleRect(rect)) {
        return;
      }
      const overlapsLine = rect.top <= anchorLine && rect.bottom >= anchorLine;
      const distance = overlapsLine
        ? 0
        : Math.min(Math.abs(rect.top - anchorLine), Math.abs(rect.bottom - anchorLine));
      const height = Math.max(1, rect.height);
      const center = rect.top + rect.height / 2;
      const centerDistance = Math.abs(center - anchorLine);
      const score =
        (overlapsLine ? 0 : 100000) +
        distance * 10 +
        Math.min(height, (window.innerHeight || 0) * 4 || height) +
        centerDistance * 0.05;
      if (
        !best ||
        (overlapsLine && !best.overlapsLine) ||
        (overlapsLine === best.overlapsLine && score < best.score)
      ) {
        best = {
          lcId: lcId,
          top: rect.top,
          left: rect.left,
          distance: distance,
          overlapsLine: overlapsLine,
          score: score,
        };
      }
    });

    if (!best) {
      return null;
    }

    const anchor = {
      lcId: best.lcId,
      top: best.top,
      left: best.left,
      distance: best.distance,
      overlapsLine: best.overlapsLine,
      score: best.score,
    };
    return anchor;
  }

  function captureScrollPosition(reason) {
    if (
      reason === 'render-before-dom-replace' &&
      pendingScrollRestoreSnapshot &&
      scrollSaveSuppressedUntil &&
      Date.now() < scrollSaveSuppressedUntil
    ) {
      return {
        x: pendingScrollRestoreSnapshot.x,
        y: pendingScrollRestoreSnapshot.y,
        anchor: pendingScrollRestoreSnapshot.anchor,
      };
    }
    const anchor = findScrollAnchor();
    return {
      x: getScrollX(),
      y: getScrollY(),
      anchor: anchor,
    };
  }

  function normalizeSavedScrollPosition(value) {
    if (!value || typeof value !== 'object') {
      return null;
    }
    const x = Number(value.x);
    const y = Number(value.y);
    const anchor = value.anchor && typeof value.anchor === 'object' ? value.anchor : null;
    return {
      x: Number.isFinite(x) ? x : 0,
      y: Number.isFinite(y) ? y : 0,
      anchor:
        anchor && typeof anchor.lcId === 'string'
          ? {
              lcId: anchor.lcId,
              top: Number.isFinite(Number(anchor.top)) ? Number(anchor.top) : 0,
              left: Number.isFinite(Number(anchor.left)) ? Number(anchor.left) : 0,
            }
          : null,
    };
  }

  function readSavedScrollPosition() {
    if (!scrollStorageKey) {
      return null;
    }
    try {
      if (!window.sessionStorage) {
        return null;
      }
      return normalizeSavedScrollPosition(
        JSON.parse(window.sessionStorage.getItem(scrollStorageKey) || 'null')
      );
    } catch (e) {
      return null;
    }
  }

  function saveScrollPosition() {
    if (!scrollStorageKey) {
      return;
    }
    try {
      if (!window.sessionStorage) {
        return;
      }
      window.sessionStorage.setItem(
        scrollStorageKey,
        JSON.stringify(captureScrollPosition('session-save'))
      );
    } catch (e) {
      // Ignore storage errors and keep the preview usable.
    }
  }

  function captureScrollSnapshot() {
    capturedScrollSnapshot = captureScrollPosition('captured-snapshot');
    capturedScrollRestoreBlockedUntil = 0;
  }

  function blockCapturedScrollRestore() {
    if (!capturedScrollSnapshot) {
      return;
    }
    capturedScrollRestoreBlockedUntil = Date.now() + 1200;
  }

  function queueScrollPositionSave() {
    if (initialSavedScrollRestorePending) {
      return;
    }
    if (scrollSaveSuppressedUntil && Date.now() < scrollSaveSuppressedUntil) {
      return;
    }
    if (scrollSaveTimer) {
      window.clearTimeout(scrollSaveTimer);
    }
    scrollSaveTimer = window.setTimeout(() => {
      scrollSaveTimer = 0;
      saveScrollPosition();
    }, 120);
  }

  function restoreSavedScrollPositionOnce() {
    if (!initialSavedScrollRestorePending) {
      return;
    }
    initialSavedScrollRestorePending = false;
    restoreSavedScrollPosition();
  }

  function restoreSavedScrollPosition() {
    const saved = readSavedScrollPosition();
    if (saved) {
      restoreScrollPosition(saved, 'saved-scroll');
    }
  }

  function restoreCapturedScrollPosition() {
    if (capturedScrollRestoreBlockedUntil && Date.now() < capturedScrollRestoreBlockedUntil) {
      return;
    }
    if (capturedScrollSnapshot) {
      restoreScrollPosition(capturedScrollSnapshot, 'captured-scroll');
    }
  }

  function findCurrentScrollAnchor(anchor) {
    if (!anchor || !anchor.lcId) {
      return null;
    }
    const selector = '[' + KAYZART_ATTR_NAME + '="' + escapeAttrValue(anchor.lcId) + '"]';
    const nodes = document.querySelectorAll
      ? Array.prototype.slice.call(document.querySelectorAll(selector))
      : [];
    if (!nodes.length) {
      return null;
    }

    const markers = findMarkers();
    let best = null;

    nodes.forEach((node) => {
      if (!(node instanceof Element)) {
        return;
      }
      const outsideMarkers = Boolean(
        markers &&
          ((markers.start.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_PRECEDING) ||
            (markers.end.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_FOLLOWING))
      );
      const rect = node.getBoundingClientRect();
      if (outsideMarkers || !hasRenderableRect(rect)) {
        return;
      }
      const distance = Math.abs(rect.top - anchor.top);
      if (!best || distance < best.distance) {
        best = {
          rect: rect,
          distance: distance,
        };
      }
    });

    if (!best) {
      return null;
    }

    return best.rect;
  }

  function resolveRestoredScrollY(snapshot) {
    const rect = findCurrentScrollAnchor(snapshot.anchor);
    if (rect) {
      const delta = rect.top - snapshot.anchor.top;
      return {
        y: clamp(getScrollY() + delta, 0, getMaxScrollTop()),
        mode: 'anchor',
      };
    }
    return {
      y: clamp(snapshot.y, 0, getMaxScrollTop()),
      mode: 'absolute-fallback',
      currentAnchor: null,
    };
  }

  function restoreScrollPosition(snapshot, reason) {
    if (!snapshot || (!snapshot.x && !snapshot.y && !snapshot.anchor)) {
      return;
    }
    const token = ++scrollRestoreToken;
    const startedAt = Date.now();
    const maxDeferredWait = reason === 'render-after-dom-replace' ? 1400 : 600;
    let cancelled = false;
    let listening = false;
    let releaseApplyingTimer = 0;
    const timers = [];
    pendingScrollRestoreSnapshot = snapshot;
    scrollSaveSuppressedUntil = Math.max(scrollSaveSuppressedUntil, Date.now() + maxDeferredWait + 250);

    const cleanup = () => {
      if (releaseApplyingTimer) {
        window.clearTimeout(releaseApplyingTimer);
        releaseApplyingTimer = 0;
      }
      while (timers.length) {
        window.clearTimeout(timers.pop());
      }
      if (applyingScrollRestoreToken === token) {
        applyingScrollRestoreToken = 0;
      }
      if (scrollRestoreToken === token) {
        pendingScrollRestoreSnapshot = null;
      }
      if (!listening) {
        return;
      }
      listening = false;
      window.removeEventListener('scroll', cancelFromUserScroll, true);
      window.removeEventListener('wheel', cancelFromUserIntent, true);
      window.removeEventListener('touchmove', cancelFromUserIntent, true);
      window.removeEventListener('keydown', cancelFromUserIntent, true);
    };

    const cancelFromUserIntent = () => {
      blockCapturedScrollRestore();
      pendingScrollRestoreSnapshot = null;
      cancelled = true;
      cleanup();
    };

    const cancelFromUserScroll = () => {
      if (applyingScrollRestoreToken === token) {
        return;
      }
      if (scrollSaveSuppressedUntil && Date.now() < scrollSaveSuppressedUntil) {
        return;
      }
      blockCapturedScrollRestore();
      pendingScrollRestoreSnapshot = null;
      cancelled = true;
      cleanup();
    };

    const apply = () => {
      if (cancelled || token !== scrollRestoreToken) {
        cleanup();
        return;
      }
      const maxY = getMaxScrollTop();
      const x = clamp(snapshot.x, 0, getMaxScrollLeft());
      const resolved = resolveRestoredScrollY(snapshot);
      const y = resolved.y;
      const targetWasClampedByHeight = snapshot.y > maxY && y >= maxY;
      const anchorMissingDuringRender =
        reason === 'render-after-dom-replace' && snapshot.anchor && resolved.mode !== 'anchor';
      const heightStillCollapsed =
        reason === 'render-after-dom-replace' &&
        snapshot.y > maxY &&
        maxY < snapshot.y * 0.85;
      const canDefer =
        Date.now() - startedAt < maxDeferredWait &&
        (anchorMissingDuringRender || heightStillCollapsed || targetWasClampedByHeight);
      if (canDefer) {
        return;
      }
      applyingScrollRestoreToken = token;
      window.scrollTo(x, y);
      if (releaseApplyingTimer) {
        window.clearTimeout(releaseApplyingTimer);
      }
      releaseApplyingTimer = window.setTimeout(() => {
        if (applyingScrollRestoreToken === token) {
          applyingScrollRestoreToken = 0;
        }
      }, 0);
    };

    const scheduleApply = (delay) => {
      timers.push(
        window.setTimeout(() => {
          apply();
        }, delay)
      );
    };

    apply();
    listening = true;
    window.addEventListener('scroll', cancelFromUserScroll, true);
    window.addEventListener('wheel', cancelFromUserIntent, true);
    window.addEventListener('touchmove', cancelFromUserIntent, true);
    window.addEventListener('keydown', cancelFromUserIntent, true);

    if (typeof window.requestAnimationFrame === 'function') {
      window.requestAnimationFrame(apply);
    }
    scheduleApply(60);
    scheduleApply(180);
    scheduleApply(360);
    if (reason === 'render-after-dom-replace') {
      scheduleApply(720);
      scheduleApply(1200);
    }
    scheduleApply(maxDeferredWait + 20);
    timers.push(window.setTimeout(cleanup, maxDeferredWait + 320));
  }

  function clearMarkerRetryTimer() {
    if (markerRetryTimer) {
      window.clearTimeout(markerRetryTimer);
      markerRetryTimer = 0;
    }
    markerRetryStartedAt = 0;
  }

  function queueMarkerRetry() {
    if (markerRetryTimer) return;
    if (!markerRetryStartedAt) {
      markerRetryStartedAt = Date.now();
    }
    markerRetryTimer = window.setTimeout(() => {
      markerRetryTimer = 0;
      const next = pendingRenderPayload;
      if (!next) return;
      if (findMarkers()) {
        pendingRenderPayload = null;
        clearMarkerRetryTimer();
        render(next.html, next.css, next.customHead, next.bodyAttrs, next.hasBody, next.templateMode);
        return;
      }
      if (Date.now() - markerRetryStartedAt >= markerRetryMaxWaitMs) {
        pendingRenderPayload = null;
        clearMarkerRetryTimer();
        notifyMissingMarkers();
        return;
      }
      queueMarkerRetry();
    }, markerRetryDelayMs);
  }

  function render(html, css, customHead, bodyAttrs, hasBody, templateMode) {
    if (!findMarkers()) {
      pendingRenderPayload = {
        html: html || '',
        css: css || '',
        customHead: customHead,
        bodyAttrs: bodyAttrs || {},
        hasBody: Boolean(hasBody),
        templateMode: templateMode || 'standalone',
      }
      queueMarkerRetry();
      return;
    }
    pendingRenderPayload = null;
    clearMarkerRetryTimer();

    const scrollPosition = captureScrollPosition('render-before-dom-replace');
    const contentScriptsReady = replaceEditableContent(html);
    applyBodyAttrs(bodyAttrs, hasBody, templateMode);
    const customHeadScriptsReady =
      customHead !== undefined ? setCustomHead(customHead) : Promise.resolve();
    htmlScriptsReady = Promise.all([contentScriptsReady, customHeadScriptsReady]).then(() => undefined);
    clearHighlight();
    clearSelection();
    const styleEl = ensureStyleElement();
    if (styleEl) {
      styleEl.textContent = css || '';
    }
    restoreScrollPosition(scrollPosition, 'render-after-dom-replace');
    restoreSavedScrollPositionOnce();
    const currentHtmlScriptsReady = htmlScriptsReady;
    currentHtmlScriptsReady.then(() => {
      if (currentHtmlScriptsReady === htmlScriptsReady) {
        reply('KAYZART_RENDERED');
      }
    });
  }

  function setCssText(css) {
    const scrollPosition = captureScrollPosition('css-before-update');
    const styleEl = ensureStyleElement();
    if (styleEl) {
      styleEl.textContent = css || '';
    }
    restoreScrollPosition(scrollPosition, 'css-after-update');
  }

  function reply(type, payload) {
    if (!window.parent || !allowedOrigin) return;
    try {
      window.parent.postMessage(
        Object.assign({ type: type }, payload || {}),
        allowedOrigin
      );
    } catch (e) {
      // noop
    }
  }

  function notifyMissingMarkers() {
    if (missingMarkersNotified) return;
    missingMarkersNotified = true;
    reply('KAYZART_MISSING_MARKERS');
  }

  window.addEventListener('message', (event) => {
    if (!allowedOrigin) return;
    if (event.origin !== allowedOrigin) return;
    if (event.source !== window.parent) return;
    const data = event.data || {};
    if (data.type === 'KAYZART_SAVE_SCROLL') {
      saveScrollPosition();
      return;
    }
    if (data.type === 'KAYZART_RESTORE_SAVED_SCROLL') {
      restoreSavedScrollPosition();
      return;
    }
    if (data.type === 'KAYZART_CAPTURE_SCROLL_SNAPSHOT') {
      captureScrollSnapshot();
      return;
    }
    if (data.type === 'KAYZART_RESTORE_CAPTURED_SCROLL') {
      restoreCapturedScrollPosition();
      return;
    }
    if (data.type === 'KAYZART_INIT') {
      isReady = true;
      reply('KAYZART_READY', { post_id: postId });
      return;
    }
    if (data.type === 'KAYZART_RENDER') {
      if (!isReady) return;
      if ('liveHighlightEnabled' in data) {
        setDomSelectorEnabled(Boolean(data.liveHighlightEnabled));
      }
      render(
        data.canonicalHTML,
        data.cssText,
        'customHead' in data ? data.customHead : undefined,
        data.bodyAttrs || {},
        Boolean(data.hasBody),
        data.templateMode || 'standalone'
      );
    }
    if (data.type === 'KAYZART_SET_CSS') {
      if (!isReady) return;
      setCssText(data.cssText);
    }
    if (data.type === 'KAYZART_SET_HIGHLIGHT') {
      if (!isReady) return;
      setDomSelectorEnabled(Boolean(data.liveHighlightEnabled));
    }
    if (data.type === 'KAYZART_SET_ELEMENTS_TAB_OPEN') {
      if (!isReady) return;
      setElementsTabOpen(Boolean(data.open));
    }
    if (data.type === 'KAYZART_RUN_JS') {
      if (!isReady) return;
      jsEnabled = true;
      runJs(data.jsText || '', data.jsMode);
    }
    if (data.type === 'KAYZART_DISABLE_JS') {
      if (!isReady) return;
      jsEnabled = false;
      stopJsRuntime();
    }
  });

  window.addEventListener('scroll', queueScrollPositionSave, { passive: true });
  window.addEventListener('beforeunload', () => {
    saveScrollPosition();
    stopJsRuntime();
  });
  window.addEventListener('pagehide', () => {
    saveScrollPosition();
    stopJsRuntime();
  });
  attachDomSelector();
})();

