(function () {
  const payloadSelector = 'script[data-kayzart-js]';
  const processedAttr = 'data-kayzart-js-run';
  const waitAttr = 'data-kayzart-js-wait';
  const modeAttr = 'data-kayzart-js-mode';

  function decodePayload(value) {
    if (!value) return '';
    try {
      return decodeURIComponent(value);
    } catch (e) {
      return value;
    }
  }

  function runPayload(payload) {
    if (!payload || payload.hasAttribute(processedAttr)) return;
    const host = payload.closest('kayzart-output');
    const raw = payload.textContent || '';
    const jsText = decodePayload(raw);
    if (!jsText.trim()) {
      payload.setAttribute(processedAttr, '1');
      return;
    }
    const scriptMode = resolveScriptMode(payload.getAttribute(modeAttr), jsText);
    const scriptEl = document.createElement('script');
    scriptEl.type = scriptMode === 'module' ? 'module' : 'text/javascript';
    scriptEl.text = jsText;
    (host || document.body || document.documentElement).appendChild(scriptEl);
    payload.setAttribute(processedAttr, '1');
  }

  function normalizeScriptMode(mode) {
    const value = String(mode || '').toLowerCase();
    if (value === 'classic' || value === 'module') {
      return value;
    }
    return 'auto';
  }

  function stripJsForModeDetection(code) {
    const source = String(code || '');
    return source
      .replace(/\/\*[\s\S]*?\*\//g, ' ')
      .replace(/\/\/[^\n\r]*/g, ' ')
      .replace(/(["'`])(?:\\[\s\S]|(?!\1)[\s\S])*\1/g, ' ');
  }

  function resolveScriptMode(mode, jsText) {
    const normalized = normalizeScriptMode(mode);
    if (normalized !== 'auto') {
      return normalized;
    }

    const source = stripJsForModeDetection(jsText);
    const hasStaticImport =
      /(^|[;\n\r])\s*import\s+(?:[\w*\s{},]+\s+from\s*)?['"][^'"]+['"]\s*;?/m.test(source) ||
      /(^|[;\n\r])\s*export\s+(?:\{|\*|default|class|function|const|let|var)\b/m.test(source) ||
      /\bimport\.meta\b/.test(source);
    return hasStaticImport ? 'module' : 'classic';
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
})();
