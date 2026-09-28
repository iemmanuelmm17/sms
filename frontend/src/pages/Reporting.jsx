import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { api, getTimezone, fmtPhone, initials } from '../api/client';
import { AGENTS_ENABLED } from '../lib/features';
import { toastError } from '../lib/toast';
import { CATS, catColor, catLabel, PRESETS, rangeFor, rangeDays, escCsv, saveFile } from '../lib/reporting';

const DEFAULT_FILTERS = { preset: 'last7', from: '', to: '', cats: CATS.map((c) => c.id) };
const originOf = (r) => {
  if (r.scheduled_message_id) return `Sched #${r.scheduled_message_id}`;
  if (r.auto_reply_id) return `Auto #${r.auto_reply_id}`;
  if (r.session_id) return 'Conversation';
  return '';
};

// ---------- SVG charts (no chart lib installed) ----------

function ChartFrame({ children, height = 230 }) {
  return (
    <svg viewBox={`0 0 640 ${height}`} className="w-full" role="img">
      {children}
    </svg>
  );
}

function StackedBars({ points }) {
  const H = 230, padL = 46, padB = 30, padT = 12;
  const max = Math.max(1, ...points.map((p) => p.total));
  const n = Math.max(points.length, 1);
  const slot = (640 - padL - 10) / n;
  const bw = Math.min(46, Math.max(5, slot * 0.62));
  const base = H - padB;
  const y = (v) => padT + (base - padT) * (1 - v / max);
  const showEvery = Math.ceil(n / 10);
  return (
    <ChartFrame height={H}>
      {[0, 0.25, 0.5, 0.75, 1].map((f) => (
        <g key={f}>
          <line x1={padL} x2={630} y1={y(max * f)} y2={y(max * f)} className="text-slate-200 dark:text-slate-700" stroke="currentColor" strokeWidth="1" />
          <text x={padL - 6} y={y(max * f) + 4} textAnchor="end" fontSize="10" fill="currentColor" className="text-slate-400">
            {Math.round(max * f).toLocaleString()}
          </text>
        </g>
      ))}
      {points.map((p, i) => {
        const x = padL + slot * i + (slot - bw) / 2;
        let acc = 0;
        return (
          <g key={p.bucket}>
            {CATS.map((c) => {
              const v = p[c.id] || 0;
              if (!v) return null;
              const y1 = y(acc + v);
              const h = y(acc) - y1;
              acc += v;
              return (
                <rect key={c.id} x={x} y={y1} width={bw} height={Math.max(h, 1)} fill={c.color}>
                  <title>{`${p.bucket} — ${c.label}: ${v.toLocaleString()}`}</title>
                </rect>
              );
            })}
            {p.total > 0 && <title>{`${p.bucket}: ${p.total.toLocaleString()} total`}</title>}
            {i % showEvery === 0 && (
              <text x={x + bw / 2} y={H - 10} textAnchor="middle" fontSize="10" fill="currentColor" className="text-slate-400">
                {String(p.bucket).slice(5)}
              </text>
            )}
          </g>
        );
      })}
    </ChartFrame>
  );
}

function TrendLine({ points }) {
  const H = 230, padL = 46, padB = 30, padT = 12;
  const max = Math.max(1, ...points.map((p) => p.total));
  const n = Math.max(points.length, 1);
  const span = 640 - padL - 10;
  const x = (i) => (n === 1 ? padL + span / 2 : padL + (span * i) / (n - 1));
  const base = H - padB;
  const y = (v) => padT + (base - padT) * (1 - v / max);
  const line = points.map((p, i) => `${x(i)},${y(p.total)}`).join(' ');
  const showEvery = Math.ceil(n / 10);
  return (
    <ChartFrame height={H}>
      {[0, 0.25, 0.5, 0.75, 1].map((f) => (
        <g key={f}>
          <line x1={padL} x2={630} y1={y(max * f)} y2={y(max * f)} className="text-slate-200 dark:text-slate-700" stroke="currentColor" strokeWidth="1" />
          <text x={padL - 6} y={y(max * f) + 4} textAnchor="end" fontSize="10" fill="currentColor" className="text-slate-400">
            {Math.round(max * f).toLocaleString()}
          </text>
        </g>
      ))}
      {points.length > 1 && (
        <polygon points={`${x(0)},${base} ${line} ${x(n - 1)},${base}`} fill="#3b82f6" opacity="0.12" />
      )}
      {points.length > 1 && <polyline points={line} fill="none" stroke="#3b82f6" strokeWidth="2.5" strokeLinejoin="round" />}
      {points.map((p, i) => (
        <g key={p.bucket}>
          <circle cx={x(i)} cy={y(p.total)} r="3.5" fill="#3b82f6">
            <title>{`${p.bucket}: ${p.total.toLocaleString()} sent`}</title>
          </circle>
          {i % showEvery === 0 && (
            <text x={x(i)} y={H - 10} textAnchor="middle" fontSize="10" fill="currentColor" className="text-slate-400">
              {String(p.bucket).slice(5)}
            </text>
          )}
        </g>
      ))}
    </ChartFrame>
  );
}

function Legend({ byCat }) {
  return (
    <div className="flex flex-wrap gap-x-4 gap-y-1 mt-2 pt-2 border-t border-slate-100">
      {CATS.map((c) => (
        <span key={c.id} className="inline-flex items-center gap-1.5 text-xs text-slate-600 min-w-0">
          <span className="inline-block w-2 h-2 rounded-full shrink-0" style={{ background: c.color }} />
          <span className="min-w-0 truncate">{c.label}</span>
          <span className="text-slate-400 tabular-nums font-medium">{(byCat?.[c.id] || 0).toLocaleString()}</span>
        </span>
      ))}
    </div>
  );
}

// ---------- main page ----------

export default function Reporting({
  apiBase = '/api/reports',
  tenantId = null,
  tenantName = '',
  filters: controlled = null,
  setFilters: setControlled = null,
  tenantMap = null, // super cross-tenant: { id: name } for the Tenant column
}) {
  const { user } = useAuth();
  const isAgent = user?.role === 'agent';
  // Reports cover conversations the agent may VIEW (own + view grants).
  const repAllowed = isAgent
    ? ((user?.readable_numbers || user?.assigned_numbers || [])).map((v) => String(v).replace(/\D/g, '')) : [];
  // Agents see per-agent rows for their own numbers (a shared line can be
  // worked by colleagues), so no client-side name filter — the server has
  // already limited the rows to numbers this agent can access.
  const agentVisible = () => true;
  const numVisible = (r) => !isAgent || repAllowed.includes(String(r.from_number ?? '').replace(/\D/g, ''));
  const [local, setLocal] = useState(DEFAULT_FILTERS);
  const filters = controlled ?? local;
  const setFilters = setControlled ?? setLocal;
  const [agentSel, setAgentSel] = useState([]);
  const [showAgents, setShowAgents] = useState(false);
  const [summary, setSummary] = useState(null);
  const [trend, setTrend] = useState(null);
  const [agents, setAgents] = useState(null);
  const [numbers, setNumbers] = useState(null);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState('');
  const [sort, setSort] = useState({ key: 'total', dir: 'desc' });
  const [sortNum, setSortNum] = useState({ key: 'total', dir: 'desc' });
  const [drill, setDrill] = useState(null); // { agent_id, name }
  const [drillRows, setDrillRows] = useState([]);
  const [drillTotal, setDrillTotal] = useState(0);
  const [drillLoading, setDrillLoading] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [numMeta, setNumMeta] = useState({});   // digits -> { label, tags[] } from the Numbers page
  const [agentQ, setAgentQ] = useState('');
  const [numQ, setNumQ] = useState('');

  const range = rangeFor(filters.preset, filters.from, filters.to);
  const rangeStr = range ? `${range.from}|${range.to}` : '';
  const catsKey = [...filters.cats].sort().join(',');
  const agentKey = [...agentSel].sort((a, b) => a - b).join(',');
  const showTenant = !!tenantMap && (tenantId === null || tenantId === undefined);

  const baseParams = () => {
    // The browser's timezone: the backend turns "today" into day bounds in it,
    // so a send made a few minutes ago lands inside the range.
    const p = { from: range.from, to: range.to, tz: getTimezone() };
    if (filters.cats.length < CATS.length) p.categories = filters.cats.join(',');
    if (agentSel.length) p.agent_ids = agentSel.join(',');
    if (tenantId !== null && tenantId !== undefined) p.tenant_id = tenantId;
    return p;
  };

  useEffect(() => {
    if (!range) { setLoading(false); return; }
    let dead = false;
    setLoading(true);
    setErr('');
    const p = baseParams();
    Promise.all([api.reportSummary(apiBase, p), api.reportTrend(apiBase, p), api.reportByAgent(apiBase, p), api.reportByNumber(apiBase, p)])
      .then(([s, t, a, n]) => {
        if (dead) return;
        setSummary(s);
        setTrend(t);
        setAgents(a);
        setNumbers(n);
      })
      .catch((e) => { if (!dead) setErr(e?.response?.data?.message || 'Failed to load report.'); })
      .finally(() => { if (!dead) setLoading(false); });
    return () => { dead = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [apiBase, tenantId, rangeStr, catsKey, agentKey, user?.role]);

  // Reset drill-down + agent selection when the scope changes.
  useEffect(() => { setDrill(null); setAgentSel([]); }, [apiBase, tenantId]);

  // Descriptions/tags are maintained on the Numbers page — reuse them here so
  // a number reads "Primary inbound line" rather than bare digits. Best-effort:
  // super-admin cross-tenant scope has no single company settings to read.
  useEffect(() => {
    if (tenantId !== null && tenantId !== undefined) return;
    if (apiBase.includes('superadmin')) return;
    let dead = false;
    api.companySettings()
      .then((c) => { if (!dead) setNumMeta(c?.number_meta || {}); })
      .catch(() => {});
    return () => { dead = true; };
  }, [apiBase, tenantId]);

  useEffect(() => {
    if (!drill || !range) return;
    let dead = false;
    setDrillLoading(true);
    const p = { ...baseParams(), agent_id: drill.agent_id, per_page: 50, page: 1 };
    delete p.agent_ids;
    api.reportDetail(apiBase, p)
      .then((d) => {
        if (dead) return;
        setDrillRows(d.data || []);
        setDrillTotal(d.meta?.total || 0);
      })
      .catch((e) => { if (!dead) toastError(e?.response?.data?.message || 'Failed to load detail.'); })
      .finally(() => { if (!dead) setDrillLoading(false); });
    return () => { dead = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [drill, rangeStr, catsKey, apiBase, tenantId]);

  const agentOptions = useMemo(
    () => (agents?.rows || []).filter((r) => r.agent_id !== null),
    [agents]
  );

  const sortedAgents = useMemo(() => {
    const rows = [...(agents?.rows || [])];
    const { key, dir } = sort;
    const m = dir === 'asc' ? 1 : -1;
    rows.sort((a, b) => {
      if (key === 'agent_name') return String(a.agent_name).localeCompare(String(b.agent_name)) * m;
      return ((a[key] || 0) - (b[key] || 0)) * m;
    });
    return rows;
  }, [agents, sort]);

  const sortedNumbers = useMemo(() => {
    const rows = [...(numbers?.rows || [])];
    const { key, dir } = sortNum;
    const m = dir === 'asc' ? 1 : -1;
    rows.sort((a, b) => {
      if (key === 'from_number') return String(a.from_number ?? '').localeCompare(String(b.from_number ?? '')) * m;
      return ((a[key] || 0) - (b[key] || 0)) * m;
    });
    return rows;
  }, [numbers, sortNum]);

  const visibleAgents = useMemo(() => {
    const q = agentQ.trim().toLowerCase();
    const rows = sortedAgents.filter(agentVisible);
    return q ? rows.filter((a) => String(a.agent_name || '').toLowerCase().includes(q)) : rows;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sortedAgents, agentQ, isAgent, user?.id]);

  const visibleNumbers = useMemo(() => {
    const q = numQ.trim().toLowerCase();
    const qd = q.replace(/\D/g, '');
    const rows = sortedNumbers.filter(numVisible);
    if (!q) return rows;
    return rows.filter((r) => {
      const d = String(r.from_number ?? '').replace(/\D/g, '');
      const meta = numMeta[d] || {};
      return (qd && d.includes(qd))
        || fmtPhone(r.from_number || '').toLowerCase().includes(q)
        || String(meta.label || '').toLowerCase().includes(q)
        || (meta.tags || []).join(' ').toLowerCase().includes(q);
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sortedNumbers, numQ, numMeta, isAgent]);

  const title = tenantName ? `Reporting — ${tenantName}` : (apiBase.includes('superadmin') ? 'Reporting — all tenants' : 'Reporting');

  const toggleCat = (id) => {
    const has = filters.cats.includes(id);
    const next = has ? filters.cats.filter((c) => c !== id) : [...filters.cats, id];
    setFilters({ ...filters, cats: next.length ? next : CATS.map((c) => c.id) });
  };

  const toggleAgent = (id) => {
    setAgentSel((sel) => (sel.includes(id) ? sel.filter((a) => a !== id) : [...sel, id]));
  };

  const pickPreset = (id) => {
    if (id === 'custom' && (!filters.from || !filters.to)) {
      const d7 = rangeFor('last7');
      setFilters({ ...filters, preset: 'custom', from: d7.from, to: d7.to });
    } else {
      setFilters({ ...filters, preset: id });
    }
  };

  const sortBy = (key) => setSort((s) => (s.key === key
    ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' }
    : { key, dir: key === 'agent_name' ? 'asc' : 'desc' }));

  const th = (label, key) => (
    <th
      key={key}
      onClick={() => sortBy(key)}
      className={`px-3 py-2 font-medium cursor-pointer select-none whitespace-nowrap ${key === 'agent_name' ? 'text-left' : 'text-right'}`}
      title="Sort"
    >
      {label} {sort.key === key && (sort.dir === 'asc' ? '▲' : '▼')}
    </th>
  );

  const sortByNum = (key) => setSortNum((s) => (s.key === key
    ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' }
    : { key, dir: key === 'from_number' ? 'asc' : 'desc' }));

  const thNum = (label, key) => (
    <th
      key={key}
      onClick={() => sortByNum(key)}
      className={`px-3 py-2 font-medium cursor-pointer select-none whitespace-nowrap ${key === 'from_number' ? 'text-left' : 'text-right'}`}
      title="Sort"
    >
      {label} {sortNum.key === key && (sortNum.dir === 'asc' ? '▲' : '▼')}
    </th>
  );

  const exportCsv = async (agentId = null, fileTag = 'report') => {
    if (!range || exporting) return;
    setExporting(true);
    try {
      const p = { ...baseParams(), per_page: 5000, page: 1 };
      if (agentId !== null) { p.agent_id = agentId; delete p.agent_ids; }
      const d = await api.reportDetail(apiBase, p);
      const rows = d.data || [];
      if (!rows.length) { toastError('Nothing to export.'); return; }
      const head = ['sent_at', 'tenant', 'category', 'agent', 'from_number', 'to_number', 'type', 'origin'].map(escCsv).join(',');
      const lines = rows.map((r) => [
        r.sent_at,
        tenantMap?.[r.tenant_id] ?? tenantName ?? '',
        catLabel(r.category),
        r.actor_name ?? '',
        r.from_number ?? '',
        r.to_number ?? '',
        r.type ?? '',
        originOf(r),
      ].map(escCsv).join(','));
      await saveFile([head, ...lines].join('\n'), 'text/csv',
        `reporting-${fileTag}-${range.from}-${range.to}.csv`,
        `Exported ${rows.length.toLocaleString()} row${rows.length === 1 ? '' : 's'}.`);
      if ((d.meta?.total || 0) > rows.length) {
        toastError(`Truncated: showing first ${rows.length.toLocaleString()} of ${(d.meta.total).toLocaleString()} rows. Narrow the range.`);
      }
    } catch (e) {
      toastError(e?.response?.data?.message || 'Export failed.');
    } finally {
      setExporting(false);
    }
  };

  const days = range ? rangeDays(range.from, range.to) : 0;
  const delta = summary?.delta_pct;
  const total = summary?.total || 0;

  const peak = (trend?.points || []).reduce((b, p) => (p.total > (b?.total ?? -1) ? p : b), null);

  return (
    <div className="space-y-4">
      {/* ---- Page header ---- */}
      <div className="flex items-start gap-3 flex-wrap">
        <div className="flex-1 min-w-0">
          <h1 className="text-fluid-lg font-bold text-slate-900 flex items-center gap-2 flex-wrap">
            <span className="min-w-0 truncate">{title}</span>
            {!loading && !err && (
              <span className="inline-flex items-center gap-1 text-[10px] font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-full px-2 py-0.5 shrink-0">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />Live
              </span>
            )}
          </h1>
          <p className="text-xs text-slate-400 mt-1">Monitor message throughput, delivery categories, and agent engagement.</p>
        </div>
        <button
          onClick={() => exportCsv(isAgent ? user?.id : null)}
          disabled={exporting || !range}
          className="text-xs font-semibold px-3 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white disabled:opacity-50 shrink-0">
          {exporting ? 'Exporting…' : '⬇ Export CSV'}
        </button>
      </div>

      {/* Filters */}
      <div className="bg-white border rounded-xl p-4 space-y-3">
        <div className="flex flex-wrap gap-2 items-center">
          {PRESETS.map((p) => (
            <button
              key={p.id}
              onClick={() => pickPreset(p.id)}
              className={`text-xs px-3 py-1.5 rounded-full border ${filters.preset === p.id
                ? 'bg-slate-900 text-white border-slate-900'
                : 'text-slate-600 hover:bg-slate-100'}`}
            >
              {p.label}
            </button>
          ))}
          {filters.preset === 'custom' && (
            <span className="inline-flex items-center gap-2 text-xs text-slate-600 ml-1">
              <input
                type="date" value={filters.from} max={filters.to || undefined}
                onChange={(e) => setFilters({ ...filters, from: e.target.value })}
                className="border rounded-lg px-2 py-1.5"
              />
              <span>→</span>
              <input
                type="date" value={filters.to} min={filters.from || undefined}
                onChange={(e) => setFilters({ ...filters, to: e.target.value })}
                className="border rounded-lg px-2 py-1.5"
              />
            </span>
          )}
          <span className="text-xs text-slate-500 ml-auto inline-flex items-center gap-1.5 border rounded-lg px-2.5 py-1.5 bg-slate-50 min-w-0">
            <span aria-hidden="true">🗓</span>
            <span className="min-w-0 truncate">
              {range ? `${range.from} → ${range.to} (${days} day${days === 1 ? '' : 's'})` : 'Pick a valid range'}
            </span>
          </span>
        </div>
        <div className="flex flex-wrap gap-2 items-center">
          <span className="text-xs font-medium text-slate-500 shrink-0">Filter categories:</span>
          {CATS.map((c) => {
            const on = filters.cats.includes(c.id);
            return (
              <button
                key={c.id}
                onClick={() => toggleCat(c.id)}
                aria-pressed={on}
                className={`inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-full border transition-colors ${on
                  ? 'bg-slate-50 text-slate-800'
                  : 'border-slate-200 text-slate-400'}`}
                style={on ? { borderColor: c.color, boxShadow: `inset 0 0 0 1px ${c.color}33` } : undefined}
              >
                <span className="inline-block w-2 h-2 rounded-full shrink-0" style={{ background: c.color, opacity: on ? 1 : 0.25 }} />
                {c.label}
              </button>
            );
          })}
          <div className="relative ml-auto">
            <button
              onClick={() => setShowAgents((v) => !v)}
              className="text-xs font-medium px-3 py-1.5 rounded-lg border text-slate-600 hover:bg-slate-100 whitespace-nowrap"
            >
              {agentSel.length ? `Agents: ${agentSel.length} selected ▾` : 'Agents: All members ▾'}
            </button>
            {showAgents && (
              <div className="absolute z-20 mt-1 w-64 max-h-64 overflow-auto bg-white border rounded-xl shadow-lg p-2">
                {agentOptions.length === 0 && (
                  <div className="text-xs text-slate-400 px-2 py-1.5">No agents with sends in this view.</div>
                )}
                {agentOptions.map((a) => (
                  <label key={a.agent_id} className="flex items-center gap-2 text-sm px-2 py-1.5 rounded hover:bg-slate-50 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={agentSel.includes(a.agent_id)}
                      onChange={() => toggleAgent(a.agent_id)}
                    />
                    <span className="flex-1 truncate">{a.agent_name}</span>
                    <span className="text-xs text-slate-400 tabular-nums">{a.total.toLocaleString()}</span>
                  </label>
                ))}
                <div className="flex gap-2 border-t mt-1 pt-2 px-2">
                  <button onClick={() => setAgentSel([])} className="text-xs text-slate-500 hover:underline">Clear</button>
                  <button onClick={() => setShowAgents(false)} className="text-xs text-slate-900 font-medium hover:underline ml-auto">Done</button>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>

      {err && <div className="bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm">{err}</div>}
      {loading && (
        <div className="space-y-4" aria-busy="true" aria-label="Loading report">
          <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
            {Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className="bg-white border rounded-xl p-4 h-[104px] animate-pulse">
                <div className="h-2.5 w-16 bg-slate-100 rounded" />
                <div className="h-6 w-12 bg-slate-100 rounded mt-2" />
                <div className="h-1.5 w-full bg-slate-100 rounded-full mt-4" />
              </div>
            ))}
          </div>
          <div className="grid md:grid-cols-2 gap-4">
            {[0, 1].map((i) => (
              <div key={i} className="bg-white border rounded-xl p-4 h-64 animate-pulse">
                <div className="h-3 w-40 bg-slate-100 rounded" />
                <div className="h-44 w-full bg-slate-50 rounded mt-4" />
              </div>
            ))}
          </div>
        </div>
      )}

      {!loading && !err && summary && total === 0 && (
        <div className="bg-white border rounded-xl p-8 text-center">
          <div aria-hidden="true" className="text-3xl">📭</div>
          <p className="text-sm font-medium text-slate-600 mt-2">No sends in this range</p>
          <p className="text-xs text-slate-400 mt-1 max-w-md mx-auto">
          {summary.tracking_since
            ? `Tracking started ${summary.tracking_since} — sends before that date aren't categorized. Try a wider date range.`
            : 'No sends logged yet in this scope. Tracking starts with new outbound sends.'}
          </p>
        </div>
      )}

      {!loading && !err && summary && total > 0 && (
        <>
          {/* Summary cards: hero total + one tile per category with share-of-volume */}
          <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
            <div className="bg-brand-600 text-white rounded-xl p-4 col-span-2 md:col-span-1 flex flex-col">
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-medium text-white/80">Total sent</span>
                <span aria-hidden="true" className="text-white/70">➤</span>
              </div>
              <div className="text-3xl font-bold tabular-nums mt-1">{total.toLocaleString()}</div>
              <div className="text-[11px] mt-1 text-white/85">
                {delta === null || delta === undefined ? (
                  <span>{summary.prev_total === 0 ? 'new in period' : '—'}</span>
                ) : delta > 0 ? (
                  <span>▲ +{delta}% <span className="text-white/60">vs prev {days}d</span></span>
                ) : delta < 0 ? (
                  <span>▼ {Math.abs(delta)}% <span className="text-white/60">vs prev {days}d</span></span>
                ) : (
                  <span className="text-white/70">= 0% vs prev {days}d</span>
                )}
              </div>
              <div className="mt-3 pt-2 border-t border-white/20 flex items-center justify-between text-[10px] text-white/80">
                <span>Period total</span>
                <span className="font-semibold">100% volume</span>
              </div>
            </div>
            {CATS.map((c) => {
              const v = summary.by_category?.[c.id] || 0;
              const pct = total > 0 ? (v / total) * 100 : 0;
              const muted = !filters.cats.includes(c.id);
              return (
                <div key={c.id} className={`bg-white border rounded-xl p-4 flex flex-col ${muted ? 'opacity-50' : ''}`}>
                  <div className="text-xs text-slate-500 inline-flex items-center gap-1.5 min-w-0">
                    <span className="inline-block w-2 h-2 rounded-full shrink-0" style={{ background: c.color }} />
                    <span className="min-w-0 truncate">{c.label}</span>
                  </div>
                  <div className="text-2xl font-bold tabular-nums mt-0.5">{v.toLocaleString()}</div>
                  <div className="text-[11px] text-slate-400 mt-0.5">{pct.toFixed(1)}% of total volume</div>
                  <div className="mt-2 h-1.5 rounded-full bg-slate-100 overflow-hidden" role="presentation">
                    <div className="h-full rounded-full transition-all" style={{ width: `${pct}%`, background: c.color }} />
                  </div>
                </div>
              );
            })}
          </div>

          {/* Charts */}
          <div className="grid md:grid-cols-2 gap-4">
            <div className="bg-white border rounded-xl p-4">
              <div className="flex items-start gap-2 flex-wrap">
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-semibold flex items-center gap-1.5">
                    <span aria-hidden="true">📊</span>Volume by category
                  </div>
                  <p className="text-[11px] text-slate-400">Daily stacked distribution across message types</p>
                </div>
              </div>
              <div className="mt-2"><StackedBars points={trend?.points || []} /></div>
              <Legend byCat={summary.by_category} />
            </div>
            <div className="bg-white border rounded-xl p-4">
              <div className="flex items-start gap-2 flex-wrap">
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-semibold flex items-center gap-1.5">
                    <span aria-hidden="true">📈</span>Send trend{trend?.bucket === 'week' ? ' (weekly)' : ' (daily)'}
                  </div>
                  <p className="text-[11px] text-slate-400">Total volume over time — hover points for the exact count</p>
                </div>
                {peak && peak.total > 0 && (
                  <span className="text-[10px] font-semibold text-brand-700 bg-brand-50 border border-brand-200 rounded-full px-2 py-1 shrink-0">
                    Peak: {peak.total.toLocaleString()} sends ({String(peak.bucket).slice(5)})
                  </span>
                )}
              </div>
              <div className="mt-2"><TrendLine points={trend?.points || []} /></div>
            </div>
          </div>

          {/* By agent — hidden in phase 1 (portal login, no in-app roster). */}
          {AGENTS_ENABLED && (
          <div className="bg-white border rounded-xl p-4">
            <div className="flex items-start gap-2 flex-wrap mb-2">
              <div className="flex-1 min-w-0">
                <div className="text-sm font-semibold flex items-center gap-1.5">
                  <span aria-hidden="true">👤</span>Breakdown by agent
                </div>
                <p className="text-[11px] text-slate-400">Click any row to view that agent&apos;s message log</p>
              </div>
              {sortedAgents.filter(agentVisible).length > 1 && (
                <input value={agentQ} onChange={(e) => setAgentQ(e.target.value)}
                  placeholder="🔍 Filter agents…" aria-label="Filter agents"
                  className="text-xs border rounded-lg px-2.5 py-1.5 w-40 min-w-0 focus:outline-none focus:ring-2 focus:ring-brand-500" />
              )}
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-xs text-slate-500 border-b">
                    {th('Agent', 'agent_name')}
                    {th('Total', 'total')}
                    {th('New SMS', 'new_sms')}
                    {th('Regular reply', 'regular_reply')}
                    {th('Mass triggered', 'mass_triggered')}
                  </tr>
                </thead>
                <tbody>
                  {visibleAgents.map((a) => (
                    <tr
                      key={a.agent_id === null ? 'admin' : a.agent_id}
                      onClick={() => a.agent_id !== null && setDrill({ agent_id: a.agent_id, name: a.agent_name })}
                      className={`border-b last:border-0 tabular-nums ${a.agent_id !== null ? 'hover:bg-slate-50 cursor-pointer' : ''} ${drill?.agent_id === a.agent_id ? 'bg-slate-50' : ''}`}
                    >
                      <td className="px-3 py-2">
                        <span className="flex items-center gap-2 min-w-0">
                          <span aria-hidden="true"
                            className="w-7 h-7 shrink-0 rounded-full bg-brand-100 text-brand-700 text-[10px] font-bold flex items-center justify-center">
                            {initials(a.agent_name) || '—'}
                          </span>
                          <span className="min-w-0">
                            <span className="block truncate">{a.agent_name}</span>
                            <span className="block text-[10px] text-slate-400 truncate">
                              {a.agent_id === null ? 'Unattributed / admin sends' : 'Agent'}
                            </span>
                          </span>
                        </span>
                      </td>
                      <td className="px-3 py-2 text-right font-medium">{a.total.toLocaleString()}</td>
                      <td className="px-3 py-2 text-right">{a.new_sms.toLocaleString()}</td>
                      <td className="px-3 py-2 text-right">{a.regular_reply.toLocaleString()}</td>
                      <td className="px-3 py-2 text-right">
                        {a.mass_triggered > 0
                          ? <span className="inline-flex items-center gap-1"><span className="text-emerald-600">●</span>{a.mass_triggered.toLocaleString()}</span>
                          : <span className="text-slate-300">—</span>}
                      </td>
                    </tr>
                  ))}
                  {visibleAgents.length === 0 && (
                    <tr><td colSpan="5" className="px-3 py-4 text-center text-slate-400 text-sm">
                      {agentQ.trim() ? `No agents match “${agentQ.trim()}”.` : 'No agent-attributed sends in this view.'}
                    </td></tr>
                  )}
                </tbody>
              </table>
            </div>
            <div className="flex items-center gap-2 flex-wrap mt-2 pt-2 border-t border-slate-100">
              <span className="text-[11px] text-slate-400 flex-1 min-w-0">
                ⓘ Mass SMS and auto-replies without an attributed agent count toward totals only.
              </span>
              <span className="text-[11px] text-slate-400 shrink-0">
                Showing {visibleAgents.length} of {sortedAgents.filter(agentVisible).length} agent{sortedAgents.filter(agentVisible).length === 1 ? '' : 's'}
              </span>
            </div>
          </div>
          )}

          {/* By sending number */}
          <div className="bg-white border rounded-xl p-4">
            <div className="flex items-start gap-2 flex-wrap mb-2">
              <div className="flex-1 min-w-0">
                <div className="text-sm font-semibold flex items-center gap-1.5">
                  <span aria-hidden="true">📱</span>Breakdown by sending number
                </div>
                <p className="text-[11px] text-slate-400">Line performance and per-category delivery stats</p>
              </div>
              {sortedNumbers.filter(numVisible).length > 1 && (
                <input value={numQ} onChange={(e) => setNumQ(e.target.value)}
                  placeholder="🔍 Search number…" aria-label="Search sending numbers"
                  className="text-xs border rounded-lg px-2.5 py-1.5 w-40 min-w-0 focus:outline-none focus:ring-2 focus:ring-brand-500" />
              )}
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-xs text-slate-500 border-b">
                    {thNum('Number', 'from_number')}
                    {thNum('Total', 'total')}
                    {thNum('New SMS', 'new_sms')}
                    {thNum('Regular reply', 'regular_reply')}
                    {thNum('Mass SMS', 'mass_sms')}
                    {thNum('Auto-reply', 'auto_reply')}
                  </tr>
                </thead>
                <tbody>
                  {visibleNumbers.map((r) => {
                    const nd = String(r.from_number ?? '').replace(/\D/g, '');
                    const meta = numMeta[nd] || {};
                    const cell = (v, cid) => (
                      <td className="px-3 py-2 text-right">
                        {v > 0
                          ? <span style={{ color: catColor(cid) }} className="font-medium">{v.toLocaleString()}</span>
                          : <span className="text-slate-300">0</span>}
                      </td>
                    );
                    return (
                      <tr key={r.from_number ?? 'unknown'} className="border-b last:border-0 tabular-nums">
                        <td className="px-3 py-2">
                          <span className="flex items-start gap-2 min-w-0">
                            <span aria-hidden="true" className="w-1.5 h-1.5 rounded-full bg-emerald-500 mt-1.5 shrink-0" />
                            <span className="min-w-0">
                              <span className="block truncate">
                                {r.from_number ? fmtPhone(r.from_number) : <span className="text-slate-400">Unknown</span>}
                              </span>
                              {meta.label && <span className="block text-[10px] text-slate-400 truncate">{meta.label}</span>}
                              {(meta.tags || []).length > 0 && (
                                <span className="flex flex-wrap gap-1 mt-0.5">
                                  {meta.tags.map((t) => (
                                    <span key={t} className="text-[9px] font-semibold text-brand-700 bg-brand-50 border border-brand-100 rounded-full px-1.5 py-px max-w-[8rem] truncate">{t}</span>
                                  ))}
                                </span>
                              )}
                            </span>
                          </span>
                        </td>
                        <td className="px-3 py-2 text-right font-semibold">{r.total.toLocaleString()}</td>
                        {cell(r.new_sms, 'new_sms')}
                        {cell(r.regular_reply, 'regular_reply')}
                        {cell(r.mass_sms, 'mass_sms')}
                        {cell(r.auto_reply, 'auto_reply')}
                      </tr>
                    );
                  })}
                  {visibleNumbers.length === 0 && (
                    <tr><td colSpan="6" className="px-3 py-4 text-center text-slate-400 text-sm">
                      {numQ.trim() ? `No numbers match “${numQ.trim()}”.` : 'No sends in this view.'}
                    </td></tr>
                  )}
                </tbody>
              </table>
            </div>
            <div className="flex items-center gap-2 flex-wrap mt-2 pt-2 border-t border-slate-100">
              <span className="text-[11px] text-slate-400 flex-1 min-w-0">
                {visibleNumbers.length} sending number{visibleNumbers.length === 1 ? '' : 's'} with activity in this range
              </span>
              {!isAgent && !apiBase.includes('superadmin') && (
                <Link to="/app/numbers" className="text-[11px] font-medium text-brand-600 hover:underline shrink-0">Manage numbers →</Link>
              )}
            </div>
          </div>

          {/* Agent drill-down */}
          {drill && (
            <div className="bg-white border rounded-xl p-4">
              <div className="flex items-center gap-2 mb-2 flex-wrap">
                <span aria-hidden="true"
                  className="w-7 h-7 shrink-0 rounded-full bg-brand-100 text-brand-700 text-[10px] font-bold flex items-center justify-center">
                  {initials(drill.name) || '—'}
                </span>
                <div className="min-w-0">
                  <div className="text-sm font-semibold truncate">Messages — {drill.name}</div>
                  <div className="text-[11px] text-slate-400">
                    {drillTotal.toLocaleString()} in range{drillTotal > 50 ? ' · showing newest 50' : ''}
                  </div>
                </div>
                <span className="flex-1" />
                <button onClick={() => exportCsv(drill.agent_id, `agent-${drill.agent_id}`)}
                  className="text-xs font-medium text-brand-600 hover:underline py-1 shrink-0">⬇ Export this agent</button>
                <button onClick={() => setDrill(null)} aria-label="Close message detail"
                  className="text-xs text-slate-400 hover:text-slate-700 py-1 px-1 shrink-0">✕ Close</button>
              </div>
              {drillLoading && <div className="text-sm text-slate-500">Loading…</div>}
              {!drillLoading && (
                <div className="overflow-x-auto max-h-96 overflow-y-auto">
                  <table className="w-full text-sm">
                    <thead className="sticky top-0 bg-white">
                      <tr className="text-xs text-slate-500 border-b text-left">
                        <th className="px-3 py-2 font-medium">Sent</th>
                        {showTenant && <th className="px-3 py-2 font-medium">Tenant</th>}
                        <th className="px-3 py-2 font-medium">Category</th>
                        <th className="px-3 py-2 font-medium">From → To</th>
                        <th className="px-3 py-2 font-medium">Type</th>
                        <th className="px-3 py-2 font-medium">Origin</th>
                      </tr>
                    </thead>
                    <tbody>
                      {drillRows.map((r) => (
                        <tr key={r.id} className="border-b last:border-0 tabular-nums">
                          <td className="px-3 py-1.5 whitespace-nowrap text-xs">{String(r.sent_at || '').slice(0, 16).replace('T', ' ')}</td>
                          {showTenant && <td className="px-3 py-1.5 text-xs">{tenantMap?.[r.tenant_id] ?? '—'}</td>}
                          <td className="px-3 py-1.5 text-xs whitespace-nowrap">
                            <span className="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 font-medium"
                              style={{ color: catColor(r.category), background: `${catColor(r.category)}1a` }}>
                              <span className="inline-block w-1.5 h-1.5 rounded-full" style={{ background: catColor(r.category) }} />
                              {catLabel(r.category)}
                            </span>
                          </td>
                          <td className="px-3 py-1.5 text-xs whitespace-nowrap">
                            {r.from_number ? fmtPhone(r.from_number) : '—'} <span className="text-slate-300">→</span> {r.to_number ? fmtPhone(r.to_number) : '—'}
                          </td>
                          <td className="px-3 py-1.5 text-xs">{r.type || 'sms'}</td>
                          <td className="px-3 py-1.5 text-xs text-slate-500">{originOf(r)}</td>
                        </tr>
                      ))}
                      {drillRows.length === 0 && (
                        <tr><td colSpan={showTenant ? 6 : 5} className="px-3 py-4 text-center text-slate-400 text-sm">No messages.</td></tr>
                      )}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </>
      )}
    </div>
  );
}
