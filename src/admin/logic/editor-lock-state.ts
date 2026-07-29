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
