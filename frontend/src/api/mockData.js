// Demo data mirroring the real Dynalink API shapes from the spec files.
// Used when no backend is configured (VITE_API_URL empty) so the UI is
// fully explorable. Swap to live by setting VITE_API_URL to Laravel.

export const mockUser = {
  username: '6001@1180.DynaCloud',
  user: '6001',
  domain: '1180.DynaCloud',
  display_name: 'Demo Agent',
  email: 'agent@example.com',
  scope: 'Office Manager',
  main_number: '12123527376',
};

// 150 numbers: deliberately over the provider's 100-row page default so the
// UI is exercised against a domain that would previously have been truncated.
const extraDemoNumbers = Array.from({ length: 150 }, (_, i) => {
  const area = [212, 646, 347, 917, 206, 305, 415, 617, 312, 480][i % 10];
  return `1${area}55${String(10000 + i * 7).slice(-5)}`;
});
export const mockSmsNumbers = [
  { number: '12123527376', application: 'user', domain: '1180.DynaCloud', dest: '7336', carrier: 'inteliquent', 'mms-capable': true, 'group-mms-capable': true },
  { number: '16465885860', application: 'user', domain: '1180.DynaCloud', dest: '7014', carrier: 'inteliquent', 'mms-capable': true, 'group-mms-capable': true },
  // Padding so the Integration number picker is exercised at realistic scale (20+).
  // Distinct extensions + mixed MMS capability so the Numbers page filters
  // and capability pills are exercised realistically.
  ...extraDemoNumbers.map((n, i) => ({
    number: n, application: 'user', domain: '1180.DynaCloud',
    dest: String(7000 + i * 17), carrier: 'inteliquent',
    'mms-capable': i % 5 !== 0,
    'group-mms-capable': i % 3 !== 0,
  })),
];

export const mockContacts = [
  { 'unique-id': 'c-001', 'name-first-name': 'Craig', 'name-last-name': 'Patane', email: 'craig@example.com', company: 'Acme Inc', 'phonenumber-cell': '19175551212', 'phonenumber-work': '', 'phonenumber-home': '', department: 'Admin' },
  { 'unique-id': 'c-002', 'name-first-name': 'Alexa', 'name-last-name': 'Rivera', email: 'rachel@globalexterminating.com', company: 'Global Exterminating', 'phonenumber-cell': '17185550101', 'phonenumber-work': '17185550102', 'phonenumber-home': '', department: 'SCHEDULING' },
  { 'unique-id': 'c-003', 'name-first-name': '927 Front', 'name-last-name': 'Door', email: 'mail@birns.net', company: 'Birns', 'phonenumber-cell': '13475550103', 'phonenumber-work': '', 'phonenumber-home': '', department: 'Admin' },
  { 'unique-id': 'c-004', 'name-first-name': 'Maria', 'name-last-name': 'Santos', email: 'maria@example.com', company: 'Acme Inc', 'phonenumber-cell': '16505550104', 'phonenumber-work': '', 'phonenumber-home': '', department: 'Sales' },
];

export const mockSessions = [
  {
    user: '6001', domain: '1180.DynaCloud',
    'messagesession-id': '465f0a6d385f9dd9873f2fcb32fcecce',
    'messagesession-remote': 19175778756,
    'messagesession-sms-number': 12123527376,
    'messagesession-last-datetime': '2026-09-10T13:24:49+00:00',
    'messagesession-last-message': "My second floor door Bell is not working it's button 2 on the door when pressing it hangs up right away",
    'messagesession-last-sender': 19175778756,
    'messagesession-last-status': 'unread',
    'messagesession-last-type': 'sms',
  },
  {
    user: '6001', domain: '1180.DynaCloud',
    'messagesession-id': '01e43c6bc6c16fe560b6bf62985445f3',
    'messagesession-remote': 17185550101,
    'messagesession-sms-number': 12123527376,
    'messagesession-last-datetime': '2026-09-11T23:01:46+00:00',
    'messagesession-last-message': 'Thanks! Tech is on the way.',
    'messagesession-last-sender': 12123527376,
    'messagesession-last-status': 'read',
    'messagesession-last-type': 'sms',
  },
  {
    user: '6001', domain: '1180.DynaCloud',
    'messagesession-id': 'aa11bb22cc33dd44ee55ff6600112233',
    'messagesession-remote': 16505550104,
    'messagesession-sms-number': 16465885860,
    'messagesession-last-datetime': '2026-09-09T09:12:00+00:00',
    'messagesession-last-message': 'Can we reschedule for Friday?',
    'messagesession-last-sender': 16505550104,
    'messagesession-last-status': 'read',
    'messagesession-last-type': 'sms',
  },
];

export const mockMessages = {
  '465f0a6d385f9dd9873f2fcb32fcecce': [
    { id: 'm-6a2957534181b', timestamp: '2026-06-10 12:23:47', type: 'sms', direction: 'term', dialed: 19175778756, text: 'Number Searched: 7184350471\nCNAM Response: PATANE CRAIG PA', status: 'sent', 'from-number': 12123527376, 'messagesession-id': '465f0a6d385f9dd9873f2fcb32fcecce' },
    { id: '2981FE71', timestamp: '2026-09-10 13:24:49', type: 'sms', direction: 'orig', dialed: 12123527376, text: "My second floor door Bell is not working it's button 2 on the door when pressing it hangs up right away", status: '', 'from-number': 19175778756, 'messagesession-id': '465f0a6d385f9dd9873f2fcb32fcecce' },
    // MMS with an image attachment, so the inline preview + lightbox are
    // exercisable without a live provider. Inline SVG data URI = no network.
    { id: 'm-mms-1', timestamp: '2026-09-10 13:26:10', type: 'mms', direction: 'orig', dialed: 12123527376, text: 'Here is a photo of the panel', status: '', 'from-number': 19175778756, 'messagesession-id': '465f0a6d385f9dd9873f2fcb32fcecce', 'mime-type': 'image/svg+xml', 'file-access-url': 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="320" height="240"><rect width="320" height="240" fill="#334155"/><circle cx="160" cy="110" r="56" fill="#38bdf8"/><text x="160" y="205" font-size="20" fill="#e2e8f0" text-anchor="middle">door panel.jpg</text></svg>') },
  ],
  '01e43c6bc6c16fe560b6bf62985445f3': [
    { id: 'm-6aa4885a91e4a', timestamp: '2026-09-11 23:01:46', type: 'sms', direction: 'term', dialed: 17185550101, text: 'Tech is on the way, ETA 20 min.', status: 'sent', 'from-number': 12123527376, 'messagesession-id': '01e43c6bc6c16fe560b6bf62985445f3' },
    { id: 'm-reply-1', timestamp: '2026-09-11 23:02:30', type: 'sms', direction: 'orig', dialed: 12123527376, text: 'Thanks! Tech is on the way.', status: '', 'from-number': 17185550101, 'messagesession-id': '01e43c6bc6c16fe560b6bf62985445f3' },
  ],
  'aa11bb22cc33dd44ee55ff6600112233': [
    { id: 'm-x1', timestamp: '2026-09-09 09:12:00', type: 'sms', direction: 'orig', dialed: 16465885860, text: 'Can we reschedule for Friday?', status: '', 'from-number': 16505550104, 'messagesession-id': 'aa11bb22cc33dd44ee55ff6600112233' },
  ],
};

export const mockGroups = [
  { id: 'g-1', name: 'VIP Clients', domain: '1180.DynaCloud', description: 'Top accounts', members: [
    { 'unique-id': 'c-001', 'name-first-name': 'Craig', 'name-last-name': 'Patane', company: 'Acme Inc', phone: '19175551212' },
    { 'unique-id': 'c-004', 'name-first-name': 'Maria', 'name-last-name': 'Santos', company: 'Acme Inc', phone: '16505550104' },
  ], created_at: '2026-09-01T10:00:00+00:00', updated_at: '2026-09-01T10:00:00+00:00' },
];

export const mockTemplates = [
  { id: 1, name: 'Appointment Reminder', body: 'Hi {{first_name}}, this is a reminder of your appointment tomorrow at {{time}}. Reply YES to confirm.', shared: true },
  { id: 2, name: 'Tech On The Way', body: 'Hi {{first_name}}, our technician is on the way. ETA {{eta}}.', shared: true },
  { id: 3, name: 'After-hours Reply', body: 'Thanks for contacting us! Our office is currently closed. We will reply during business hours.', shared: false },
];

export const mockScheduled = [
  { id: 1, domain: '1180.DynaCloud', user: '6001', name: 'Friday Promo', message: 'Weekend special! 20% off all services. Reply STOP to opt out.', from_number: '12123527376', type: 'sms', send_at: '2026-09-18T09:00:00', targets: { group_ids: ['g-1'] }, recipients: [{ phone: '19175551212', name: 'Craig Patane' }, { phone: '16505550104', name: 'Maria Santos' }], status: 'pending', send_log: [] },
];
