import { Pencil, Trash2 } from 'lucide-react';

/**
 * Flat circular icon button for row-level actions. The label moves into
 * title/aria-label; a hover tint keeps the action discoverable. Hover
 * backgrounds ride the global dark-mode overrides in index.css
 * (.dark .hover\:bg-brand-50:hover / .dark .hover\:bg-red-50:hover).
 */
export function IconAction({ icon: Icon, label, onClick, danger = false, disabled = false, className = '' }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      title={label}
      aria-label={label}
      className={`p-1.5 rounded-full text-slate-500 transition shrink-0 disabled:opacity-40 disabled:cursor-not-allowed ${
        danger
          ? 'hover:text-red-600 hover:bg-red-50 dark:hover:text-red-400'
          : 'hover:text-brand-700 hover:bg-brand-50 dark:hover:text-blue-300'
      } ${className}`}
    >
      <Icon className="w-4 h-4" />
    </button>
  );
}

/** Pencil — edit. */
export const EditBtn = ({ label = 'Edit', ...p }) => <IconAction {...p} icon={Pencil} label={label} />;

/** Trash can — delete. */
export const DeleteBtn = ({ label = 'Delete', ...p }) => <IconAction {...p} icon={Trash2} label={label} danger />;
