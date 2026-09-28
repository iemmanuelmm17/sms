import { toastSuccess } from './toast';

// Shared Reporting helpers: category meta, date presets, CSV/download.
export const CATS = [
  { id: 'new_sms', label: 'New SMS', color: '#3b82f6' },
  { id: 'regular_reply', label: 'Regular reply', color: '#10b981' },
  { id: 'mass_sms', label: 'Mass SMS', color: '#f59e0b' },
  { id: 'auto_reply', label: 'Auto-reply', color: '#8b5cf6' },
  { id: 'email_sms', label: 'Email to SMS', color: '#14b8d4' },
];
export const catLabel = (id) => CATS.find((c) => c.id === id)?.label || id;
export const catColor = (id) => CATS.find((c) => c.id === id)?.color || '#64748b';

export const PRESETS = [
  { id: 'today', label: 'Today' },
  { id: 'yesterday', label: 'Yesterday' },
  { id: 'last7', label: 'Last 7 days' },
  { id: 'thisWeek', label: 'This week' },
  { id: 'lastWeek', label: 'Last week' },
  { id: 'thisMonth', label: 'This month' },
  { id: 'lastMonth', label: 'Last month' },
  { id: 'custom', label: 'Custom' },
];

export const fmtD = (d) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** { from, to } as Y-m-d, or null when a custom range is incomplete. */
export function rangeFor(preset, customFrom, customTo) {
  const now = new Date();
  const day = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
  switch (preset) {
    case 'today': return { from: fmtD(day), to: fmtD(day) };
    case 'yesterday': { const y = addDays(day, -1); return { from: fmtD(y), to: fmtD(y) }; }
    case 'last7': return { from: fmtD(addDays(day, -6)), to: fmtD(day) };
    case 'thisWeek': { const dow = (day.getDay() + 6) % 7; return { from: fmtD(addDays(day, -dow)), to: fmtD(day) }; }
    case 'lastWeek': {
      const dow = (day.getDay() + 6) % 7;
      const mon = addDays(day, -dow - 7);
      return { from: fmtD(mon), to: fmtD(addDays(mon, 6)) };
    }
    case 'thisMonth': return { from: fmtD(new Date(day.getFullYear(), day.getMonth(), 1)), to: fmtD(day) };
    case 'lastMonth': {
      const first = new Date(day.getFullYear(), day.getMonth() - 1, 1);
      const last = new Date(day.getFullYear(), day.getMonth(), 0);
      return { from: fmtD(first), to: fmtD(last) };
    }
    case 'custom':
      if (!customFrom || !customTo || customTo < customFrom) return null;
      return { from: customFrom, to: customTo };
    default: return { from: fmtD(addDays(day, -6)), to: fmtD(day) };
  }
}

export const rangeDays = (from, to) => Math.round((new Date(to) - new Date(from)) / 86400000) + 1;

export const escCsv = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;

// Blob download with a mobile share-sheet fallback (same pattern as Messages export).
export async function saveFile(content, mime, filename, doneMsg) {
  const blob = new Blob([content], { type: `${mime};charset=utf-8` });
  try {
    const file = new File([blob], filename, { type: blob.type });
    if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
      await navigator.share({ files: [file], title: filename });
      return;
    }
  } catch (e) { if (e && e.name === 'AbortError') return; }
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  if (doneMsg) toastSuccess(doneMsg);
}
