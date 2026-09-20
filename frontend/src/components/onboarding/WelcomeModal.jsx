import Modal from '../Modal';
import { useAuth } from '../../context/AuthContext';
import { fmtPhone } from '../../api/client';
import { useOnboarding } from './useOnboarding';

/** One-screen welcome on first login. Never blocks the app — Skip kills the tour too. */
export default function WelcomeModal() {
  const { user } = useAuth();
  const { save } = useOnboarding();
  const isAgent = user?.role === 'agent';
  const first = user?.first_name || (user?.display_name || '').split(' ')[0] || 'there';
  const nums = isAgent ? (user?.assigned_numbers || []) : [];
  const skip = () => save({ welcomed: true, tour_seen: true });

  return (
    <Modal wide="max-w-lg" onClose={skip}>
      <h2 className="text-lg font-bold text-slate-800">Welcome{isAgent ? `, ${first}` : ''}! 👋</h2>
      {isAgent ? (
        <div className="mt-2 space-y-2 text-sm text-slate-600">
          <p>You're set up as an agent. Claim threads from the Pending Queue, reply, and you're doing the job.</p>
          <p>
            <span className="font-semibold text-slate-700">Your numbers: </span>
            {nums.length > 0 ? nums.map(fmtPhone).join(', ') : 'none assigned yet — ask your admin.'}
          </p>
          <p className="text-xs text-slate-500">Questions about anything? Ask your tenant admin.</p>
        </div>
      ) : (
        <div className="mt-2 space-y-2 text-sm text-slate-600">
          <p>Three things to get going:</p>
          <ol className="list-decimal ml-5 space-y-1">
            <li><span className="font-semibold text-slate-700">Invite agents</span> — they get their own login.</li>
            <li><span className="font-semibold text-slate-700">Review Numbers</span> — assign agents per number.</li>
            <li><span className="font-semibold text-slate-700">Watch the queue</span> — unassigned threads land there.</li>
          </ol>
        </div>
      )}
      <div className="mt-4 flex gap-2">
        <button onClick={() => save({ welcomed: true })}
          className="flex-1 bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg py-2">
          Show me around
        </button>
        <button onClick={skip} className="px-4 text-sm text-slate-500 hover:text-slate-700">Skip</button>
      </div>
    </Modal>
  );
}
