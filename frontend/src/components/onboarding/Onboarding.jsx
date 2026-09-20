import { useEffect, useRef, useState } from 'react';
import { useOnboarding } from './useOnboarding';
import { subscribePwa } from '../../lib/pwa';
import { toastSuccess } from '../../lib/toast';
import WelcomeModal from './WelcomeModal';
import Checklist from './Checklist';
import Spotlight from './Spotlight';

/**
 * Orchestrator (mounted once, at the top of Messages):
 * welcome modal → checklist → 3-step spotlight tour.
 */
export default function Onboarding() {
  const { state, save } = useOnboarding();
  const [installed, setInstalled] = useState(false);
  const prevDone = useRef(null);

  useEffect(() => subscribePwa((s) => setInstalled(!!s.installed)), []);

  // PWA-installed auto-detection stamps the step server-side.
  useEffect(() => {
    if (state && !state.dismissed && !state.done && installed && !state.steps?.installed) {
      save({ step: 'installed' });
    }
  }, [state, installed, save]);

  useEffect(() => {
    if (prevDone.current === false && state?.done === true) {
      toastSuccess('Onboarding complete — nice! 🎉');
    }
    if (state) prevDone.current = state.done;
  }, [state]);

  if (!state || state.dismissed) return null;
  return (
    <>
      {!state.welcomed && <WelcomeModal />}
      {!state.done && <Checklist />}
      {state.welcomed && !state.tour_seen && <Spotlight onDone={() => save({ tour_seen: true })} />}
    </>
  );
}
