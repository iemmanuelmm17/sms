import { useEffect, useMemo, useState } from 'react';
import { api } from '../../api/client';
import Reporting from '../Reporting';
import { CATS, rangeFor } from '../../lib/reporting';

export default function SuperReporting() {
  const [filters, setFilters] = useState({ preset: 'last7', from: '', to: '', cats: CATS.map((c) => c.id) });
  const [sel, setSel] = useState(null); // null = all, number = tenant, 'legacy'
  const [trows, setTrows] = useState([]);
  const [tloading, setTloading] = useState(true);
  const [q, setQ] = useState('');
  const [sort, setSort] = useState({ key: 'total', dir: 'desc' });

  const range = rangeFor(filters.preset, filters.from, filters.to);
  const rangeStr = range ? `${range.from}|${range.to}` : '';
  const catsKey = [...filters.cats].sort().join(',');

  useEffect(() => {
    if (!range) return;
    let dead = false;
    setTloading(true);
    const p = { from: range.from, to: range.to };
    if (filters.cats.length < CATS.length) p.categories = filters.cats.join(',');
    api.superReportTenants(p)
      .then((d) => { if (!dead) setTrows(d.rows || []); })
      .catch(() => { if (!dead) setTrows([]); })
      .finally(() => { if (!dead) setTloading(false); });
    return () => { dead = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rangeStr, catsKey]);

  const tenantMap = useMemo(() => {
    const m = {};
    trows.forEach((t) => { m[t.tenant_id] = t.tenant_name; });
    return m;
  }, [trows]);

  const selName = sel === null ? '' : (trows.find((t) => String(t.tenant_id) === String(sel))?.tenant_name || '');

  const sortBy = (key) => setSort((s) => (s.key === key
    ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' }
    : { key, dir: key === 'tenant_name' ? 'asc' : 'desc' }));

  const th = (label, key, num = true) => (
    <th
      key={key}
      onClick={() => sortBy(key)}
      className={`px-3 py-2 font-medium cursor-pointer select-none whitespace-nowrap ${num ? 'text-right' : 'text-left'}`}
      title="Sort"
    >
      {label} {sort.key === key && (sort.dir === 'asc' ? '▲' : '▼')}
    </th>
  );

  const visible = useMemo(() => {
    const needle = q.trim().toLowerCase();
    const rows = needle ? trows.filter((t) => String(t.tenant_name).toLowerCase().includes(needle)) : [...trows];
    const { key, dir } = sort;
    const m = dir === 'asc' ? 1 : -1;
    rows.sort((a, b) => {
      if (key === 'tenant_name') return String(a.tenant_name).localeCompare(String(b.tenant_name)) * m;
      return ((a[key] ?? -1) - (b[key] ?? -1)) * m;
    });
    return rows;
  }, [trows, q, sort]);

  return (
    <div>
      <h1 className="text-xl font-bold text-slate-900 mb-1">Reporting</h1>
      <p className="text-xs text-slate-400 mb-4">Cross-tenant send volume. Pick a tenant to drill into its report below.</p>

      <div className="flex flex-wrap items-center gap-2 mb-4">
        <select
          value={sel === null ? '' : String(sel)}
          onChange={(e) => setSel(e.target.value === '' ? null : (e.target.value === 'legacy' ? 'legacy' : Number(e.target.value)))}
          className="border rounded-lg px-3 py-2 text-sm bg-white"
        >
          <option value="">All tenants (aggregate)</option>
          {trows.map((t) => (
            <option key={String(t.tenant_id)} value={String(t.tenant_id)}>{t.tenant_name}</option>
          ))}
        </select>
        {sel !== null && (
          <button onClick={() => setSel(null)} className="text-xs text-slate-500 hover:underline">
            ✕ back to aggregate
          </button>
        )}
      </div>

      <Reporting
        apiBase="/api/superadmin/reports"
        tenantId={sel}
        tenantName={selName}
        filters={filters}
        setFilters={setFilters}
        tenantMap={tenantMap}
      />

      <div className="bg-white border rounded-xl p-4 mt-4">
        <div className="flex items-center gap-2 mb-2">
          <div className="text-sm font-semibold">Tenants</div>
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Filter by name…"
            className="ml-auto border rounded-lg px-3 py-1.5 text-sm w-48"
          />
        </div>
        {tloading && <div className="text-sm text-slate-500">Loading…</div>}
        {!tloading && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-xs text-slate-500 border-b">
                  {th('Tenant', 'tenant_name', false)}
                  {th('Total', 'total')}
                  {th('New', 'new_sms')}
                  {th('Regular', 'regular_reply')}
                  {th('Mass', 'mass_sms')}
                  {th('Auto', 'auto_reply')}
                  {th('Agents', 'active_agents')}
                </tr>
              </thead>
              <tbody>
                {visible.map((t) => (
                  <tr
                    key={String(t.tenant_id)}
                    onClick={() => setSel(t.tenant_id === 'legacy' ? 'legacy' : Number(t.tenant_id))}
                    className={`border-b last:border-0 tabular-nums hover:bg-slate-50 cursor-pointer ${String(sel) === String(t.tenant_id) ? 'bg-slate-50' : ''}`}
                  >
                    <td className="px-3 py-2">{t.tenant_name}</td>
                    <td className="px-3 py-2 text-right font-medium">{(t.total || 0).toLocaleString()}</td>
                    <td className="px-3 py-2 text-right">{(t.new_sms || 0).toLocaleString()}</td>
                    <td className="px-3 py-2 text-right">{(t.regular_reply || 0).toLocaleString()}</td>
                    <td className="px-3 py-2 text-right">{(t.mass_sms || 0).toLocaleString()}</td>
                    <td className="px-3 py-2 text-right">{(t.auto_reply || 0).toLocaleString()}</td>
                    <td className="px-3 py-2 text-right">{t.active_agents === null || t.active_agents === undefined ? '—' : t.active_agents}</td>
                  </tr>
                ))}
                {visible.length === 0 && (
                  <tr><td colSpan="7" className="px-3 py-4 text-center text-slate-400 text-sm">No tenants match.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        )}
        <div className="text-[11px] text-slate-400 mt-2">Click a row to drill into that tenant's report above. High-volume tenants are worth a cost/carrier-compliance glance.</div>
      </div>
    </div>
  );
}
