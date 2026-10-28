/**
 * Business Motion: edit HTML attributes, not this JavaScript, for AI changes.
 * Root: data-kz-motion="on|off" (off disables all effects).
 * Elements: data-kz-effect="reveal|float|parallax".
 * data-kz-duration: milliseconds, 100..4500 (reveal 700, float 4200).
 * data-kz-delay: milliseconds, 0..500 (default 0).
 * data-kz-distance: pixels, 0..48 for reveal (20), 0..16 otherwise (8).
 * Invalid numbers use defaults. No expressions, selectors, or resource URLs.
 * Examples: set data-kz-distance="4" to reduce motion; root "off" stops it.
 * Runtime states are held outside the HTML. Every effect is an enhancement:
 * without JS, observers, or Web Animations, the complete content stays visible.
 * A reveal already on screen when the page loads is left as painted (no
 * flash); reveals play as elements scroll in or are added/changed later.
 */
export default function mountBusinessMotion({ root, onCleanup }) {
  const doc = root.nodeType === 9 ? root : root.ownerDocument;
  const win = doc.defaultView;
  if (!win || !win.MutationObserver || !win.requestAnimationFrame) return;
  const reduced = win.matchMedia('(prefers-reduced-motion: reduce)');
  const pointer = win.matchMedia('(hover: hover) and (pointer: fine)');
  const states = new Map();
  let alive = true;
  let mounted = false;
  let syncFrame = 0;
  let pointerFrame = 0;
  let targetX = 0;
  let targetY = 0;
  let currentX = 0;
  let currentY = 0;

  function number(element, name, fallback, maximum, minimum = 0) {
    const raw = (element.getAttribute(name) || '').trim();
    if (!/^\d+(?:\.\d+)?$/.test(raw)) return fallback;
    const value = Number(raw);
    return Number.isFinite(value) ? Math.min(maximum, Math.max(minimum, value)) : fallback;
  }
  function config(element) {
    const owner = element.closest('[data-kz-motion]');
    const effect = element.getAttribute('data-kz-effect');
    const enabled = owner && owner.getAttribute('data-kz-motion') !== 'off' && !reduced.matches;
    if (!enabled || !['reveal', 'float', 'parallax'].includes(effect)) return null;
    if (effect === 'parallax' && !pointer.matches) return null;
    return {
      effect,
      duration: number(element, 'data-kz-duration', effect === 'float' ? 4200 : 700, 4500, 100),
      delay: number(element, 'data-kz-delay', 0, 500),
      distance: number(element, 'data-kz-distance', effect === 'reveal' ? 20 : 8, effect === 'reveal' ? 48 : 16),
    };
  }
  function cancel(state) {
    if (state.animation) state.animation.cancel();
    if (observer) observer.unobserve(state.element);
  }
  function animate(state) {
    if (!state.element.animate) return;
    const { effect, duration, delay, distance } = state.config;
    const frames = effect === 'float'
      ? [{ transform: 'translateY(0)' }, { transform: `translateY(-${distance}px)`, offset: 0.5 }, { transform: 'translateY(0)' }]
      : [{ opacity: 0, transform: `translateY(${distance}px)` }, { opacity: 1, transform: 'translateY(0)' }];
    state.animation = state.element.animate(frames, { duration, delay, easing: 'cubic-bezier(.2,.65,.3,1)', fill: 'backwards', iterations: 1 });
    if (doc.hidden) state.animation.pause();
  }
  const observer = win.IntersectionObserver ? new win.IntersectionObserver(entries => {
    for (const entry of entries) {
      const state = states.get(entry.target);
      if (!state || !entry.isIntersecting) continue;
      if (state.config.effect === 'reveal') {
        animate(state);
        observer.unobserve(entry.target);
      }
    }
  }, { threshold: 0.08 }) : null;

  function sync() {
    syncFrame = 0;
    if (!alive) return;
    const elements = new Set(root.querySelectorAll('[data-kz-effect]'));
    for (const [element, state] of states) {
      if (!elements.has(element) || !element.isConnected) { cancel(state); states.delete(element); }
    }
    for (const element of elements) {
      const next = config(element);
      const signature = JSON.stringify(next);
      const previous = states.get(element);
      if (previous && previous.signature === signature) continue;
      if (previous) { cancel(previous); states.delete(element); }
      if (!next) continue;
      const state = { element, config: next, signature, animation: null };
      states.set(element, state);
      if (next.effect === 'reveal') {
        // Hiding content the visitor can already see would make it flicker.
        if (!mounted && onScreen(element)) continue;
        if (observer) observer.observe(element);
      } else if (next.effect === 'float') {
        animate(state);
      } else if (element.animate) {
        state.animation = element.animate([{ transform: 'translate(0, 0)' }], { duration: 1, fill: 'both' });
        state.animation.pause();
      }
    }
    mounted = true;
    schedulePointer();
  }
  function onScreen(element) {
    const rect = element.getBoundingClientRect();
    return rect.bottom > 0 && rect.right > 0 && rect.top < win.innerHeight && rect.left < win.innerWidth;
  }
  function scheduleSync() {
    if (alive && !syncFrame) syncFrame = win.requestAnimationFrame(sync);
  }
  function movePointer(event) {
    targetX = (event.clientX / Math.max(1, win.innerWidth) - 0.5) * 2;
    targetY = (event.clientY / Math.max(1, win.innerHeight) - 0.5) * 2;
    schedulePointer();
  }
  function resetPointer() { targetX = 0; targetY = 0; schedulePointer(); }
  function schedulePointer() {
    if (alive && !doc.hidden && !pointerFrame && pointer.matches && !reduced.matches && Array.from(states.values()).some(state => state.config.effect === 'parallax')) {
      pointerFrame = win.requestAnimationFrame(updatePointer);
    }
  }
  function updatePointer() {
    pointerFrame = 0;
    if (!alive || doc.hidden) return;
    currentX += (targetX - currentX) * 0.14;
    currentY += (targetY - currentY) * 0.14;
    for (const state of states.values()) {
      if (state.config.effect !== 'parallax' || !state.animation) continue;
      const distance = state.config.distance;
      state.animation.effect.setKeyframes([{ transform: `translate(${(currentX * distance).toFixed(2)}px, ${(currentY * distance).toFixed(2)}px)` }]);
    }
    if (Math.abs(currentX - targetX) + Math.abs(currentY - targetY) > 0.002) schedulePointer();
  }
  function visibility() {
    for (const state of states.values()) {
      if (!state.animation || state.config.effect === 'parallax') continue;
      if (doc.hidden && state.animation.playState === 'running') state.animation.pause();
      else if (!doc.hidden && state.animation.playState === 'paused') state.animation.play();
    }
    if (doc.hidden && pointerFrame) { win.cancelAnimationFrame(pointerFrame); pointerFrame = 0; }
    else schedulePointer();
  }
  const mutations = new win.MutationObserver(scheduleSync);
  mutations.observe(root.nodeType === 9 ? doc.documentElement : root, {
    subtree: true, childList: true, attributes: true,
    attributeFilter: ['data-kz-motion', 'data-kz-effect', 'data-kz-duration', 'data-kz-delay', 'data-kz-distance'],
  });
  doc.addEventListener('pointermove', movePointer, { passive: true });
  doc.addEventListener('pointerleave', resetPointer);
  doc.addEventListener('visibilitychange', visibility);
  const listen = (media, fn) => media.addEventListener ? media.addEventListener('change', fn) : media.addListener(fn);
  const unlisten = (media, fn) => media.removeEventListener ? media.removeEventListener('change', fn) : media.removeListener(fn);
  listen(reduced, scheduleSync);
  listen(pointer, scheduleSync);
  sync();
  onCleanup(() => {
    alive = false;
    mutations.disconnect();
    if (observer) observer.disconnect();
    if (syncFrame) win.cancelAnimationFrame(syncFrame);
    if (pointerFrame) win.cancelAnimationFrame(pointerFrame);
    for (const state of states.values()) cancel(state);
    states.clear();
    doc.removeEventListener('pointermove', movePointer);
    doc.removeEventListener('pointerleave', resetPointer);
    doc.removeEventListener('visibilitychange', visibility);
    unlisten(reduced, scheduleSync);
    unlisten(pointer, scheduleSync);
  });
}
