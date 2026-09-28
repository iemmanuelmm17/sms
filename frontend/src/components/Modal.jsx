import { useEffect } from 'react';

/**
 * Persistent modal: backdrop clicks do NOT close it (so filled-in forms
 * are never lost by a misclick). Close via ✕/Cancel buttons or Escape.
 */
export default function Modal({ onClose, wide, children }) {
  useEffect(() => {
    const h = (e) => { if (e.key === 'Escape' && onClose) onClose(); };
    window.addEventListener('keydown', h);
    return () => window.removeEventListener('keydown', h);
  }, [onClose]);

  return (
    <div className="fixed inset-0 bg-black/40 flex items-end sm:items-center justify-center z-50 sm:p-4">
      <div className={`bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl w-full ${wide || 'max-w-md'} p-5 max-h-[94vh] sm:max-h-[92vh] overflow-y-auto chat-scroll pb-[max(1.25rem,env(safe-area-inset-bottom))] sm:pb-5`}>
        {children}
      </div>
    </div>
  );
}
