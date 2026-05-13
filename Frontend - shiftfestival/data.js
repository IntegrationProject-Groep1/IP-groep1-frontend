// Mock data for the Shift Festival event platform prototype.

window.SHIFT_DATA = {
  event: {
    name: "Shift Festival 2026",
    tagline: "Three days. Twelve stages. One integrated experience.",
    dates: "12 – 14 May 2026",
    venue: "Desiderius Campus · Brussels",
    description:
      "An annual gathering for engineers, designers and operators building integration systems. Workshops, keynotes, and late-night demos across twelve venues on the Desiderius campus.",
  },

  sessions: [
    {
      id: "S-201",
      title: "Opening Keynote — The Decade of Integration",
      type: "keynote",
      speaker: "Inez Vandenbroucke",
      track: "Main Hall",
      start: "Tue 12 May · 09:30",
      end: "10:30",
      duration: "60 min",
      location: "Auditorium A",
      capacity: 480,
      enrolled: 312,
      tags: ["Keynote", "All audiences"],
      blurb:
        "How distributed teams build durable systems with message-driven contracts — patterns, traps, and the next ten years.",
    },
    {
      id: "S-204",
      title: "Workshop: XSD-first contracts in practice",
      type: "workshop",
      speaker: "Charles Wong · Jarno Janssens",
      track: "Lab",
      start: "Tue 12 May · 11:00",
      end: "13:00",
      duration: "2 hr",
      location: "Lab Room 4",
      capacity: 32,
      enrolled: 31,
      tags: ["Hands-on", "Intermediate"],
      blurb:
        "Migrate a fan-out user.created flow to a v2.0 single-routing-key contract. Bring a laptop with Docker.",
    },
    {
      id: "S-211",
      title: "RabbitMQ at scale: dead letters, retries, and silent drops",
      type: "talk",
      speaker: "Ilyas Fariss",
      track: "Stage B",
      start: "Tue 12 May · 14:30",
      end: "15:15",
      duration: "45 min",
      location: "Stage B",
      capacity: 220,
      enrolled: 118,
      tags: ["Engineering", "Advanced"],
      blurb:
        "A tour of the failure modes that cost us seven weeks of post-mortems — and the seven heuristics we kept.",
    },
    {
      id: "S-217",
      title: "Design panel: When the contract is the product",
      type: "panel",
      speaker: "Dries Michiels +3",
      track: "Stage C",
      start: "Tue 12 May · 16:00",
      end: "17:00",
      duration: "60 min",
      location: "Stage C",
      capacity: 180,
      enrolled: 89,
      tags: ["Design", "Strategy"],
      blurb:
        "How product, design, and platform teams negotiate XML schemas without burning the building down.",
    },
    {
      id: "S-302",
      title: "Welcome Reception",
      type: "reception",
      speaker: "Hosted by the Festival team",
      track: "Atrium",
      start: "Tue 12 May · 19:00",
      end: "22:00",
      duration: "3 hr",
      location: "Atrium · Glass Pavilion",
      capacity: 900,
      enrolled: 612,
      tags: ["Social", "Food & drinks"],
      blurb:
        "Belgian beer, finger food, and a chance to meet the speakers. Badge required at entry.",
    },
    {
      id: "S-401",
      title: "Workshop: Identity-first registration flows",
      type: "workshop",
      speaker: "Inez Vandenbroucke",
      track: "Lab",
      start: "Wed 13 May · 09:30",
      end: "11:30",
      duration: "2 hr",
      location: "Lab Room 2",
      capacity: 32,
      enrolled: 18,
      tags: ["Hands-on", "Intermediate"],
      blurb:
        "Build a registration form that calls an Identity RPC before the CRM event lands. Includes a wallet stub.",
    },
    {
      id: "S-415",
      title: "Lightning talks: payment, badges, and the kassa",
      type: "talk",
      speaker: "Seven speakers · 5 min each",
      track: "Stage B",
      start: "Wed 13 May · 13:00",
      end: "13:45",
      duration: "45 min",
      location: "Stage B",
      capacity: 220,
      enrolled: 64,
      tags: ["Lightning", "All audiences"],
      blurb:
        "Seven five-minute talks on payment registered events, badge scans, and the long shadow of the kassa.",
    },
    {
      id: "S-512",
      title: "Closing Keynote — What we shipped, what broke",
      type: "keynote",
      speaker: "All four team leads",
      track: "Main Hall",
      start: "Thu 14 May · 17:00",
      end: "18:00",
      duration: "60 min",
      location: "Auditorium A",
      capacity: 480,
      enrolled: 201,
      tags: ["Keynote", "All audiences"],
      blurb:
        "An unvarnished post-mortem from the four teams who built the platform. Bring questions.",
    },
  ],

  // Initial enrollments for the seeded attendee.
  initialEnrollments: ["S-201", "S-204", "S-302"],

  // Initial company invites for the seeded company admin.
  initialInvites: [
    { email: "marieke.delvaux@studio-twelve.be", sent: "2 May", status: "accepted", name: "Marieke Delvaux" },
    { email: "olivier.serrano@studio-twelve.be", sent: "4 May", status: "pending", name: null },
    { email: "noah.hendricks@studio-twelve.be", sent: "6 May", status: "pending", name: null },
  ],

  // Initial wallet transactions.
  initialWallet: {
    balance: 42.5,
    transactions: [
      { id: "T-091", date: "8 May", label: "Top-up · iDEAL", amount: 50.0, kind: "credit" },
      { id: "T-088", date: "8 May", label: "Lunch voucher · Lab Café", amount: -7.5, kind: "debit" },
      { id: "T-082", date: "6 May", label: "Welcome credit", amount: 25.0, kind: "credit" },
      { id: "T-080", date: "6 May", label: "Espresso · Stage B kiosk", amount: -3.0, kind: "debit" },
      { id: "T-077", date: "5 May", label: "Top-up · Bancontact", amount: 25.0, kind: "credit" },
    ],
  },

  // Seeded user.
  seedUser: {
    firstName: "Léa",
    lastName: "Marchand",
    email: "lea.marchand@studio-twelve.be",
    company: "Studio Twelve",
    role: "company-admin",
    masterUuid: "u-7f3a-2c41-9b8e-018d",
    badgeId: "BDG-08412",
    dateOfBirth: "1994-08-22",
    vat: "BE0784.512.901",
  },
};
