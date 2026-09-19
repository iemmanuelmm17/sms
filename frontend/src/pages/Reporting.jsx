import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../context/AuthContext';
import { api } from '../api/client';
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
          <line x1={padL} x2={630} y1={y(max * f)} y2={y(max * f)} stroke="#e2e8f0" strokeWidth="1" />
          <text x={padL - 6} y={y(max * f) + 4} textAnchor="end" fontSize="10" fill="#94a3b8">
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
              <text x={x + bw / 2} y={H - 10} textAnchor="middle" fontSize="10" fill="#94a3b8">
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
          <line x1={padL} x2={630} y1={y(max * f)} y2={y(max * f)} stroke="#e2e8f0" strokeWidth="1" />
          <text x={padL - 6} y={y(max * f) + 4} textAnchor="end" fontSize="10" fill="#94a3b8">
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
            <text x={x(i)} y={H - 10} textAnchor="middle" fontSize="10" fill="#94a3b8">
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
    <div className="flex flex-wrap gap-x-4 gap-y-1 mt-2">
      {CATS.map((c) => (
        <span key={c.id} className="inline-flex items-center gap-1.5 text-xs text-slate-600">
          <span className="inline-block w-2.5 h-2.5 rounded-sm" style={{ background: c.color }} />
          {c.label}
          <span className="text-slate-400 tabular-nums">{(byCat?.[c.id] || 0).toLocaleString()}</span>
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
  const repAllowed = isAgent ? (user?.assigned_numbers || []).map((v) => String(v).replace(/\D/g, '')) : [];
  const agentVisible = (a) => !isAgent || String(a.agent_id) === String(user?.id);
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

  const range = rangeFor(filters.preset, filters.from, filters.to);
  const rangeStr = range ? `${range.from}|${range.to}` : '';
  const catsKey = [...filters.cats].sort().join(',');
  const agentKey = [...agentSel].sort((a, b) => a - b).join(',');
  const showTenant = !!tenantMap && (tenantId === null || tenantId === undefined);

  const baseParams = () => {
    const p = { from: range.from, to: range.to };
    if (filters.cats.length < CATS.length) p.categories = filters.cats.join(',');
    if (agentSel.length) p.agent_ids = agentSel.join(',');
    if (tenantId !== null && tenantId !== undefined) p.tenant_id = tenantId;
    return p;
  };

  useEffect(() => {
    if (!range || user?.role === 'agent') { setLoading(false); return; }
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

  if (user?.role === 'agent') {
    return (
      <div>
        <h1 className="text-xl font-bold text-slate-900 mb-4">Reporting</h1>
        <div className="bg-white border rounded-xl p-5 text-sm text-slate-500">
          Reporting isn't available for agent accounts.
        </div>
      </div>
    );
  }

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

  return (
    <div>
      <h1 className="text-xl font-bold text-slate-900 mb-4">{title}</h1>

      {/* Filters */}
      <div className="bg-white border rounded-xl p-4 mb-4 space-y-3">
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
          <span className="text-xs text-slate-400 ml-auto">
            {range ? `${range.from} → ${range.to} (${days} day${days === 1 ? '' : 's'}, max 31)` : 'Pick a valid range'}
          </span>
        </div>
        <div className="flex flex-wrap gap-2 items-center">
          {CATS.map((c) => {
            const on = filters.cats.includes(c.id);
            return (
              <button
                key={c.id}
                onClick={() => toggleCat(c.id)}
                className={`inline-flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-full border ${on
                  ? 'border-slate-300 bg-slate-50 text-slate-800'
                  : 'border-slate-200 text-slate-400 line-through'}`}
              >
                <span className="inline-block w-2.5 h-2.5 rounded-sm" style={{ background: c.color, opacity: on ? 1 : 0.3 }} />
                {c.label}
              </button>
            );
          })}
          <div className="relative">
            <button
              onClick={() => setShowAgents((v) => !v)}
              className="text-xs px-3 py-1.5 rounded-full border text-slate-600 hover:bg-slate-100"
            >
              {agentSel.length ? `Agents: ${agentSel.length} selected ▾` : 'Agents: all ▾'}
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
          <button
            onClick={() => exportCsv(isAgent ? user?.id : null)}
            disabled={exporting || !range}
            className="text-xs px-3 py-1.5 rounded-full border text-slate-700 hover:bg-slate-100 disabled:opacity-50 ml-auto"
          >
            {exporting ? 'Exporting…' : '⬇ Export CSV'}
          </button>
        </div>
      </div>

      {err && <div className="bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm mb-4">{err}</div>}
      {loading && <div className="text-sm text-slate-500 mb-4">Loading report…</div>}

      {!loading && !err && summary && total === 0 && (
        <div className="bg-white border rounded-xl p-5 text-sm text-slate-500 mb-4">
          {summary.tracking_since
            ? `No sends in this range. Tracking started ${summary.tracking_since} — sends before that date aren't categorized.`
            : `No sends logged yet in this scope. Tracking starts with new outbound sends.`}
        </div>
      )}

      {!loading && !err && summary && total > 0 && (
        <>
          {/* Summary cards */}
          <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
            <div className="bg-white border rounded-xl p-4 col-span-2 md:col-span-1">
              <div className="text-xs text-slate-500">Total sent</div>
              <div className="text-2xl font-bold tabular-nums">{total.toLocaleString()}</div>
              <div className="text-xs mt-1">
                {delta === null || delta === undefined ? (
                  <span className="text-slate-400">{summary.prev_total === 0 ? 'new in period' : '—'}</span>
                ) : delta > 0 ? (
                  <span className="text-emerald-600">▲ {delta}% <span className="text-slate-400">vs prev {days}d</span></span>
                ) : delta < 0 ? (
                  <span className="text-rose-500">▼ {Math.abs(delta)}% <span className="text-slate-400">vs prev {days}d</span></span>
                ) : (
                  <span className="text-slate-400">= 0% vs prev {days}d</span>
                )}
              </div>
            </div>
            {CATS.map((c) => (
              <div key={c.id} className="bg-white border rounded-xl p-4">
                <div className="text-xs text-slate-500 inline-flex items-center gap-1.5">
                  <span className="inline-block w-2 h-2 rounded-sm" style={{ background: c.color }} />
                  {c.label}
                </div>
                <div className="text-2xl font-bold tabular-nums">{(summary.by_category?.[c.id] || 0).toLocaleString()}</div>
              </div>
            ))}
          </div>

          {/* Charts */}
          <div className="grid md:grid-cols-2 gap-4 mb-4">
            <div className="bg-white border rounded-xl p-4">
              <div className="text-sm font-semibold mb-1">Volume by category</div>
              <StackedBars points={trend?.points || []} />
              <Legend byCat={summary.by_category} />
            </div>
            <div className="bg-white border rounded-xl p-4">
              <div className="text-sm font-semibold mb-1">
                Send trend{trend?.bucket === 'week' ? ' (weekly)' : ' (daily)'}
              </div>
              <TrendLine points={trend?.points || []} />
              <div className="text-xs text-slate-400 mt-2">Total sends per {trend?.bucket === 'week' ? 'week' : 'day'} · hover for values</div>
            </div>
          </div>

          {/* By agent */}
          <div className="bg-white border rounded-xl p-4 mb-4">
            <div className="text-sm font-semibold mb-2">By agent <span className="font-normal text-slate-400 text-xs">— click a row for message detail</span></div>
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
                  {sortedAgents.filter(agentVisible).map((a) => (
                    <tr
                      key={a.agent_id === null ? 'admin' : a.agent_id}
                      onClick={() => a.agent_id !== null && setDrill({ agent_id: a.agent_id, name: a.agent_name })}
                      className={`border-b last:border-0 tabular-nums ${a.agent_id !== null ? 'hover:bg-slate-50 cursor-pointer' : ''} ${drill?.agent_id === a.agent_id ? 'bg-slate-50' : ''}`}
                    >
                      <td className="px-3 py-2">{a.agent_name}</td>
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
                  {sortedAgents.filter(agentVisible).length === 0 && (
                    <tr><td colSpan="5" className="px-3 py-4 text-center text-slate-400 text-sm">No agent-attributed sends in this view.</td></tr>
                  )}
                </tbody>
              </table>
            </div>
            <div className="text-[11px] text-slate-400 mt-2">Mass SMS and auto-replies without an attributed agent count toward totals only.</div>
          </div>

          {/* By sending number */}
          <div className="bg-white border rounded-xl p-4 mb-4">
            <div className="text-sm font-semibold mb-2">By sending number</div>
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
                  {sortedNumbers.filter(numVisible).map((r) => (
                    <tr key={r.from_number ?? 'unknown'} className="border-b last:border-0 tabular-nums">
                      <td className="px-3 py-2">{r.from_number || <span className="text-slate-400">Unknown</span>}</td>
                      <td className="px-3 py-2 text-right font-medium">{r.total.toLocaleString()}</td>
                      <td className="px-3 py-2 text-right">{r.new_sms.toLocaleString()}</td>
                      <td className="px-3 py-2 text-right">{r.regular_reply.toLocaleString()}</td>
                      <td className="px-3 py-2 text-right">{r.mass_sms.toLocaleString()}</td>
                      <td className="px-3 py-2 text-right">{r.auto_reply.toLocaleString()}</td>
                    </tr>
                  ))}
                  {sortedNumbers.filter(numVisible).length === 0 && (
                    <tr><td colSpan="6" className="px-3 py-4 text-center text-slate-400 text-sm">No sends in this view.</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>

          {/* Agent drill-down */}
          {drill && (
            <div className="bg-white border rounded-xl p-4 mb-4">
              <div className="flex items-center gap-2 mb-2">
                <div className="text-sm font-semibold">Messages — {drill.name}</div>
                <span className="text-xs text-slate-400">({drillTotal.toLocaleString()} in range{drillTotal > 50 ? ', showing 50' : ''})</span>
                <button onClick={() => exportCsv(drill.agent_id, `agent-${drill.agent_id}`)} className="text-xs text-slate-600 hover:underline ml-auto">⬇ Export this agent</button>
                <button onClick={() => setDrill(null)} className="text-xs text-slate-400 hover:text-slate-700">✕ Close</button>
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
                            <span className="inline-block w-2 h-2 rounded-sm mr-1.5" style={{ background: catColor(r.category) }} />
                            {catLabel(r.category)}
                          </td>
                          <td className="px-3 py-1.5 text-xs whitespace-nowrap">{r.from_number || '—'} → {r.to_number || '—'}</td>
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
