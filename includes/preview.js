(function () {
  const styleId = 'kayzart-style';
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
  const markerRetryDelayMs = 50;
  const markerRetryMaxWaitMs = 10000;
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
      '親要素へ移動'
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
      'HTMLをコピー'
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
      '削除'
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

  function replaceEditableContent(html) {
    const markers = findMarkers();
    if (!markers) return Promise.resolve();

    const range = document.createRange();
    range.setStartAfter(markers.start);
    range.setEndBefore(markers.end);
    range.deleteContents();

    const wrapper = document.createElement('div');
    wrapper.innerHTML = html || '';
    const scriptsReady = reviveScripts(wrapper);
    const frag = document.createDocumentFragment();
    while (wrapper.firstChild) {
      frag.appendChild(wrapper.firstChild);
    }
    range.insertNode(frag);
    range.detach();
    return scriptsReady;
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
    const currentHtmlScriptsReady = htmlScriptsReady;
    currentHtmlScriptsReady.then(() => {
      if (currentHtmlScriptsReady === htmlScriptsReady) {
        reply('KAYZART_RENDERED');
      }
    });
  }

  function setCssText(css) {
    const styleEl = ensureStyleElement();
    if (styleEl) {
      styleEl.textContent = css || '';
    }
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

  window.addEventListener('beforeunload', stopJsRuntime);
  window.addEventListener('pagehide', stopJsRuntime);
  attachDomSelector();
})();

