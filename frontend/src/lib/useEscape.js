import { useEffect } from 'react';

/**
 * Close an overlay on Escape. Modals already do this in components/Modal.jsx —
 * this hook is for inline panels (Templates editor, context menus, export
 * popovers) that render without a Modal wrapper.
 *
 * @param {Function} onClose called on Escape
 * @param {boolean} active   only listen while the overlay is open
 */
export default function useEscape(onClose, active = true)
{
  useEffect(() => {
    if (!active || typeof onClose !== 'function') return undefined;
    const h = (e) => {
      if (e.key === 'Escape' || e.key === 'Esc') onClose();
    };
    window.addEventListener('keydown', h);
    return () => window.removeEventListener('keydown', h);
  }, [onClose, active]);
}
