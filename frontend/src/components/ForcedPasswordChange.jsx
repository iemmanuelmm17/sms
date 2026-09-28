import { ChangePasswordForm } from './ChangePasswordModal';
import { fmtExpiry } from '../lib/passwordPolicy';

/**
 * Day-0 block. Shown when password_expires_at has actually passed — either
 * because login was refused (credentials were right, the password just
 * lapsed) or because an already-signed-in session rolled past expiry and the
 * next authenticated request came back 409 password_expired.
 *
 * There is deliberately no Cancel and no way around this screen: it reuses
 * the shared ChangePasswordForm in forced mode (no current-password field,
 * same complexity + reuse rules, same audit path as anywhere else).
 */
export default function ForcedPasswordChange({ expiresAt, onDone }) {
  return (
    <div className="fixed inset-0 z-[60] bg-slate-900 flex items-center justify-center p-4">
      <div className="w-full max-w-md bg-white rounded-2xl shadow-2xl p-6">
        <div className="text-3xl mb-2">🔒</div>
        <h1 className="text-lg font-bold text-slate-900">Your password has expired</h1>
        <p className="text-sm text-slate-500 mt-1 mb-4">
          {expiresAt && expiresAt !== '1'
            ? <>It expired on <span className="font-semibold text-slate-700">{fmtExpiry(expiresAt)}</span>. </>
            : 'It is past its expiry date. '}
          Choose a new password to continue — you&apos;ll be signed straight in.
        </p>
        <ChangePasswordForm forced submitLabel="Set new password" onDone={onDone} />
      </div>
    </div>
  );
}
