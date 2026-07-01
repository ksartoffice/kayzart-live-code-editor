import { afterEach, describe, expect, it, vi } from 'vitest';
import { createPreviewRenderScheduler } from '../../../../src/admin/utils/debounce';

describe('createPreviewRenderScheduler', () => {
  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it('coalesces continuous changes into one trailing render', () => {
    vi.useFakeTimers();
    const render = vi.fn();
    const scheduler = createPreviewRenderScheduler(render, 350);

    scheduler.schedule();
    vi.advanceTimersByTime(100);
    scheduler.schedule();
    vi.advanceTimersByTime(100);
    scheduler.schedule();
    vi.advanceTimersByTime(349);

    expect(render).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1);

    expect(render).toHaveBeenCalledTimes(1);
  });

  it('runs one latest render after an in-flight render completes', () => {
    vi.useFakeTimers();
    const render = vi.fn();
    const scheduler = createPreviewRenderScheduler(render, 350);

    scheduler.schedule();
    vi.advanceTimersByTime(350);
    expect(render).toHaveBeenCalledTimes(1);

    scheduler.schedule();
    vi.advanceTimersByTime(350);
    expect(render).toHaveBeenCalledTimes(1);

    scheduler.schedule();
    vi.advanceTimersByTime(350);
    expect(render).toHaveBeenCalledTimes(1);

    scheduler.markComplete();
    vi.advanceTimersByTime(349);
    expect(render).toHaveBeenCalledTimes(1);

    vi.advanceTimersByTime(1);
    expect(render).toHaveBeenCalledTimes(2);
  });
});
