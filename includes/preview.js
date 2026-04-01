(function () {
  const styleId = 'kayzart-style';
  const scriptId = 'kayzart-script';
  const shadowContentId = 'kayzart-shadow-content';
  const shadowScriptsId = 'kayzart-shadow-scripts';
  const externalScriptAttr = 'data-kayzart-external-script';
  const externalStyleAttr = 'data-kayzart-external-style';
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
  let elementsTabOpen = false;
  let markerNodes = null;
  let externalScripts = [];
  let externalScriptsReady = Promise.resolve();
  let externalScriptsToken = 0;
  let externalStyles = [];
  let shadowEnabled = false;
  let shadowHost = null;
  let shadowRoot = null;
  let jsRunToken = 0;
  let jsCleanupCallbacks = [];
  let activeModuleUrl = '';
  let jsEnabled = false;
  let missingMarkersNotified = false;
  let pendingRenderPayload = null;
  let markerRetryTimer = 0;
  let markerRetryStartedAt = 0;
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

  function clearShadowHost() {
    if (shadowHost) {
      shadowHost.remove();
    }
    shadowHost = null;
    shadowRoot = null;
  }

  function ensureShadowHost() {
    if (shadowHost && shadowHost.isConnected) return shadowHost;
    const markers = findMarkers();
    if (!markers) return null;

    const range = document.createRange();
    range.setStartAfter(markers.start);
    range.setEndBefore(markers.end);
    range.deleteContents();

    const host = document.createElement('kayzart-output');
    if (postId) {
      host.setAttribute('data-post-id', String(postId));
    }
    range.insertNode(host);
    range.detach();

    shadowHost = host;
    shadowRoot = null;
    if (host.attachShadow) {
      try {
        shadowRoot = host.attachShadow({ mode: 'open' });
      } catch (e) {
        shadowRoot = null;
      }
    }
    const root = shadowRoot || host;
    const styleEl = document.createElement('style');
    styleEl.id = styleId;
    const contentEl = document.createElement('div');
    contentEl.id = shadowContentId;
    const scriptsEl = document.createElement('div');
    scriptsEl.id = shadowScriptsId;
    root.appendChild(styleEl);
    root.appendChild(contentEl);
    root.appendChild(scriptsEl);
    return shadowHost;
  }

  function ensureShadowRoot() {
    if (shadowRoot && shadowRoot.host && shadowRoot.host.isConnected) {
      return shadowRoot;
    }
    const host = ensureShadowHost();
    if (host && host.shadowRoot) {
      shadowRoot = host.shadowRoot;
    }
    return shadowRoot;
  }

  function ensureShadowContent(root) {
    let content = root.querySelector('#' + shadowContentId);
    if (!content) {
      content = document.createElement('div');
      content.id = shadowContentId;
      root.appendChild(content);
    }
    return content;
  }

  function ensureShadowScripts(root) {
    let scripts = root.querySelector('#' + shadowScriptsId);
    if (!scripts) {
      scripts = document.createElement('div');
      scripts.id = shadowScriptsId;
      root.appendChild(scripts);
    }
    return scripts;
  }

  function getStyleRoot() {
    if (!shadowEnabled) {
      return document.head || document.body;
    }
    const root = ensureShadowRoot();
    return root || null;
  }

  function getScriptHost() {
    if (!shadowEnabled) {
      return document.head || document.body;
    }
    const root = ensureShadowRoot();
    if (!root) return document.head || document.body;
    return ensureShadowScripts(root);
  }

  function getInlineScriptHost() {
    if (!shadowEnabled) {
      return document.body || document.head;
    }
    const root = ensureShadowRoot();
    if (root && root.host) {
      return root.host;
    }
    return document.body || document.head;
  }

  function setShadowDomEnabled(enabled) {
    const next = Boolean(enabled);
    if (shadowEnabled === next) return;
    stopJsRuntime();
    shadowEnabled = next;
    removeStyleElement();
    clearExternalScripts();
    clearExternalStyles();
    if (!shadowEnabled) {
      clearShadowHost();
    } else {
      ensureShadowHost();
    }
    if (externalScripts.length) {
      externalScriptsReady = loadExternalScripts(externalScripts);
    }
    if (externalStyles.length) {
      loadExternalStyles(externalStyles);
    }
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

    document.body.appendChild(group);
    selectActionGroup = group;
    return group;
  }

  function hideSelectActionButtons() {
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
    const buttonCount = (showAddonButton ? 1 : 0) + (showEditButton ? 1 : 0);
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
    drawSelection(target);
    const lcId = target.getAttribute(KAYZART_ATTR_NAME);
    if (lcId) {
      reply('KAYZART_SELECT', { lcId: lcId });
    }
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
    const roots = [];
    if (shadowRoot) {
      roots.push(shadowRoot);
    }
    roots.push(document);
    roots.forEach((root) => {
      const scriptEl = root.querySelector('#' + scriptId);
      if (scriptEl) {
        scriptEl.remove();
      }
    });
  }

  function removeStyleElement() {
    const roots = [];
    if (shadowRoot) {
      roots.push(shadowRoot);
    }
    roots.push(document);
    roots.forEach((root) => {
      const styleEl = root.querySelector('#' + styleId);
      if (styleEl) {
        styleEl.remove();
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

  function getRuntimeHost() {
    if (shadowEnabled) {
      const host = ensureShadowHost();
      if (host) {
        return host;
      }
    }
    const existing = document.querySelector('kayzart-output');
    return existing instanceof HTMLElement ? existing : null;
  }

  function buildModuleRuntimeContext() {
    const host = getRuntimeHost();
    const root = shadowEnabled ? ensureShadowRoot() || document : document;
    return {
      root: root,
      document: document,
      host: host,
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
        console.error('[KayzArt] onCleanup callback failed.', error);
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
        console.error('[KayzArt] Module JS execution failed.', error);
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

    const currentReady = externalScriptsReady;
    currentReady.then(() => {
      if (runToken !== jsRunToken || currentReady !== externalScriptsReady) return;
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

  function normalizeExternalScripts(list) {
    if (!Array.isArray(list)) return [];
    return list
      .map((entry) => String(entry).trim())
      .filter(Boolean);
  }

  function normalizeExternalStyles(list) {
    if (!Array.isArray(list)) return [];
    return list
      .map((entry) => String(entry).trim())
      .filter(Boolean);
  }

  function isSameList(a, b) {
    if (a.length !== b.length) return false;
    return a.every((value, index) => value === b[index]);
  }

  function clearExternalScripts() {
    const roots = [];
    if (shadowRoot) {
      roots.push(shadowRoot);
    }
    roots.push(document);
    roots.forEach((root) => {
      const nodes = root.querySelectorAll('script[' + externalScriptAttr + ']');
      nodes.forEach((node) => node.remove());
    });
  }

  function clearExternalStyles() {
    const roots = [];
    if (shadowRoot) {
      roots.push(shadowRoot);
    }
    roots.push(document);
    roots.forEach((root) => {
      const nodes = root.querySelectorAll('link[' + externalStyleAttr + ']');
      nodes.forEach((node) => node.remove());
    });
  }

  function loadExternalScripts(list) {
    externalScriptsToken += 1;
    const token = externalScriptsToken;
    clearExternalScripts();
    if (!list.length) {
      return Promise.resolve();
    }

    const head = getScriptHost();
    return list.reduce((chain, url) => {
      return chain.then(
        () =>
          new Promise((resolve) => {
            if (token !== externalScriptsToken) {
              resolve();
              return;
            }
            const scriptEl = document.createElement('script');
            scriptEl.setAttribute(externalScriptAttr, '1');
            scriptEl.async = false;
            scriptEl.src = url;
            scriptEl.onload = () => resolve();
            scriptEl.onerror = () => resolve();
            head.appendChild(scriptEl);
          })
      );
    }, Promise.resolve());
  }

  function loadExternalStyles(list) {
    clearExternalStyles();
    if (!list.length) {
      return;
    }

    const root = getStyleRoot();
    const host = root || document.head || document.body;
    list.forEach((url) => {
      const linkEl = document.createElement('link');
      linkEl.setAttribute(externalStyleAttr, '1');
      linkEl.rel = 'stylesheet';
      linkEl.href = url;
      host.appendChild(linkEl);
    });
  }

  function setExternalScripts(list) {
    const next = normalizeExternalScripts(list);
    if (isSameList(next, externalScripts)) return;
    externalScripts = next;
    externalScriptsReady = loadExternalScripts(next);
  }

  function setExternalStyles(list) {
    const next = normalizeExternalStyles(list);
    if (isSameList(next, externalStyles)) return;
    externalStyles = next;
    loadExternalStyles(next);
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

  function replaceEditableContent(html) {
    const markers = findMarkers();
    if (!markers) return;

    const range = document.createRange();
    range.setStartAfter(markers.start);
    range.setEndBefore(markers.end);
    range.deleteContents();

    const wrapper = document.createElement('div');
    wrapper.innerHTML = html || '';
    hydrateDeclarativeShadowDom(wrapper);
    const frag = document.createDocumentFragment();
    while (wrapper.firstChild) {
      frag.appendChild(wrapper.firstChild);
    }
    range.insertNode(frag);
    range.detach();
  }

  function renderShadow(html, css) {
    const root = ensureShadowRoot();
    if (!root) return;
    const content = ensureShadowContent(root);
    content.innerHTML = html || '';
    hydrateDeclarativeShadowDom(content);
    const styleEl = ensureStyleElement();
    if (styleEl) {
      styleEl.textContent = css || '';
    }
    clearHighlight();
    clearSelection();
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
        render(next.html, next.css);
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

  function render(html, css) {
    if (!findMarkers()) {
      pendingRenderPayload = {
        html: html || '',
        css: css || '',
      }
      queueMarkerRetry();
      return;
    }
    pendingRenderPayload = null;
    clearMarkerRetryTimer();

    if (shadowEnabled) {
      renderShadow(html, css);
      reply('KAYZART_RENDERED');
      return;
    }
    clearShadowHost();
    replaceEditableContent(html);
    clearHighlight();
    clearSelection();
    const styleEl = ensureStyleElement();
    if (styleEl) {
      styleEl.textContent = css || '';
    }
    reply('KAYZART_RENDERED');
  }

  function hydrateDeclarativeShadowDom(root) {
    if (!root || typeof root.querySelectorAll !== 'function') return;
    const templates = root.querySelectorAll('template[shadowrootmode]');
    templates.forEach((tpl) => {
      const host = tpl.parentElement;
      if (!host) return;
      if (host.hasAttribute('data-kayzart-shadow-hydrated')) return;
      const modeAttr = tpl.getAttribute('shadowrootmode');
      const mode = modeAttr === 'closed' ? 'closed' : 'open';
      try {
        const shadow = host.attachShadow({ mode: mode });
        shadow.appendChild(tpl.content.cloneNode(true));
        host.setAttribute('data-kayzart-shadow-hydrated', '1');
        tpl.remove();
      } catch (e) {
        // noop
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
      setShadowDomEnabled(Boolean(data.shadowDomEnabled));
      if ('liveHighlightEnabled' in data) {
        setDomSelectorEnabled(Boolean(data.liveHighlightEnabled));
      }
      render(data.canonicalHTML, data.cssText);
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
    if (data.type === 'KAYZART_EXTERNAL_SCRIPTS') {
      if (!isReady) return;
      setExternalScripts(data.urls || []);
    }
    if (data.type === 'KAYZART_EXTERNAL_STYLES') {
      if (!isReady) return;
      setExternalStyles(data.urls || []);
    }
  });

  window.addEventListener('beforeunload', stopJsRuntime);
  window.addEventListener('pagehide', stopJsRuntime);
  attachDomSelector();
})();

