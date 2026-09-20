// Step catalog for first-run onboarding. The backend owns completion state
// (PUT /api/me/onboarding); this file is copy + links only.
export const ONBOARDING_STEPS = {
  agent: [
    { id: 'installed', title: 'Install the app', desc: 'One tap from your home screen — no browser needed.', kind: 'install' },
    { id: 'push', title: 'Turn on push alerts', desc: 'Know the moment a customer replies.', link: '/app/settings', cta: 'Open Settings' },
    { id: 'tag', title: 'Pick your tag color', desc: 'Your color marks every thread you own.', link: '/app/settings', cta: 'Choose color' },
    { id: 'first_send', title: 'Send your first reply', desc: 'Open any conversation and reply — this checks itself off.', kind: 'auto' },
  ],
  admin: [
    { id: 'installed', title: 'Install the app', desc: 'One tap from your home screen — no browser needed.', kind: 'install' },
    { id: 'push', title: 'Turn on push alerts', desc: 'Know the moment a customer replies.', link: '/app/settings', cta: 'Open Settings' },
    { id: 'agent_created', title: 'Invite your first agent', desc: 'Agents get their own login and claim threads from the queue.', link: '/app/agents', cta: 'Open Agents' },
    { id: 'numbers_reviewed', title: 'Review your numbers', desc: 'Assign agents and notification emails per number.', link: '/app/numbers', cta: 'Open Numbers' },
  ],
};

export const stepsFor = (role) => ONBOARDING_STEPS[role] || [];
