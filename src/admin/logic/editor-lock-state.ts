export type EditorLockState = {
  htmlAndCss: boolean;
  otherEditors: boolean;
};

export function resolveEditorLockState(
  extensionLocked: boolean,
  cssModeChangeInFlight: boolean
): EditorLockState {
  return {
    htmlAndCss: extensionLocked || cssModeChangeInFlight,
    otherEditors: extensionLocked,
  };
}

export function nextEditorLockGeneration(
  currentLocked: boolean,
  nextLocked: boolean,
  currentGeneration: number
) {
  return currentLocked === nextLocked ? currentGeneration : currentGeneration + 1;
}

export function isEditorLockRequestCurrent(
  locked: boolean,
  currentGeneration: number,
  requestGeneration: number
) {
  return !locked && currentGeneration === requestGeneration;
}
