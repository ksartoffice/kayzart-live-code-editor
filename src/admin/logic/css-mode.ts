import { cloneCssByMode, type CssByMode, type EditorCssMode } from '../types/css-mode';

export const TAILWIND_DEFAULT_CSS = '@import "tailwindcss";\n\n@theme {\n  /* ... */\n}\n';

/**
 * Matches the Tailwind entry import. Kept in sync by hand with
 * Ai_Css_Imports::TAILWIND_IMPORT_PATTERN, which rejects AI edits that drop it.
 */
export const TAILWIND_IMPORT_PATTERN = /@import\s+(?:url\(\s*)?["']tailwindcss["']/i;

const CSS_COMMENT_PATTERN = /\/\*[\s\S]*?\*\//g;

/**
 * Whether the source pulls in the Tailwind entry point.
 *
 * Comments are stripped first: a commented-out import matches the pattern but
 * compiles to nothing, so treating it as present would seed a Tailwind page
 * that produces no utilities at all.
 */
export const hasTailwindImport = (css: string): boolean =>
  TAILWIND_IMPORT_PATTERN.test(css.replace(CSS_COMMENT_PATTERN, ''));

export const createInitialTailwindSource = (normalCss: string): string => {
  if (hasTailwindImport(normalCss)) {
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
