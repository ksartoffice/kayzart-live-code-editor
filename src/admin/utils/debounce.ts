export function debounce<T extends (...args: any[]) => void>(fn: T, ms: number) {
  let timer: number | undefined;
  return (...args: Parameters<T>) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => fn(...args), ms);
  };
}

export type PreviewRenderScheduler = {
  schedule: () => void;
  markComplete: () => void;
};

export function createPreviewRenderScheduler(
  fn: () => void,
  delayMs = 350
): PreviewRenderScheduler {
  let timer: number | undefined;
  let queued = false;
  let running = false;
  let rerunAfterComplete = false;

  const flush = () => {
    timer = undefined;
    if (!queued) {
      return;
    }
    if (running) {
      rerunAfterComplete = true;
      return;
    }
    queued = false;
    running = true;
    fn();
  };

  const schedule = () => {
    queued = true;
    window.clearTimeout(timer);
    timer = window.setTimeout(flush, delayMs);
  };

  const markComplete = () => {
    if (!running) {
      return;
    }
    running = false;
    if (rerunAfterComplete || queued) {
      rerunAfterComplete = false;
      schedule();
    }
  };

  return {
    schedule,
    markComplete,
  };
}
