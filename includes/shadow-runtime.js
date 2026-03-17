(function () {
  const payloadSelector = 'script[data-kayzart-js]';
  const processedAttr = 'data-kayzart-js-run';
  const waitAttr = 'data-kayzart-js-wait';
  const modeAttr = 'data-kayzart-js-mode';
  const runtimeState = new Map();

  function decodePayload(value) {
    if (!value) return '';
    try {
      return decodeURIComponent(value);
    } catch (e) {
      return value;
    }
  }

  function getRuntimeState(payload) {
    const existing = runtimeState.get(payload);
    if (existing) {
      return existing;
    }
    const nextState = {
      cleanups: [],
      runToken: 0,
      activeModuleUrl: '',
    };
    runtimeState.set(payload, nextState);
    return nextState;
  }

  function runCleanupCallbacks(payload) {
    const state = getRuntimeState(payload);
    state.runToken += 1;
    if (state.activeModuleUrl) {
      try {
        URL.revokeObjectURL(state.activeModuleUrl);
      } catch (error) {
        // noop
      }
      state.activeModuleUrl = '';
    }
    if (!state.cleanups.length) {
      return;
    }
    const callbacks = state.cleanups.slice();
    state.cleanups = [];
    callbacks.forEach((cleanup) => {
      try {
        cleanup();
      } catch (error) {
        console.error('[KayzArt] onCleanup callback failed.', error);
      }
    });
  }

  function normalizeScriptMode(mode) {
    const value = String(mode || '').trim().toLowerCase();
    if (value === 'module') {
      return 'module';
    }
    if (value === 'classic' || value === 'auto') {
      return 'classic';
    }
    return 'classic';
  }

  function buildRuntimeContext(payload, host) {
    const state = getRuntimeState(payload);
    const root = host && host.shadowRoot ? host.shadowRoot : document;
    return {
      root: root,
      document: document,
      host: host || null,
      onCleanup: (fn) => {
        if (typeof fn === 'function') {
          state.cleanups.push(fn);
        }
      },
    };
  }

  function runClassicPayload(host, jsText) {
    const scriptEl = document.createElement('script');
    scriptEl.type = 'text/javascript';
    scriptEl.text = jsText;
    (host || document.body || document.documentElement).appendChild(scriptEl);
  }

  async function runModulePayload(payload, host, jsText) {
    const state = getRuntimeState(payload);
    const runToken = ++state.runToken;
    const moduleUrl = URL.createObjectURL(
      new Blob([String(jsText)], { type: 'text/javascript' })
    );
    state.activeModuleUrl = moduleUrl;
    try {
      const moduleExports = await import(moduleUrl);
      if (state.runToken !== runToken) {
        return;
      }
      const entry = moduleExports && moduleExports.default;
      if (typeof entry !== 'function') {
        console.error('[KayzArt] Module JS default export must be a function.');
        return;
      }
      const context = buildRuntimeContext(payload, host);
      const maybeCleanup = entry(context);
      if (typeof maybeCleanup === 'function') {
        state.cleanups.push(maybeCleanup);
      }
    } catch (error) {
      if (state.runToken === runToken) {
        console.error('[KayzArt] Module JS execution failed.', error);
      }
    } finally {
      if (state.activeModuleUrl === moduleUrl) {
        state.activeModuleUrl = '';
      }
      try {
        URL.revokeObjectURL(moduleUrl);
      } catch (error) {
        // noop
      }
    }
  }

  function runPayload(payload) {
    if (!payload || payload.hasAttribute(processedAttr)) return;
    const host = payload.closest('kayzart-output');
    const raw = payload.textContent || '';
    const jsText = decodePayload(raw);
    runCleanupCallbacks(payload);
    if (!jsText.trim()) {
      payload.setAttribute(processedAttr, '1');
      return;
    }
    const scriptMode = normalizeScriptMode(payload.getAttribute(modeAttr));
    if (scriptMode === 'module') {
      void runModulePayload(payload, host, jsText);
    } else {
      runClassicPayload(host, jsText);
    }
    payload.setAttribute(processedAttr, '1');
  }

  function runPending(force) {
    const payloads = document.querySelectorAll(payloadSelector);
    payloads.forEach((payload) => {
      if (!force && payload.hasAttribute(waitAttr)) {
        return;
      }
      runPayload(payload);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => runPending(false));
  } else {
    runPending(false);
  }

  window.addEventListener('load', () => runPending(true));
  window.addEventListener('beforeunload', () => {
    runtimeState.forEach((state, payload) => {
      runCleanupCallbacks(payload);
      state.cleanups = [];
    });
  });
})();
