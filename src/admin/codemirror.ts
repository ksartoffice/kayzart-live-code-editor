import { basicSetup } from 'codemirror';
import {
  Compartment,
  EditorState,
  StateEffect,
  StateField,
  type Extension,
  type Range,
  type StateCommand,
} from '@codemirror/state';
import {
  Decoration,
  EditorView,
  keymap,
  type DecorationSet,
  type KeyBinding,
} from '@codemirror/view';
import { undo, redo, undoDepth, redoDepth } from '@codemirror/commands';
import { html as htmlLanguage } from '@codemirror/lang-html';
import { css as cssLanguage } from '@codemirror/lang-css';
import { javascript as javascriptLanguage } from '@codemirror/lang-javascript';
import { oneDark } from '@codemirror/theme-one-dark';
import {
  abbreviationTracker,
  emmetConfig,
  EmmetKnownSyntax,
  expandAbbreviation,
} from '@emmetio/codemirror6-plugin';

export type WordWrapMode = 'off' | 'on';

export type EditorPosition = {
  lineNumber: number;
  column: number;
};

export class EditorRange {
  public readonly startLineNumber: number;
  public readonly startColumn: number;
  public readonly endLineNumber: number;
  public readonly endColumn: number;

  constructor(
    startLineNumber: number,
    startColumn: number,
    endLineNumber: number,
    endColumn: number
  ) {
    this.startLineNumber = startLineNumber;
    this.startColumn = startColumn;
    this.endLineNumber = endLineNumber;
    this.endColumn = endColumn;
  }

  getEndPosition(): EditorPosition {
    return {
      lineNumber: this.endLineNumber,
      column: this.endColumn,
    };
  }
}

export class EditorSelection extends EditorRange {}

export type EditorDecoration = {
  range: EditorRange;
  options: {
    className?: string;
    inlineClassName?: string;
  };
};

export type EditorModel = {
  getValue: () => string;
  getPositionAt: (offset: number) => EditorPosition;
  getOffsetAt: (position: EditorPosition) => number;
  onDidChangeContent: (listener: () => void) => () => void;
  canUndo: () => boolean;
  canRedo: () => boolean;
  pushEditOperations: (
    _beforeSelections: EditorSelection[],
    operations: Array<{ range: EditorRange; text: string }>,
    computeCursorState?: (inverseOperations: Array<{ range: EditorRange; text: string }>) =>
      | EditorSelection[]
      | null
  ) => EditorSelection[] | null;
  deltaDecorations: (oldDecorationIds: string[], decorations: EditorDecoration[]) => string[];
};

export type CodeEditorInstance = {
  focus: () => void;
  getModel: () => EditorModel;
  onDidFocusEditorText: (listener: () => void) => () => void;
  getSelection: () => EditorSelection | null;
  getPosition: () => EditorPosition;
  setSelection: (selection: EditorSelection) => void;
  pushUndoStop: () => void;
  trigger: (_source: string, action: 'undo' | 'redo', _payload: unknown) => void;
  revealRangeInCenter: (range: EditorRange, _scrollType?: number) => void;
  updateOptions: (options: { wordWrap?: WordWrapMode }) => void;
  addAction: (action: {
    id: string;
    label: string;
    keybindings?: number[];
    contextMenuGroupId?: string;
    contextMenuOrder?: number;
    run: () => void | Promise<void>;
  }) => void;
};

export type CodeMirrorType = {
  Range: typeof EditorRange;
  Selection: typeof EditorSelection;
  KeyMod: {
    CtrlCmd: number;
    Alt: number;
  };
  KeyCode: {
    KeyS: number;
    KeyZ: number;
  };
  editor: {
    ScrollType: {
      Smooth: number;
    };
    OverviewRulerLane: {
      Full: number;
    };
  };
};

export type CodeMirrorSetup = {
  codemirror: CodeMirrorType;
  htmlModel: EditorModel;
  cssModel: EditorModel;
  jsModel: EditorModel;
  htmlEditor: CodeEditorInstance;
  cssEditor: CodeEditorInstance;
  jsEditor: CodeEditorInstance;
};

type CodeMirrorInitOptions = {
  initialHtml: string;
  initialCss: string;
  initialJs: string;
  htmlWordWrap: WordWrapMode;
  tailwindEnabled: boolean;
  useTailwindDefault: boolean;
  canEditJs: boolean;
  htmlContainer: HTMLElement;
  cssContainer: HTMLElement;
  jsContainer: HTMLElement;
};

type DecorationSpec = {
  from: number;
  to: number;
  className?: string;
  inlineClassName?: string;
};

type EditorWrapper = {
  model: EditorModel;
  editor: CodeEditorInstance;
};

const DEFAULT_TAILWIND_CSS =
  '@import "tailwindcss";\n' +
  '\n' +
  '@theme {\n' +
  '  /* ... */\n' +
  '}\n';

const DECORATION_EFFECT = StateEffect.define<DecorationSpec[]>();

const buildDecorationSet = (specs: DecorationSpec[], state: EditorState): DecorationSet => {
  const ranges: Range<Decoration>[] = [];

  specs.forEach((spec) => {
    const from = Math.max(0, Math.min(spec.from, state.doc.length));
    const to = Math.max(from, Math.min(spec.to, state.doc.length));

    if (spec.inlineClassName && to > from) {
      ranges.push(
        Decoration.mark({ class: spec.inlineClassName }).range(from, to)
      );
    }

    if (spec.className) {
      const line = state.doc.lineAt(from);
      ranges.push(Decoration.line({ class: spec.className }).range(line.from));
    }
  });

  return Decoration.set(ranges, true);
};

const decorationField = StateField.define<DecorationSet>({
  create() {
    return Decoration.none;
  },
  update(value, transaction) {
    let next = value.map(transaction.changes);
    for (const effect of transaction.effects) {
      if (effect.is(DECORATION_EFFECT)) {
        next = buildDecorationSet(effect.value, transaction.state);
      }
    }
    return next;
  },
  provide(field) {
    return EditorView.decorations.from(field);
  },
});

const KEY_MOD_CTRL_CMD = 1 << 11;
const KEY_MOD_ALT = 1 << 9;
const KEY_CODE_S = 1;
const KEY_CODE_Z = 2;

const lineWrappingExtension = (mode: WordWrapMode): Extension =>
  mode === 'on' ? EditorView.lineWrapping : [];

const lineColumnToOffset = (state: EditorState, position: EditorPosition): number => {
  const lineNumber = Math.max(1, Math.min(position.lineNumber, state.doc.lines));
  const line = state.doc.line(lineNumber);
  const raw = line.from + Math.max(0, position.column - 1);
  return Math.max(line.from, Math.min(raw, line.to));
};

const offsetToPosition = (state: EditorState, offset: number): EditorPosition => {
  const clamped = Math.max(0, Math.min(offset, state.doc.length));
  const line = state.doc.lineAt(clamped);
  return {
    lineNumber: line.number,
    column: clamped - line.from + 1,
  };
};

const rangeToOffsets = (
  state: EditorState,
  range: EditorRange
): { from: number; to: number } => {
  const from = lineColumnToOffset(state, {
    lineNumber: range.startLineNumber,
    column: range.startColumn,
  });
  const to = lineColumnToOffset(state, {
    lineNumber: range.endLineNumber,
    column: range.endColumn,
  });

  return {
    from: Math.min(from, to),
    to: Math.max(from, to),
  };
};

const offsetsToRange = (state: EditorState, from: number, to: number): EditorRange => {
  const start = offsetToPosition(state, from);
  const end = offsetToPosition(state, to);
  return new EditorRange(start.lineNumber, start.column, end.lineNumber, end.column);
};

const decodeKeybinding = (binding: number): string | null => {
  const hasCtrlCmd = (binding & KEY_MOD_CTRL_CMD) !== 0;
  const hasAlt = (binding & KEY_MOD_ALT) !== 0;
  const code = binding & 0xff;

  const key = code === KEY_CODE_S ? 's' : code === KEY_CODE_Z ? 'z' : null;
  if (!key) {
    return null;
  }

  const segments: string[] = [];
  if (hasCtrlCmd) {
    segments.push('Mod');
  }
  if (hasAlt) {
    segments.push('Alt');
  }
  segments.push(key);
  return segments.join('-');
};

const emmetExtensions = (syntax: EmmetKnownSyntax): Extension[] => [
  emmetConfig.of({ syntax }),
  abbreviationTracker({ syntax }),
  keymap.of([
    {
      key: 'Tab',
      run: expandAbbreviation,
    },
  ]),
];

const createEditorWrapper = (options: {
  parent: HTMLElement;
  initialValue: string;
  language: Extension;
  emmet?: EmmetKnownSyntax;
  readOnly?: boolean;
  wordWrap?: WordWrapMode;
}): EditorWrapper => {
  const wrapCompartment = new Compartment();
  const editableCompartment = new Compartment();
  const actionKeymapCompartment = new Compartment();
  const changeListeners = new Set<() => void>();
  const focusListeners = new Set<() => void>();
  const actionKeymaps: KeyBinding[] = [];
  let decorationIdSeq = 0;
  const activeDecorations = new Map<string, DecorationSpec>();

  const updateActionKeymaps = (view: EditorView) => {
    view.dispatch({
      effects: actionKeymapCompartment.reconfigure(keymap.of(actionKeymaps)),
    });
  };

  const syncDecorations = (view: EditorView) => {
    view.dispatch({
      effects: DECORATION_EFFECT.of(Array.from(activeDecorations.values())),
    });
  };

  const updateListener = EditorView.updateListener.of((update) => {
    if (update.docChanged) {
      changeListeners.forEach((listener) => listener());
    }
    if (update.focusChanged && update.view.hasFocus) {
      focusListeners.forEach((listener) => listener());
    }
  });

  const extensions: Extension[] = [
    basicSetup,
    oneDark,
    wrapCompartment.of(lineWrappingExtension(options.wordWrap ?? 'off')),
    editableCompartment.of(EditorView.editable.of(!options.readOnly)),
    actionKeymapCompartment.of(keymap.of(actionKeymaps)),
    options.language,
    decorationField,
    updateListener,
  ];

  if (options.emmet) {
    extensions.push(...emmetExtensions(options.emmet));
  }

  const state = EditorState.create({
    doc: options.initialValue,
    extensions,
  });

  options.parent.textContent = '';
  const view = new EditorView({
    state,
    parent: options.parent,
  });

  const model: EditorModel = {
    getValue: () => view.state.doc.toString(),
    getPositionAt: (offset) => offsetToPosition(view.state, offset),
    getOffsetAt: (position) => lineColumnToOffset(view.state, position),
    onDidChangeContent: (listener) => {
      changeListeners.add(listener);
      return () => {
        changeListeners.delete(listener);
      };
    },
    canUndo: () => undoDepth(view.state) > 0,
    canRedo: () => redoDepth(view.state) > 0,
    pushEditOperations: (_beforeSelections, operations, computeCursorState) => {
      if (!operations.length) {
        return null;
      }

      const sorted = operations
        .map((operation) => {
          const offsets = rangeToOffsets(view.state, operation.range);
          return {
            ...offsets,
            text: operation.text,
          };
        })
        .sort((a, b) => b.from - a.from);

      const changes = sorted.map((operation) => ({
        from: operation.from,
        to: operation.to,
        insert: operation.text,
      }));

      view.dispatch({ changes });

      const inverseOperations = sorted
        .slice()
        .reverse()
        .map((operation) => {
          const from = operation.from;
          const to = operation.from + operation.text.length;
          return {
            range: offsetsToRange(view.state, from, to),
            text: '',
          };
        });

      const nextSelections = computeCursorState ? computeCursorState(inverseOperations) : null;
      if (nextSelections && nextSelections[0]) {
        const first = nextSelections[0];
        const anchor = lineColumnToOffset(view.state, {
          lineNumber: first.startLineNumber,
          column: first.startColumn,
        });
        const head = lineColumnToOffset(view.state, {
          lineNumber: first.endLineNumber,
          column: first.endColumn,
        });
        view.dispatch({
          selection: {
            anchor,
            head,
          },
          scrollIntoView: true,
        });
      }

      return nextSelections;
    },
    deltaDecorations: (oldDecorationIds, decorations) => {
      oldDecorationIds.forEach((id) => {
        activeDecorations.delete(id);
      });

      const nextIds = decorations.map((decoration) => {
        const id = `d-${++decorationIdSeq}`;
        const offsets = rangeToOffsets(view.state, decoration.range);
        activeDecorations.set(id, {
          from: offsets.from,
          to: offsets.to,
          className: decoration.options.className,
          inlineClassName: decoration.options.inlineClassName,
        });
        return id;
      });

      syncDecorations(view);
      return nextIds;
    },
  };

  const editor: CodeEditorInstance = {
    focus: () => {
      view.focus();
    },
    getModel: () => model,
    onDidFocusEditorText: (listener) => {
      focusListeners.add(listener);
      return () => {
        focusListeners.delete(listener);
      };
    },
    getSelection: () => {
      const main = view.state.selection.main;
      const start = offsetToPosition(view.state, main.from);
      const end = offsetToPosition(view.state, main.to);
      return new EditorSelection(start.lineNumber, start.column, end.lineNumber, end.column);
    },
    getPosition: () => offsetToPosition(view.state, view.state.selection.main.head),
    setSelection: (selection) => {
      const anchor = lineColumnToOffset(view.state, {
        lineNumber: selection.startLineNumber,
        column: selection.startColumn,
      });
      const head = lineColumnToOffset(view.state, {
        lineNumber: selection.endLineNumber,
        column: selection.endColumn,
      });
      view.dispatch({ selection: { anchor, head }, scrollIntoView: true });
    },
    pushUndoStop: () => {
      // no-op for CodeMirror; transactions already split by user actions.
    },
    trigger: (_source, action, _payload) => {
      if (action === 'undo') {
        undo(view);
        return;
      }
      if (action === 'redo') {
        redo(view);
      }
    },
    revealRangeInCenter: (range) => {
      const offsets = rangeToOffsets(view.state, range);
      view.dispatch({
        effects: EditorView.scrollIntoView(offsets.from, { y: 'center' }),
      });
    },
    updateOptions: (opts) => {
      if (opts.wordWrap) {
        view.dispatch({
          effects: wrapCompartment.reconfigure(lineWrappingExtension(opts.wordWrap)),
        });
      }
    },
    addAction: (action) => {
      const bindings = (action.keybindings || [])
        .map((binding) => decodeKeybinding(binding))
        .filter((binding): binding is string => Boolean(binding));

      bindings.forEach((binding) => {
        const command: StateCommand = () => {
          void action.run();
          return true;
        };
        actionKeymaps.push({ key: binding, run: command });
      });

      updateActionKeymaps(view);
    },
  };

  return { model, editor };
};

export async function initCodeMirrorEditors(options: CodeMirrorInitOptions): Promise<CodeMirrorSetup> {
  const initialCss =
    options.tailwindEnabled && options.initialCss.trim() === '' && options.useTailwindDefault
      ? DEFAULT_TAILWIND_CSS
      : options.initialCss;

  const htmlWrapper = createEditorWrapper({
    parent: options.htmlContainer,
    initialValue: options.initialHtml ?? '',
    language: htmlLanguage(),
    emmet: EmmetKnownSyntax.html,
    wordWrap: options.htmlWordWrap,
  });

  const cssWrapper = createEditorWrapper({
    parent: options.cssContainer,
    initialValue: initialCss ?? '',
    language: cssLanguage(),
    emmet: EmmetKnownSyntax.css,
  });

  const jsWrapper = createEditorWrapper({
    parent: options.jsContainer,
    initialValue: options.initialJs ?? '',
    language: javascriptLanguage(),
    readOnly: !options.canEditJs,
  });

  return {
    codemirror: {
      Range: EditorRange,
      Selection: EditorSelection,
      KeyMod: {
        CtrlCmd: KEY_MOD_CTRL_CMD,
        Alt: KEY_MOD_ALT,
      },
      KeyCode: {
        KeyS: KEY_CODE_S,
        KeyZ: KEY_CODE_Z,
      },
      editor: {
        ScrollType: {
          Smooth: 0,
        },
        OverviewRulerLane: {
          Full: 0,
        },
      },
    },
    htmlModel: htmlWrapper.model,
    cssModel: cssWrapper.model,
    jsModel: jsWrapper.model,
    htmlEditor: htmlWrapper.editor,
    cssEditor: cssWrapper.editor,
    jsEditor: jsWrapper.editor,
  };
}
