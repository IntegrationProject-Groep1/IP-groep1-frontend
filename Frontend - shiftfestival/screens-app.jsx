// Authenticated app screens: shell + dashboard + sessions + admin.
const { useState: useStateA, useMemo: useMemoA, useEffect: useEffectA } = React;

/* ──────────────────────────────────────────────────────────────
   APP SHELL — sidebar, top bar, content slot
   ────────────────────────────────────────────────────────────── */

function AppShell({ state, actions, children }) {
  const NAV = [
    { id: "dashboard", label: "Dashboard", icon: "home" },
    { id: "badge", label: "My Badge", icon: "qr" },
    { id: "sessions", label: "Browse Sessions", icon: "calendar" },
    { id: "my-sessions", label: "My Sessions", icon: "check" },
    { id: "wallet", label: "Wallet", icon: "wallet" },
  ];
  const ADMIN_NAV = [
    { id: "admin-sessions", label: "Manage Sessions", icon: "settings" },
    { id: "company", label: "Team & Invites", icon: "users" },
  ];

  const isAdmin = state.user?.role === "company-admin";

  return (
    <div className="shell" data-screen-label="App">
      <aside className="shell-side">
        <div className="shell-brand">
          <BrandMark/>
          <div>
            <div className="brand-name brand-name-stack">Shift Festival</div>
            <div className="brand-tag">2026 · Attendee</div>
          </div>
        </div>

        <nav className="shell-nav">
          <div className="shell-nav-group">
            <div className="shell-nav-label">Festival</div>
            {NAV.map((n) => (
              <button
                key={n.id}
                className={`shell-nav-item ${state.route === n.id ? "is-active" : ""}`}
                onClick={() => actions.go(n.id)}
              >
                <Icon name={n.icon} size={16}/>
                <span>{n.label}</span>
                {n.id === "my-sessions" && state.enrollments.length > 0 && (
                  <span className="nav-count">{state.enrollments.length}</span>
                )}
              </button>
            ))}
          </div>

          {isAdmin && (
            <div className="shell-nav-group">
              <div className="shell-nav-label">Company admin</div>
              {ADMIN_NAV.map((n) => (
                <button
                  key={n.id}
                  className={`shell-nav-item ${state.route === n.id ? "is-active" : ""}`}
                  onClick={() => actions.go(n.id)}
                >
                  <Icon name={n.icon} size={16}/>
                  <span>{n.label}</span>
                </button>
              ))}
            </div>
          )}
        </nav>

        <div className="shell-user">
          <div className="shell-user-avatar">{initials(state.user)}</div>
          <div className="shell-user-meta">
            <div className="shell-user-name">{state.user?.firstName} {state.user?.lastName}</div>
            <div className="shell-user-sub">{state.user?.email}</div>
          </div>
          <button className="icon-btn" title="Sign out" onClick={actions.logout}>
            <Icon name="logout" size={14}/>
          </button>
        </div>
      </aside>

      <main className="shell-main">
        <div className="shell-topbar">
          <div className="shell-crumbs">
            <span>{routeLabel(state.route)}</span>
          </div>
          <div className="shell-topbar-actions">
            <div className="event-pill">
              <span className="event-pill-dot"/>
              <span>Live · {window.SHIFT_DATA.event.dates}</span>
            </div>
            <button className="icon-btn" onClick={() => actions.go("badge")} title="Open my badge">
              <Icon name="qr" size={16}/>
            </button>
          </div>
        </div>
        <div className="shell-content">
          {children}
        </div>
      </main>
    </div>
  );
}

function initials(u) {
  if (!u) return "?";
  return `${(u.firstName||"?")[0]}${(u.lastName||"")[0]}`.toUpperCase();
}

function routeLabel(r) {
  const map = {
    dashboard: "Dashboard",
    badge: "My Badge",
    sessions: "Browse Sessions",
    "my-sessions": "My Sessions",
    wallet: "Wallet",
    "admin-sessions": "Manage Sessions",
    company: "Team & Invites",
    profile: "Account",
  };
  return map[r] || "Dashboard";
}

/* ──────────────────────────────────────────────────────────────
   DASHBOARD
   ────────────────────────────────────────────────────────────── */

function DashboardScreen({ state, actions }) {
  const next = useMemoA(() => {
    return state.enrollments
      .map((id) => window.SHIFT_DATA.sessions.find((s) => s.id === id))
      .filter(Boolean);
  }, [state.enrollments]);

  return (
    <div className="dash">
      <header className="dash-hero">
        <div className="dash-hero-lhs">
          <span className="eyebrow">Welcome back</span>
          <h1>Hi {state.user?.firstName} — your festival starts in <strong>4 days</strong>.</h1>
          <p>You're enrolled in <strong>{state.enrollments.length}</strong> session{state.enrollments.length === 1 ? "" : "s"}. Your digital badge is ready to use at the door.</p>
          <div className="dash-hero-ctas">
            <Button onClick={() => actions.go("badge")} icon="qr">Open my badge</Button>
            <Button variant="ghost" onClick={() => actions.go("sessions")} iconRight="arrow-right">Browse the programme</Button>
          </div>
        </div>
        <div className="dash-hero-rhs">
          <MiniBadge state={state}/>
        </div>
      </header>

      <div className="dash-grid">
        <Card>
          <SectionLabel action={<a className="muted-link" onClick={() => actions.go("my-sessions")}>See all <Icon name="arrow-right" size={12}/></a>}>
            Up next on your schedule
          </SectionLabel>
          {next.length === 0 ? (
            <EmptyState
              title="You haven't enrolled in any sessions yet."
              body="The programme is published. Pick the talks, workshops, and panels you want to attend."
              action={<Button size="sm" onClick={() => actions.go("sessions")}>Browse sessions</Button>}
            />
          ) : (
            <ul className="schedule-list">
              {next.slice(0, 3).map((s) => (
                <li key={s.id}>
                  <div className="schedule-time">
                    <strong>{s.start.split("·")[1]?.trim() || "—"}</strong>
                    <span>{s.start.split("·")[0]?.trim()}</span>
                  </div>
                  <div className="schedule-meta">
                    <div className="schedule-title">{s.title}</div>
                    <div className="schedule-sub">
                      <Icon name="pin" size={12}/> {s.location}
                      <span className="dot-sep">·</span>
                      <span>{s.speaker}</span>
                    </div>
                  </div>
                  <Tag tone={tagToneForType(s.type)}>{s.type}</Tag>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card>
          <SectionLabel>Wallet</SectionLabel>
          <div className="wallet-mini">
            <div className="wallet-mini-balance">
              <span className="wallet-mini-currency">€</span>
              <span className="wallet-mini-amount">{state.wallet.balance.toFixed(2)}</span>
            </div>
            <p>Spend on food, drinks, and merch by tapping your badge at any kiosk.</p>
            <Button size="sm" variant="ghost" full onClick={() => actions.go("wallet")} iconRight="arrow-right">
              Top up or see history
            </Button>
          </div>
        </Card>

        <Card className="dash-stat-row">
          <Stat label="Sessions enrolled" value={state.enrollments.length} sub="out of 84 on offer"/>
          <Stat label="Wallet balance" value={`€${state.wallet.balance.toFixed(0)}`} sub="last top-up 8 May"/>
          {state.user?.role === "company-admin" && (
            <Stat label="Team members" value={state.invites.length + 1} sub={`${state.invites.filter(i => i.status === 'accepted').length} accepted`}/>
          )}
          <Stat label="Days until kick-off" value="4" sub="Tuesday 12 May, 09:30"/>
        </Card>

        <Card>
          <SectionLabel>Festival pulse</SectionLabel>
          <ul className="pulse-list">
            <li>
              <span className="pulse-dot pulse-ok"/>
              <span><strong>Sessions service</strong> is healthy. 84 sessions published.</span>
              <span className="pulse-time">2 min ago</span>
            </li>
            <li>
              <span className="pulse-dot pulse-warn"/>
              <span><strong>Workshop: XSD-first contracts</strong> is 97% full. Last seats.</span>
              <span className="pulse-time">14 min ago</span>
            </li>
            <li>
              <span className="pulse-dot pulse-ok"/>
              <span><strong>Identity service</strong> issued your master UUID.</span>
              <span className="pulse-time">2 days ago</span>
            </li>
            <li>
              <span className="pulse-dot pulse-ok"/>
              <span><strong>Welcome credit</strong> of €25 added to your wallet.</span>
              <span className="pulse-time">6 days ago</span>
            </li>
          </ul>
        </Card>
      </div>
    </div>
  );
}

function MiniBadge({ state }) {
  const u = state.user;
  return (
    <div className="mini-badge">
      <div className="mini-badge-strap"/>
      <div className="mini-badge-card">
        <div className="mini-badge-top">
          <span>SHIFT · 2026</span>
          <span>DAY 01</span>
        </div>
        <div className="mini-badge-name">{u?.firstName} {u?.lastName}</div>
        <div className="mini-badge-role">{u?.company || "Independent"} · Attendee</div>
        <QrCode value={u?.masterUuid || "u-anon"} size={96}/>
        <div className="mini-badge-id">{u?.badgeId}</div>
      </div>
    </div>
  );
}

function tagToneForType(t) {
  if (t === "keynote") return "primary";
  if (t === "workshop") return "accent";
  if (t === "reception") return "warm";
  return "neutral";
}

/* ──────────────────────────────────────────────────────────────
   BADGE (full QR page)
   ────────────────────────────────────────────────────────────── */

function BadgeScreen({ state, actions }) {
  const u = state.user;
  return (
    <div className="badge-page">
      <Card className="badge-page-card">
        <div className="badge-page-hd">
          <span className="eyebrow">Your festival badge</span>
          <h1>Scan to enter, check in, or pay.</h1>
          <p>This QR code identifies you across all twelve venues. The same scan works at the door, at the bar, and at any session check-in.</p>
        </div>

        <div className="badge-display">
          <div className="badge-display-strap"/>
          <div className="badge-display-card">
            <div className="badge-display-top">
              <span>SHIFT · 2026</span>
              <span>BDG · {u?.badgeId}</span>
            </div>
            <div className="badge-display-name">{u?.firstName} {u?.lastName}</div>
            <div className="badge-display-role">{u?.company || "Independent"}</div>
            <div className="badge-display-qr">
              <QrCode value={u?.masterUuid || "u-anon"} size={260}/>
            </div>
            <div className="badge-display-foot">
              <span>Master UUID · {u?.masterUuid}</span>
              <span>12 – 14 May · Desiderius Campus</span>
            </div>
          </div>
        </div>

        <div className="badge-actions">
          <div className="badge-action">
            <Icon name="wallet" size={16}/>
            <div>
              <strong>Wallet balance</strong>
              <span>€{state.wallet.balance.toFixed(2)} ready to spend</span>
            </div>
          </div>
          <div className="badge-action">
            <Icon name="shield" size={16}/>
            <div>
              <strong>Identity verified</strong>
              <span>Issued by the Identity service</span>
            </div>
          </div>
          <div className="badge-action">
            <Icon name="calendar" size={16}/>
            <div>
              <strong>{state.enrollments.length} sessions</strong>
              <span>Auto-checked-in on scan</span>
            </div>
          </div>
        </div>
      </Card>

      <Card className="badge-help">
        <SectionLabel>How to use it</SectionLabel>
        <ol className="how-list">
          <li><span>1</span> Hold your phone screen-up under the scanner. Brightness on max helps.</li>
          <li><span>2</span> Listen for the chime. Green = entry granted, amber = staff will assist.</li>
          <li><span>3</span> Lost your phone? Visit the help desk in the Atrium with photo ID.</li>
        </ol>
      </Card>
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────
   SESSIONS (browse + enrol)
   ────────────────────────────────────────────────────────────── */

function SessionsScreen({ state, actions }) {
  const [q, setQ] = useStateA("");
  const [filter, setFilter] = useStateA("all");

  const sessions = window.SHIFT_DATA.sessions;
  const filtered = sessions.filter((s) => {
    const matchesQ = !q || [s.title, s.speaker, s.location, s.blurb].some((x) => x.toLowerCase().includes(q.toLowerCase()));
    const matchesF = filter === "all" || s.type === filter;
    return matchesQ && matchesF;
  });

  return (
    <div className="sessions-page">
      <div className="sessions-head">
        <div>
          <h1>The programme</h1>
          <p>{sessions.length} sessions across three days. Filter, search, and enrol — drop sessions any time before the festival starts.</p>
        </div>
        <div className="sessions-tools">
          <div className="search">
            <Icon name="search" size={14}/>
            <input
              placeholder="Search talks, speakers, or rooms…"
              value={q}
              onChange={(e) => setQ(e.target.value)}
            />
          </div>
          <div className="chip-row">
            {["all","keynote","workshop","talk","panel","reception"].map((t) => (
              <button
                key={t}
                className={`chip ${filter === t ? "is-on" : ""}`}
                onClick={() => setFilter(t)}
              >{t}</button>
            ))}
          </div>
        </div>
      </div>

      {filtered.length === 0 ? (
        <EmptyState
          title="No sessions match that filter."
          body="Try a different keyword or clear the filters."
          action={<Button size="sm" onClick={() => { setQ(""); setFilter("all"); }}>Reset filters</Button>}
        />
      ) : (
        <div className="schedule-stack">
          {Object.entries(
            filtered.reduce((acc, s) => {
              const day = s.start.split("·")[0]?.trim() || "Unscheduled";
              acc[day] = acc[day] || [];
              acc[day].push(s);
              return acc;
            }, {})
          ).map(([day, items]) => (
            <section key={day} className="schedule-day">
              <h2 className="schedule-day-title">{day}</h2>
              <div className="session-list">
                {items.map((s) => (
                  <SessionCard
                    key={s.id}
                    session={s}
                    enrolled={state.enrollments.includes(s.id)}
                    onEnroll={() => actions.enroll(s.id)}
                    onDrop={() => actions.drop(s.id)}
                  />
                ))}
              </div>
            </section>
          ))}
        </div>
      )}
    </div>
  );
}

function SessionCard({ session, enrolled, onEnroll, onDrop }) {
  const full = session.enrolled >= session.capacity && !enrolled;
  return (
    <article className={`session-card ${enrolled ? "is-enrolled" : ""}`}>
      <div className="session-time-col">
        <span className="session-day">{session.start.split("·")[0]?.trim()}</span>
        <span className="session-hour">{session.start.split("·")[1]?.trim()}</span>
        <span className="session-dur">— {session.end}</span>
      </div>

      <div className="session-main">
        <div className="session-tags">
          <Tag tone={tagToneForType(session.type)}>{session.type}</Tag>
          {session.tags.slice(0,2).map((t,i) => <Tag key={i}>{t}</Tag>)}
          {enrolled && <Tag tone="ok">Enrolled</Tag>}
        </div>
        <h3>{session.title}</h3>
        <p>{session.blurb}</p>
        <div className="session-foot">
          <span><Icon name="users" size={12}/> {session.speaker}</span>
          <span><Icon name="pin" size={12}/> {session.location}</span>
        </div>
      </div>

      <div className="session-side">
        <CapacityBar enrolled={session.enrolled + (enrolled ? 1 : 0)} capacity={session.capacity}/>
        {enrolled ? (
          <Button variant="ghost" size="sm" onClick={onDrop} full icon="x">Drop</Button>
        ) : full ? (
          <Button variant="ghost" size="sm" disabled full>Waitlist full</Button>
        ) : (
          <Button size="sm" onClick={onEnroll} full icon="plus">Enrol</Button>
        )}
      </div>
    </article>
  );
}

/* ──────────────────────────────────────────────────────────────
   MY SESSIONS
   ────────────────────────────────────────────────────────────── */

function MySessionsScreen({ state, actions }) {
  const mine = state.enrollments
    .map((id) => window.SHIFT_DATA.sessions.find((s) => s.id === id))
    .filter(Boolean);

  const grouped = mine.reduce((acc, s) => {
    const day = s.start.split("·")[0]?.trim() || "Unscheduled";
    acc[day] = acc[day] || [];
    acc[day].push(s);
    return acc;
  }, {});

  return (
    <div className="my-sessions">
      <div className="my-sessions-head">
        <div>
          <h1>My schedule</h1>
          <p>The sessions you've enrolled in, grouped by day. Calendar invites are sent automatically and confirmed by the Planning service.</p>
        </div>
        <Button onClick={() => actions.go("sessions")} icon="plus">Add more sessions</Button>
      </div>

      {mine.length === 0 ? (
        <EmptyState
          title="No sessions on your schedule yet."
          body="Pick a few from the programme — you can always drop them later."
          action={<Button onClick={() => actions.go("sessions")}>Browse the programme</Button>}
        />
      ) : (
        <div className="schedule-stack">
          {Object.entries(grouped).map(([day, items]) => (
            <section key={day} className="schedule-day">
              <h2 className="schedule-day-title">{day}</h2>
              <div className="schedule-day-items">
                {items.map((s) => (
                  <div key={s.id} className="schedule-item">
                    <div className="schedule-item-time">
                      <strong>{s.start.split("·")[1]?.trim()}</strong>
                      <span>—</span>
                      <strong>{s.end}</strong>
                    </div>
                    <div className="schedule-item-main">
                      <div className="schedule-item-tags">
                        <Tag tone={tagToneForType(s.type)}>{s.type}</Tag>
                        <span className="schedule-item-confirm"><Icon name="check" size={11}/> Calendar invite confirmed</span>
                      </div>
                      <h3>{s.title}</h3>
                      <div className="schedule-item-sub">
                        <span><Icon name="pin" size={12}/> {s.location}</span>
                        <span><Icon name="users" size={12}/> {s.speaker}</span>
                      </div>
                    </div>
                    <Button variant="ghost" size="sm" onClick={() => actions.drop(s.id)}>Drop</Button>
                  </div>
                ))}
              </div>
            </section>
          ))}
        </div>
      )}
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────
   WALLET
   ────────────────────────────────────────────────────────────── */

function WalletScreen({ state, actions }) {
  const [amount, setAmount] = useStateA(25);
  const [method, setMethod] = useStateA("Bancontact");

  const topup = () => actions.walletTopup(Number(amount), method);

  return (
    <div className="wallet">
      <div className="wallet-grid">
        <Card className="wallet-balance-card">
          <SectionLabel>Festival wallet</SectionLabel>
          <div className="wallet-balance-big">
            <span className="wallet-currency">€</span>
            <span className="wallet-amount">{state.wallet.balance.toFixed(2)}</span>
          </div>
          <p className="wallet-tip">Tap your badge at any kiosk to spend. The balance updates within seconds.</p>
          <div className="wallet-quick">
            {[10, 25, 50, 100].map((a) => (
              <button
                key={a}
                className={`wallet-quick-btn ${Number(amount) === a ? "is-on" : ""}`}
                onClick={() => setAmount(a)}
              >€{a}</button>
            ))}
            <button className={`wallet-quick-btn wallet-quick-custom ${![10,25,50,100].includes(Number(amount)) ? "is-on" : ""}`}>
              <input
                type="number"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                min="1"
              />
            </button>
          </div>
          <Field label="Payment method">
            <Select value={method} onChange={setMethod}>
              <option>Bancontact</option>
              <option>iDEAL</option>
              <option>Credit card</option>
              <option>SEPA</option>
            </Select>
          </Field>
          <Button onClick={topup} full size="lg" icon="plus">Top up €{amount}</Button>
        </Card>

        <Card>
          <SectionLabel action={<span className="muted-small">{state.wallet.transactions.length} entries</span>}>
            Recent activity
          </SectionLabel>
          <ul className="wallet-tx">
            {state.wallet.transactions.map((t) => (
              <li key={t.id}>
                <div className="wallet-tx-icon" data-kind={t.kind}>
                  <Icon name={t.kind === "credit" ? "plus" : "wallet"} size={12}/>
                </div>
                <div className="wallet-tx-main">
                  <div className="wallet-tx-label">{t.label}</div>
                  <div className="wallet-tx-date">{t.date} · #{t.id}</div>
                </div>
                <div className={`wallet-tx-amount ${t.kind}`}>
                  {t.kind === "credit" ? "+" : "−"}€{Math.abs(t.amount).toFixed(2)}
                </div>
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────
   COMPANY INVITES
   ────────────────────────────────────────────────────────────── */

function CompanyScreen({ state, actions }) {
  const [email, setEmail] = useStateA("");
  const [err, setErr] = useStateA("");

  const send = (e) => {
    e.preventDefault();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
      setErr("Enter a valid email address");
      return;
    }
    setErr("");
    actions.invite(email);
    setEmail("");
  };

  const accepted = state.invites.filter((i) => i.status === "accepted").length;
  const pending = state.invites.filter((i) => i.status === "pending").length;

  return (
    <div className="company">
      <div className="company-head">
        <div>
          <span className="eyebrow">{state.user?.company}</span>
          <h1>Team & invites</h1>
          <p>Invite up to <strong>20 colleagues</strong> to join your company account. They'll register against your VAT number and be billed centrally.</p>
        </div>
        <div className="company-stats">
          <Stat label="Seats used" value={`${state.invites.length + 1} / 20`}/>
          <Stat label="Accepted" value={accepted}/>
          <Stat label="Pending" value={pending}/>
        </div>
      </div>

      <Card>
        <SectionLabel>Send a new invite</SectionLabel>
        <form onSubmit={send} className="invite-form">
          <Field label="Colleague's email address" error={err}>
            <Input value={email} onChange={setEmail} type="email" placeholder="colleague@studio-twelve.be"/>
          </Field>
          <Button type="submit" icon="mail">Send invite</Button>
        </form>
      </Card>

      <Card padding="none">
        <div className="invites-head">
          <SectionLabel>Invites ({state.invites.length})</SectionLabel>
        </div>
        <table className="invites-table">
          <thead>
            <tr>
              <th>Person</th>
              <th>Email</th>
              <th>Sent</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr className="invites-self">
              <td>
                <div className="invite-name">
                  <div className="invite-avatar">{initials(state.user)}</div>
                  <span>{state.user?.firstName} {state.user?.lastName} <em>(you)</em></span>
                </div>
              </td>
              <td>{state.user?.email}</td>
              <td>—</td>
              <td><Tag tone="primary">Owner</Tag></td>
              <td></td>
            </tr>
            {state.invites.map((inv, i) => (
              <tr key={inv.email}>
                <td>
                  <div className="invite-name">
                    <div className="invite-avatar invite-avatar-muted">
                      {inv.name ? inv.name.split(" ").map(w => w[0]).join("").slice(0,2) : "?"}
                    </div>
                    <span>{inv.name || <em className="muted">Not yet registered</em>}</span>
                  </div>
                </td>
                <td>{inv.email}</td>
                <td>{inv.sent}</td>
                <td>
                  {inv.status === "accepted"
                    ? <Tag tone="ok">Accepted</Tag>
                    : <Tag tone="warm">Pending</Tag>}
                </td>
                <td className="invites-actions">
                  {inv.status === "pending" && (
                    <button className="row-act" onClick={() => actions.resendInvite(i)}>Resend</button>
                  )}
                  <button className="row-act row-act-danger" onClick={() => actions.removeInvite(i)} title="Remove">
                    <Icon name="trash" size={13}/>
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────
   ADMIN: SESSION MANAGEMENT
   ────────────────────────────────────────────────────────────── */

function AdminSessionsScreen({ state, actions }) {
  const [creating, setCreating] = useStateA(false);
  const sessions = window.SHIFT_DATA.sessions;

  return (
    <div className="admin-sessions">
      <div className="admin-head">
        <div>
          <span className="eyebrow">Programme tools · Planning service</span>
          <h1>Manage sessions</h1>
          <p>Create, edit, or end sessions you host. Changes are pushed to the Planning service over RabbitMQ within seconds.</p>
        </div>
        <Button onClick={() => setCreating(true)} icon="plus">New session</Button>
      </div>

      {creating && <NewSessionForm onClose={() => setCreating(false)} actions={actions}/>}

      <Card padding="none">
        <div className="admin-table-head">
          <SectionLabel action={<span className="muted-small">{sessions.length} sessions live</span>}>
            Sessions you can manage
          </SectionLabel>
        </div>
        <table className="admin-table">
          <thead>
            <tr>
              <th>Session</th>
              <th>When</th>
              <th>Location</th>
              <th>Capacity</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {sessions.map((s) => (
              <tr key={s.id}>
                <td>
                  <div className="admin-title-cell">
                    <Tag tone={tagToneForType(s.type)}>{s.type}</Tag>
                    <div>
                      <div className="admin-title">{s.title}</div>
                      <div className="admin-sub">#{s.id} · {s.speaker}</div>
                    </div>
                  </div>
                </td>
                <td className="nowrap">{s.start}</td>
                <td>{s.location}</td>
                <td>
                  <div className="cap-cell">
                    <CapacityBar enrolled={s.enrolled} capacity={s.capacity}/>
                  </div>
                </td>
                <td>
                  {s.enrolled >= s.capacity
                    ? <Tag tone="hot">Full</Tag>
                    : s.enrolled / s.capacity > 0.9
                      ? <Tag tone="warm">Last seats</Tag>
                      : <Tag tone="ok">Open</Tag>}
                </td>
                <td className="invites-actions">
                  <button className="row-act"><Icon name="edit" size={13}/></button>
                  <button className="row-act row-act-danger"><Icon name="trash" size={13}/></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </div>
  );
}

function NewSessionForm({ onClose, actions }) {
  const [f, setF] = useStateA({
    title: "",
    type: "workshop",
    start: "",
    end: "",
    location: "",
    capacity: 30,
  });
  const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));

  const submit = (e) => {
    e.preventDefault();
    actions.toast({ tone: "ok", message: `Session "${f.title || "Untitled"}" published to Planning service.` });
    onClose();
  };

  return (
    <Card className="new-session-card">
      <div className="new-session-head">
        <SectionLabel>New session</SectionLabel>
        <button className="icon-btn" onClick={onClose}><Icon name="x" size={14}/></button>
      </div>
      <form onSubmit={submit} className="form-grid">
        <Field label="Session title" required>
          <Input value={f.title} onChange={set("title")} placeholder="e.g. Workshop: Identity-first registration flows"/>
        </Field>
        <div className="row-2">
          <Field label="Session type" required>
            <Select value={f.type} onChange={set("type")}>
              <option value="keynote">Keynote</option>
              <option value="workshop">Workshop</option>
              <option value="talk">Talk</option>
              <option value="panel">Panel</option>
              <option value="reception">Reception</option>
            </Select>
          </Field>
          <Field label="Maximum attendees">
            <Input type="number" value={f.capacity} onChange={set("capacity")}/>
          </Field>
        </div>
        <div className="row-2">
          <Field label="Start" required>
            <Input type="datetime-local" value={f.start} onChange={set("start")}/>
          </Field>
          <Field label="End" required>
            <Input type="datetime-local" value={f.end} onChange={set("end")}/>
          </Field>
        </div>
        <Field label="Location" help="Leave blank for online / TBD">
          <Input value={f.location} onChange={set("location")} placeholder="e.g. Lab Room 2"/>
        </Field>
        <div className="form-actions-row">
          <Button type="submit" iconRight="arrow-right">Publish to Planning</Button>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
        </div>
      </form>
    </Card>
  );
}

Object.assign(window, {
  AppShell, DashboardScreen, BadgeScreen, SessionsScreen, MySessionsScreen,
  WalletScreen, CompanyScreen, AdminSessionsScreen,
});
