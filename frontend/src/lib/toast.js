// Global toast bus. Any module can fire toastError()/toastSuccess()/toastInfo()
// and the <Toasts/> host (mounted in App) renders them. No alert() popups.
export function fireToast(type, message) {
  window.dispatchEvent(new CustomEvent('app-toast', {
    detail: { type, message: String(message ?? '') },
  }));
}
export const toastError = (msg) => fireToast('error', msg);
export const toastSuccess = (msg) => fireToast('success', msg);
export const toastInfo = (msg) => fireToast('info', msg);
