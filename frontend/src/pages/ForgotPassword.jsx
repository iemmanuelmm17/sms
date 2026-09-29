import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../api/client';
import { toastError, toastSuccess } from '../lib/toast';

/** Forgot-password for agents and tenant admins: username -> secret answer -> new password. */
export default function ForgotPassword() {
  const [step, setStep] = useState(1);
  const [username, setUsername] = useState('');
  const [challenge, setChallenge] = useState('');
  const [question, setQuestion] = useState('');
  const [answer, setAnswer] = useState('');
  const [pw1, setPw1] = useState('');
  const [pw2, setPw2] = useState('');
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  const nav = useNavigate();
  const [params] = useSearchParams();
  const isAdmin = params.get('as') === 'admin';

  const fail = (e, fallback) => {
    const msg = e?.response?.data?.message || fallback;
    setErr(msg); toastError(msg);
  };

  const doVerify = async (e) => {
    e.preventDefault();
    if (!username.trim()) return;
    setErr(''); setBusy(true);
    try {
      const d = await (isAdmin ? api.tenantForgotStart : api.forgotStart)(username.trim());
      setChallenge(d.challenge); setQuestion(d.question); setStep(2);
    } catch (ex) { fail(ex, 'Verification failed.'); }
    finally { setBusy(false); }
  };

  const doAnswer = async (e) => {
    e.preventDefault();
    if (!answer.trim()) return;
    setErr(''); setBusy(true);
    try {
      await (isAdmin ? api.tenantForgotAnswer : api.forgotAnswer)(challenge, answer);
      setStep(3);
    } catch (ex) { fail(ex, 'Incorrect answer.'); }
    finally { setBusy(false); }
  };

  const doReset = async (e) => {
    e.preventDefault();
    if (pw1.length < 8) { const m = 'New password needs at least 8 characters.'; setErr(m); toastError(m); return; }
    if (pw1 !== pw2) { const m = 'Passwords do not match.'; setErr(m); toastError(m); return; }
    setErr(''); setBusy(true);
    try {
      await (isAdmin ? api.tenantForgotComplete : api.forgotComplete)(challenge, pw1);
      toastSuccess('Password reset — sign in with your new password.');
      nav('/login');
    } catch (ex) { fail(ex, 'Reset failed.'); }
    finally { setBusy(false); }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-900 px-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-8">
        <div className="w-12 h-12 rounded-xl bg-brand-600 text-white flex items-center justify-center text-2xl font-bold mb-4">S</div>
        <h1 className="text-2xl font-bold text-slate-900">Reset password</h1>
        <p className="text-sm text-slate-500 mb-6">{isAdmin ? 'Tenant admin' : 'Agent'} accounts — step {Math.min(step, 3)} of 3</p>

        {step === 1 && (
          <form onSubmit={doVerify} className="space-y-4">
            <div>
              <label htmlFor="fp-username" className="text-sm font-medium text-slate-700">{isAdmin ? 'Admin username' : 'Agent username'}</label>
              <input id="fp-username" value={username} onChange={(e) => setUsername(e.target.value)} placeholder={isAdmin ? 'admin@tenantname' : 'maria@tenantname'} autoFocus
                className="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            </div>
            {err && <div role="alert" className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg p-2">{err}</div>}
            <button disabled={busy} className="w-full bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold">
              {busy ? 'Verifying…' : 'Verify'}
            </button>
          </form>
        )}

        {step === 2 && (
          <form onSubmit={doAnswer} className="space-y-4">
            <div className="text-sm bg-slate-50 border rounded-lg p-3">
              <span className="text-slate-500">Secret question:</span>
              <div className="font-medium text-slate-800 mt-0.5">{question}</div>
            </div>
            <div>
              <label htmlFor="fp-answer" className="text-sm font-medium text-slate-700">Your answer</label>
              <input id="fp-answer" value={answer} onChange={(e) => setAnswer(e.target.value)} autoFocus
                className="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            </div>
            {err && <div role="alert" className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg p-2">{err}</div>}
            <button disabled={busy} className="w-full bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold">
              {busy ? 'Checking…' : 'Verify answer'}
            </button>
          </form>
        )}

        {step === 3 && (
          <form onSubmit={doReset} className="space-y-4">
            <div>
              <label htmlFor="fp-pw1" className="text-sm font-medium text-slate-700">New password (min 8 characters)</label>
              <input id="fp-pw1" type="password" value={pw1} onChange={(e) => setPw1(e.target.value)} autoFocus
                className="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            </div>
            <div>
              <label htmlFor="fp-pw2" className="text-sm font-medium text-slate-700">Confirm new password</label>
              <input id="fp-pw2" type="password" value={pw2} onChange={(e) => setPw2(e.target.value)}
                className="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            </div>
            {err && <div role="alert" className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg p-2">{err}</div>}
            <button disabled={busy} className="w-full bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold">
              {busy ? 'Saving…' : 'Set new password'}
            </button>
          </form>
        )}

        <p className="mt-4 text-xs text-slate-500 text-center">
          <Link to="/login" className="text-brand-600 hover:underline">← Back to sign in</Link>
        </p>
      </div>
    </div>
  );
}
