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
  GutterMarker,
  ViewPlugin,
  crosshairCursor,
  drawSelection,
  dropCursor,
  gutter,
  highlightActiveLine,
  highlightActiveLineGutter,
  highlightSpecialChars,
  keymap,
  lineNumbers,
  rectangularSelection,
  type DecorationSet,
  type KeyBinding,
  type ViewUpdate,
} from '@codemirror/view';
import {
  defaultKeymap,
  history,
  historyKeymap,
  redo,
  redoDepth,
  undo,
  undoDepth,
} from '@codemirror/commands';
import {
  autocompletion,
  closeBrackets,
  closeBracketsKeymap,
  completionKeymap,
  startCompletion,
} from '@codemirror/autocomplete';
import {
  defaultHighlightStyle,
  foldGutter,
  foldKeymap,
  indentOnInput,
  syntaxHighlighting,
} from '@codemirror/language';
import { highlightSelectionMatches, searchKeymap } from '@codemirror/search';
import { lintKeymap } from '@codemirror/lint';
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

export type EditorScrollRulerMarker = {
  range: EditorRange;
  className?: string;
  title?: string;
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
  setUnsavedChangeLines: (lineNumbers: number[]) => void;
  setUnsavedDeletionLines: (lineNumbers: number[]) => void;
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
  setScrollRulerMarkers: (markers: EditorScrollRulerMarker[]) => void;
  clearScrollRulerMarkers: () => void;
  updateOptions: (options: { wordWrap?: WordWrapMode }) => void;
  addAction: (action: {
    id: string;
    label: string;
    keybindings?: number[];
    run: () => void | Promise<void>;
  }) => void;
  setLocked: (locked: boolean) => void;
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
};

export type CodeMirrorSetup = {
  codemirror: CodeMirrorType;
  htmlModel: EditorModel;
  customHeadModel: EditorModel;
  cssModel: EditorModel;
  jsModel: EditorModel;
  htmlEditor: CodeEditorInstance;
  customHeadEditor: CodeEditorInstance;
  cssEditor: CodeEditorInstance;
  jsEditor: CodeEditorInstance;
};

type CodeMirrorInitOptions = {
  initialHtml: string;
  initialCustomHead: string;
  initialCss: string;
  initialJs: string;
  htmlWordWrap: WordWrapMode;
  tailwindEnabled: boolean;
  useTailwindDefault: boolean;
  canEditJs: boolean;
  htmlContainer: HTMLElement;
  customHeadContainer: HTMLElement;
  cssContainer: HTMLElement;
  jsContainer: HTMLElement;
  onHtmlPaste?: (text: string) => boolean;
};

type DecorationSpec = {
  from: number;
  to: number;
  className?: string;
  inlineClassName?: string;
};

type ScrollRulerMarkerSpec = {
  from: number;
  to: number;
  className?: string;
  title?: string;
};

type EditorWrapper = {
  model: EditorModel;
  editor: CodeEditorInstance;
};

const DECORATION_EFFECT = StateEffect.define<DecorationSpec[]>();
const SCROLL_RULER_MARKERS_EFFECT = StateEffect.define<ScrollRulerMarkerSpec[]>();
const UNSAVED_CHANGE_LINES_EFFECT = StateEffect.define<number[]>();
const UNSAVED_DELETION_LINES_EFFECT = StateEffect.define<number[]>();

class UnsavedChangeMarker extends GutterMarker {
  toDOM() {
    const marker = document.createElement('span');
    marker.className = 'kayzart-unsaved-change-marker';
    return marker;
  }
}

const unsavedChangeMarker = new UnsavedChangeMarker();

class UnsavedDeletionMarker extends GutterMarker {
  toDOM() {
    const marker = document.createElement('span');
    marker.className = 'kayzart-unsaved-deletion-marker';
    return marker;
  }
}

const unsavedDeletionMarker = new UnsavedDeletionMarker();

const unsavedChangeLinesField = StateField.define<ReadonlySet<number>>({
  create() {
    return new Set();
  },
  update(value, transaction) {
    for (const effect of transaction.effects) {
      if (effect.is(UNSAVED_CHANGE_LINES_EFFECT)) {
        return new Set(effect.value);
      }
    }
    return value;
  },
});

const unsavedDeletionLinesField = StateField.define<ReadonlySet<number>>({
  create() {
    return new Set();
  },
  update(value, transaction) {
    for (const effect of transaction.effects) {
      if (effect.is(UNSAVED_DELETION_LINES_EFFECT)) {
        return new Set(effect.value);
      }
    }
    return value;
  },
});

const unsavedChangeGutter = gutter({
  class: 'kayzart-unsaved-change-gutter',
  lineMarker(view, line) {
    const lineNumber = view.state.doc.lineAt(line.from).number;
    if (view.state.field(unsavedChangeLinesField).has(lineNumber)) {
      return unsavedChangeMarker;
    }
    return view.state.field(unsavedDeletionLinesField).has(lineNumber)
      ? unsavedDeletionMarker
      : null;
  },
  lineMarkerChange(update) {
    return (
      update.startState.field(unsavedChangeLinesField) !==
        update.state.field(unsavedChangeLinesField) ||
      update.startState.field(unsavedDeletionLinesField) !==
        update.state.field(unsavedDeletionLinesField)
    );
  },
});

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

const scrollRulerMarkersField = StateField.define<ScrollRulerMarkerSpec[]>({
  create() {
    return [];
  },
  update(value, transaction) {
    let next = transaction.docChanged
      ? value.map((marker) => ({
          ...marker,
          from: transaction.changes.mapPos(marker.from),
          to: transaction.changes.mapPos(marker.to),
        }))
      : value;

    for (const effect of transaction.effects) {
      if (effect.is(SCROLL_RULER_MARKERS_EFFECT)) {
        next = effect.value;
      }
    }

    return next;
  },
});

const SCROLL_RULER_MARKER_HEIGHT = 4;
const SCROLL_RULER_MIN_VIEWPORT_HEIGHT = 18;

const clampNumber = (value: number, min: number, max: number): number =>
  Math.max(min, Math.min(value, max));

const scrollRulerPlugin = ViewPlugin.fromClass(
  class {
    private readonly dom: HTMLElement;
    private readonly viewport: HTMLElement;
    private readonly markersLayer: HTMLElement;
    private markerSpecs: ScrollRulerMarkerSpec[] = [];
    private readonly markerElements: HTMLElement[] = [];
    private measureQueued = false;
    private rafId: number | null = null;
    private resizeObserver: ResizeObserver | null = null;

    constructor(private readonly view: EditorView) {
      this.dom = document.createElement('div');
      this.dom.className = 'kayzart-scrollRuler';

      this.viewport = document.createElement('div');
      this.viewport.className = 'kayzart-scrollRulerViewport';
      this.viewport.setAttribute('aria-hidden', 'true');
      this.markersLayer = document.createElement('div');
      this.markersLayer.className = 'kayzart-scrollRulerMarkers';

      this.dom.append(this.viewport, this.markersLayer);
      this.view.dom.appendChild(this.dom);

      this.markerSpecs = this.view.state.field(scrollRulerMarkersField);
      this.renderMarkerElements();

      this.view.scrollDOM.addEventListener('scroll', this.handleScroll, { passive: true });
      if (typeof ResizeObserver !== 'undefined') {
        this.resizeObserver = new ResizeObserver(() => this.queueMeasure());
        this.resizeObserver.observe(this.view.dom);
        this.resizeObserver.observe(this.view.scrollDOM);
      }
      this.queueMeasure();
    }

    update(update: ViewUpdate) {
      const nextMarkerSpecs = update.state.field(scrollRulerMarkersField);
      const markersChanged = nextMarkerSpecs !== this.markerSpecs;
      if (nextMarkerSpecs !== this.markerSpecs) {
        this.markerSpecs = nextMarkerSpecs;
        this.renderMarkerElements();
      }

      if (
        update.geometryChanged ||
        update.viewportChanged ||
        update.docChanged ||
        markersChanged
      ) {
        this.queueMeasure();
      }
    }

    destroy() {
      this.view.scrollDOM.removeEventListener('scroll', this.handleScroll);
      if (this.resizeObserver) {
        this.resizeObserver.disconnect();
      }
      if (this.rafId !== null) {
        window.cancelAnimationFrame(this.rafId);
      }
      this.dom.remove();
    }

    private readonly handleScroll = () => {
      this.queueMeasure();
    };

    private queueMeasure() {
      if (this.measureQueued) {
        return;
      }
      this.measureQueued = true;
      this.rafId = window.requestAnimationFrame(() => {
        this.rafId = null;
        this.measureQueued = false;
        this.view.requestMeasure({
          read: () => this.readMeasure(),
          write: (measure) => this.writeMeasure(measure),
        });
      });
    }

    private readMeasure() {
      const laneHeight = this.dom.clientHeight;
      const scroller = this.view.scrollDOM;
      const scrollHeight = scroller.scrollHeight;
      const clientHeight = scroller.clientHeight;
      const hidden =
        laneHeight <= 0 ||
        clientHeight <= 0 ||
        scrollHeight <= 0 ||
        this.view.dom.getClientRects().length === 0;

      if (hidden) {
        return {
          visible: false,
          markerTops: [] as number[],
          viewportTop: 0,
          viewportHeight: 0,
        };
      }

      const viewportHeight = clampNumber(
        (clientHeight / scrollHeight) * laneHeight,
        SCROLL_RULER_MIN_VIEWPORT_HEIGHT,
        laneHeight
      );
      const maxScrollTop = Math.max(0, scrollHeight - clientHeight);
      const maxViewportTop = Math.max(0, laneHeight - viewportHeight);
      const viewportTop =
        maxScrollTop > 0
          ? clampNumber((scroller.scrollTop / maxScrollTop) * maxViewportTop, 0, maxViewportTop)
          : 0;
      const maxTop = Math.max(0, laneHeight - SCROLL_RULER_MARKER_HEIGHT);
      const paddingTop = this.view.documentPadding.top;
      const markerTops = this.markerSpecs.map((marker) => {
        const from = clampNumber(marker.from, 0, this.view.state.doc.length);
        const block = this.view.lineBlockAt(from);
        const documentY = paddingTop + block.top + block.height / 2;
        const targetScrollTop = clampNumber(
          documentY - clientHeight / 2,
          0,
          maxScrollTop
        );
        const markerCenter =
          maxScrollTop > 0
            ? (targetScrollTop / maxScrollTop) * maxViewportTop + viewportHeight / 2
            : (documentY / scrollHeight) * laneHeight;
        return clampNumber(
          markerCenter - SCROLL_RULER_MARKER_HEIGHT / 2,
          0,
          maxTop
        );
      });

      return {
        visible: true,
        markerTops,
        viewportTop,
        viewportHeight,
      };
    }

    private writeMeasure(measure: ReturnType<typeof this.readMeasure>) {
      this.dom.classList.toggle('is-hidden', !measure.visible);
      this.dom.classList.toggle('has-markers', this.markerSpecs.length > 0);
      if (!measure.visible) {
        return;
      }

      const viewportTop = clampNumber(
        measure.viewportTop,
        0,
        Math.max(0, this.dom.clientHeight - measure.viewportHeight)
      );
      this.viewport.style.top = `${viewportTop}px`;
      this.viewport.style.height = `${measure.viewportHeight}px`;

      this.markerElements.forEach((element, index) => {
        const top = measure.markerTops[index] ?? 0;
        element.style.top = `${top}px`;
      });
    }

    private renderMarkerElements() {
      this.markersLayer.textContent = '';
      this.markerElements.length = 0;

      this.markerSpecs.forEach((marker, index) => {
        const element = document.createElement('button');
        element.type = 'button';
        element.className = ['kayzart-scrollRulerMarker', marker.className]
          .filter(Boolean)
          .join(' ');
        element.setAttribute('aria-label', marker.title || 'Highlighted code location');
        if (marker.title) {
          element.title = marker.title;
        }
        element.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          this.scrollToMarker(index);
        });
        this.markersLayer.appendChild(element);
        this.markerElements.push(element);
      });

      this.queueMeasure();
    }

    private scrollToMarker(index: number) {
      const marker = this.markerSpecs[index];
      if (!marker) {
        return;
      }

      this.view.requestMeasure({
        read: () => {
          const scroller = this.view.scrollDOM;
          const paddingTop = this.view.documentPadding.top;
          const from = clampNumber(marker.from, 0, this.view.state.doc.length);
          const block = this.view.lineBlockAt(from);
          const documentY = paddingTop + block.top + block.height / 2;
          const maxScrollTop = Math.max(0, scroller.scrollHeight - scroller.clientHeight);
          return clampNumber(documentY - scroller.clientHeight / 2, 0, maxScrollTop);
        },
        write: (scrollTop) => {
          this.view.scrollDOM.scrollTo({
            top: scrollTop,
            behavior: 'smooth',
          });
          this.view.focus();
        },
      });
    }
  }
);

const baseEditorSetup: Extension = [
  lineNumbers(),
  unsavedChangeLinesField,
  unsavedDeletionLinesField,
  unsavedChangeGutter,
  highlightActiveLineGutter(),
  highlightSpecialChars(),
  history(),
  foldGutter(),
  drawSelection(),
  dropCursor(),
  EditorState.allowMultipleSelections.of(true),
  indentOnInput(),
  syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
  closeBrackets(),
  autocompletion(),
  rectangularSelection(),
  crosshairCursor(),
  highlightActiveLine(),
  highlightSelectionMatches(),
  scrollRulerMarkersField,
  scrollRulerPlugin,
  keymap.of([
    ...closeBracketsKeymap,
    ...defaultKeymap,
    ...searchKeymap,
    ...historyKeymap,
    ...foldKeymap,
    ...completionKeymap,
    ...lintKeymap,
  ]),
];

const KEY_MOD_CTRL_CMD = 1 << 11;
const KEY_MOD_ALT = 1 << 9;
const KEY_CODE_S = 1;
const KEY_CODE_Z = 2;

const lineWrappingExtension = (mode: WordWrapMode): Extension =>
  mode === 'on' ? EditorView.lineWrapping : [];

const DEFAULT_TAILWIND_CSS =
  '@import "tailwindcss";\n' +
  '\n' +
  '@theme {\n' +
  '  /* ... */\n' +
  '}\n';

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

const htmlIntellisenseExtensions = (): Extension[] => [
  EditorView.inputHandler.of((view, _from, _to, text, insert) => {
    if (text !== '<') {
      return false;
    }
    view.dispatch(insert());
    queueMicrotask(() => {
      if (view.hasFocus) {
        startCompletion(view);
      }
    });
    return true;
  }),
];

const createEditorWrapper = (options: {
  parent: HTMLElement;
  initialValue: string;
  language: Extension;
  emmet?: EmmetKnownSyntax;
  htmlIntellisense?: boolean;
  onPaste?: (text: string) => boolean;
  readOnly?: boolean;
  wordWrap?: WordWrapMode;
}): EditorWrapper => {
  const wrapCompartment = new Compartment();
  const editableCompartment = new Compartment();
  const readOnlyCompartment = new Compartment();
  const actionKeymapCompartment = new Compartment();
  const changeListeners = new Set<() => void>();
  const focusListeners = new Set<() => void>();
  const actionKeymaps: KeyBinding[] = [];
  const baseReadOnly = Boolean(options.readOnly);
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

  const setScrollRulerMarkerSpecs = (markers: EditorScrollRulerMarker[]) => {
    const specs = markers.map((marker) => {
      const offsets = rangeToOffsets(view.state, marker.range);
      return {
        from: offsets.from,
        to: offsets.to,
        className: marker.className,
        title: marker.title,
      };
    });
    view.dispatch({
      effects: SCROLL_RULER_MARKERS_EFFECT.of(specs),
    });
  };

  const normalizeUnsavedLineNumbers = (lineNumbers: number[]) => {
    const maxLine = view.state.doc.lines;
    return Array.from(
      new Set(
        lineNumbers
          .filter((lineNumber) => Number.isFinite(lineNumber))
          .map((lineNumber) => Math.trunc(lineNumber))
          .filter((lineNumber) => lineNumber >= 1 && lineNumber <= maxLine)
      )
    ).sort((a, b) => a - b);
  };

  const setUnsavedChangeLines = (lineNumbers: number[]) => {
    view.dispatch({
      effects: UNSAVED_CHANGE_LINES_EFFECT.of(normalizeUnsavedLineNumbers(lineNumbers)),
    });
  };

  const setUnsavedDeletionLines = (lineNumbers: number[]) => {
    view.dispatch({
      effects: UNSAVED_DELETION_LINES_EFFECT.of(normalizeUnsavedLineNumbers(lineNumbers)),
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
    baseEditorSetup,
    oneDark,
    wrapCompartment.of(lineWrappingExtension(options.wordWrap ?? 'off')),
    editableCompartment.of(EditorView.editable.of(!options.readOnly)),
    readOnlyCompartment.of(EditorState.readOnly.of(baseReadOnly)),
    actionKeymapCompartment.of(keymap.of(actionKeymaps)),
    options.language,
    decorationField,
    updateListener,
  ];

  if (options.emmet) {
    extensions.push(...emmetExtensions(options.emmet));
  }
  if (options.htmlIntellisense) {
    extensions.push(...htmlIntellisenseExtensions());
  }
  if (options.onPaste) {
    extensions.push(
      EditorView.domEventHandlers({
        paste: (event) => {
          const text = event.clipboardData?.getData('text/plain') ?? '';
          if (!text || !options.onPaste) {
            return false;
          }
          const handled = options.onPaste(text);
          if (handled) {
            event.preventDefault();
          }
          return handled;
        },
      })
    );
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
    setUnsavedChangeLines,
    setUnsavedDeletionLines,
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
    setScrollRulerMarkers: (markers) => {
      setScrollRulerMarkerSpecs(markers);
    },
    clearScrollRulerMarkers: () => {
      setScrollRulerMarkerSpecs([]);
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
    setLocked: (locked) => {
      view.dispatch({
        effects: readOnlyCompartment.reconfigure(
          EditorState.readOnly.of(baseReadOnly || locked)
        ),
      });
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
    htmlIntellisense: true,
    onPaste: options.onHtmlPaste,
    wordWrap: options.htmlWordWrap,
  });

  const customHeadWrapper = createEditorWrapper({
    parent: options.customHeadContainer,
    initialValue: options.initialCustomHead ?? '',
    language: htmlLanguage(),
    emmet: EmmetKnownSyntax.html,
    htmlIntellisense: true,
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
    },
    htmlModel: htmlWrapper.model,
    customHeadModel: customHeadWrapper.model,
    cssModel: cssWrapper.model,
    jsModel: jsWrapper.model,
    htmlEditor: htmlWrapper.editor,
    customHeadEditor: customHeadWrapper.editor,
    cssEditor: cssWrapper.editor,
    jsEditor: jsWrapper.editor,
  };
}
