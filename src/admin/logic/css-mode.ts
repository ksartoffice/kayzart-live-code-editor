import { cloneCssByMode, type CssByMode, type EditorCssMode } from '../types/css-mode';

export const TAILWIND_DEFAULT_CSS = '@import "tailwindcss";\n\n@theme {\n  /* ... */\n}\n';

export const createInitialTailwindSource = (normalCss: string): string => {
  if (/@import\s+(?:url\(\s*)?["']tailwindcss["']/i.test(normalCss)) {
    return normalCss;
  }
  return normalCss.trim()
    ? `${TAILWIND_DEFAULT_CSS}\n${normalCss.trimStart()}`
    : TAILWIND_DEFAULT_CSS;
};

export async function resolveCssModeChange(params: {
  currentMode: EditorCssMode;
  nextMode: EditorCssMode;
  currentCss: string;
  cssByMode: CssByMode;
  compileTailwind: () => Promise<string | null>;
}): Promise<{ css: string; cssByMode: CssByMode }> {
  const nextCssByMode = cloneCssByMode(params.cssByMode);
  nextCssByMode[params.currentMode] = params.currentCss;

  let nextCss = nextCssByMode[params.nextMode];
  if (nextCss === null && params.nextMode === 'tailwind') {
    nextCss = createInitialTailwindSource(params.currentCss);
  } else if (nextCss === null) {
    nextCss = await params.compileTailwind();
    if (nextCss === null) {
      throw new Error('tailwind_compile_failed');
    }
  }

  nextCssByMode[params.nextMode] = nextCss;
  return {
    css: nextCss,
    cssByMode: nextCssByMode,
  };
}
