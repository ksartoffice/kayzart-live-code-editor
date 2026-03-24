import { createElement, createRoot, render } from '@wordpress/element';

type SnackbarStatus = 'success' | 'error' | 'info' | 'warning';

type NoticesDeps = {
  wp: any;
};

const NOTICE_STORE = 'core/notices';
const NOTICE_OFFSET_GAP_PX = 8;

export const NOTICE_IDS = {
  editor: 'kayzart-editor',
  save: 'kayzart-save',
  export: 'kayzart-export',
  tailwind: 'kayzart-tailwind',
  templateFallback: 'kayzart-template-fallback',
  media: 'kayzart-media',
} as const;

export const NOTICE_SUCCESS_DURATION_MS = 3000;
export const NOTICE_ERROR_DURATION_MS = 5000;

function syncNoticeOffset() {
  const toolbar = document.querySelector('.kayzart-toolbar') as HTMLElement | null;
  if (!toolbar) {
    return;
  }
  const base = toolbar.getBoundingClientRect().bottom + NOTICE_OFFSET_GAP_PX;
  const list = document.querySelector('.kayzart-noticeHost .components-snackbar-list') as HTMLElement | null;
  const noticeContainer = list?.querySelector('.components-snackbar-list__notices') as HTMLElement | null;
  const firstNotice = noticeContainer?.firstElementChild as HTMLElement | null;
  const noticeHeight = firstNotice?.getBoundingClientRect().height ?? 0;
  const offset = Math.max(0, Math.round(base + noticeHeight));
  document.documentElement.style.setProperty('--kayzart-notice-offset-top', `${offset}px`);
}

function removeNoticeRaw(wp: any, id: string) {
  if (!wp?.data?.dispatch) {
    return;
  }
  wp.data.dispatch(NOTICE_STORE).removeNotice(id);
  window.setTimeout(() => {
    syncNoticeOffset();
  }, 0);
}

export function createNotices(deps: NoticesDeps) {
  const { wp } = deps;

  const createSnackbar = (
    status: SnackbarStatus,
    message: string,
    id?: string,
    autoDismissMs?: number
  ) => {
    if (!wp?.data?.dispatch) {
      return;
    }
    const options: Record<string, unknown> = {
      type: 'snackbar',
      isDismissible: true,
    };
    if (id) {
      options.id = id;
    }
    wp.data.dispatch(NOTICE_STORE).createNotice(status, message, options);
    window.setTimeout(() => {
      syncNoticeOffset();
    }, 0);
    if (id && autoDismissMs) {
      window.setTimeout(() => {
        removeNoticeRaw(wp, id);
      }, autoDismissMs);
    }
  };

  const removeNotice = (id: string) => {
    removeNoticeRaw(wp, id);
  };

  const mountNotices = () => {
    if (!wp?.components?.SnackbarList || !wp?.data?.useSelect) {
      return;
    }
    if (document.querySelector('.kayzart-noticeHost')) {
      return;
    }
    const host = document.createElement('div');
    host.className = 'kayzart-noticeHost';
    document.body.append(host);

    const SnackbarList = wp.components.SnackbarList;
    const useSelect = wp.data.useSelect;
    const Notices = () => {
      const notices = useSelect((select: any) => select(NOTICE_STORE).getNotices(), []);
      const snackbarNotices = Array.isArray(notices)
        ? notices.filter((notice: any) => notice.type === 'snackbar')
        : [];
      return createElement(SnackbarList, {
        notices: snackbarNotices,
        onRemove: (id: string) => removeNotice(id),
      });
    };

    const root = typeof createRoot === 'function' ? createRoot(host) : null;
    const node = createElement(Notices);
    if (root) {
      root.render(node);
    } else {
      render(node, host);
    }
    window.setTimeout(() => {
      syncNoticeOffset();
    }, 0);
  };

  return {
    createSnackbar,
    removeNotice,
    mountNotices,
    syncNoticeOffset,
  };
}
