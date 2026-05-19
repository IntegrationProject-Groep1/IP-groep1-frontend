// Screens for the Shift Festival prototype.
// All screens are pure components that receive {state, actions} from App.

const { useState: useStateS, useEffect: useEffectS, useMemo: useMemoS } = React;

/* ──────────────────────────────────────────────────────────────
   LANDING — public marketing-style entry
   ────────────────────────────────────────────────────────────── */

function LandingScreen({ actions, state }) {
  const ev = window.SHIFT_DATA.event;
  return (
    <div className="landing">
      <header className="landing-nav">
        <div className="brand">
          <BrandMark/>
          <span className="brand-name">Shift Festival</span>
        </div>
        <nav className="landing-nav-links">
          <a href="#program">Program</a>
          <a href="#venue">Venue</a>
          <a href="#faq">FAQ</a>
        </nav>
        <div className="landing-nav-cta">
          <Button variant="ghost" size="sm" onClick={() => actions.go("login")}>Log in</Button>
          <Button size="sm" onClick={() => actions.go("register")} iconRight="arrow-right">Get a badge</Button>
        </div>
      </header>

      <section className="hero">
        <div className="hero-grid">
          <div className="hero-lhs">
            <div className="hero-eyebrow">
              <span className="hero-dot"/> Registration is open · 4 days left
            </div>
            <h1 className="hero-title">
              <span className="hero-line">Three days.</span>
              <span className="hero-line">Twelve stages.</span>
              <span className="hero-line hero-line-accent">One integrated experience.</span>
            </h1>
            <p className="hero-sub">
              {ev.description}
            </p>
            <div className="hero-meta">
              <div><span className="hero-meta-k">When</span><span className="hero-meta-v">{ev.dates}</span></div>
              <div><span className="hero-meta-k">Where</span><span className="hero-meta-v">{ev.venue}</span></div>
              <div><span className="hero-meta-k">Tracks</span><span className="hero-meta-v">12 stages · 84 sessions</span></div>
            </div>
            <div className="hero-ctas">
              <Button size="lg" onClick={() => actions.go("register")} iconRight="arrow-right">
                Register for the festival
              </Button>
              <Button size="lg" variant="ghost" onClick={() => actions.go("login")}>
                I already have a badge
              </Button>
            </div>
          </div>
          <div className="hero-rhs">
            <BadgeMock/>
          </div>
        </div>
      </section>

      <section className="strip">
        <div className="strip-item"><span className="strip-num">2,400+</span><span className="strip-lbl">Attendees expected</span></div>
        <div className="strip-item"><span className="strip-num">84</span><span className="strip-lbl">Sessions across three days</span></div>
        <div className="strip-item"><span className="strip-num">12</span><span className="strip-lbl">Stages and labs on campus</span></div>
        <div className="strip-item"><span className="strip-num">37</span><span className="strip-lbl">Partner organisations</span></div>
      </section>

      <section className="program" id="program">
        <div className="section-head">
          <span className="section-eyebrow">Day one · Tuesday</span>
          <h2>A festival programme, not a conference agenda.</h2>
          <p>A small preview of what's on. Once you've registered you can enrol in any session that still has space.</p>
        </div>
        <div className="program-grid">
          {window.SHIFT_DATA.sessions.slice(0, 4).map((s) => (
            <article key={s.id} className="program-card">
              <div className="program-card-top">
                <Tag tone={s.type === "keynote" ? "primary" : s.type === "workshop" ? "accent" : "neutral"}>
                  {s.type}
                </Tag>
                <span className="program-card-time">{s.start}</span>
              </div>
              <h3>{s.title}</h3>
              <p>{s.blurb}</p>
              <div className="program-card-foot">
                <span>{s.speaker}</span>
                <span className="dot-sep">·</span>
                <span>{s.location}</span>
              </div>
            </article>
          ))}
        </div>
      </section>

      <footer className="landing-foot" id="venue">
        <div>
          <BrandMark/>
          <span className="brand-name">Shift Festival 2026</span>
        </div>
        <div className="landing-foot-meta">
          <span>{ev.venue}</span>
          <span className="dot-sep">·</span>
          <span>{ev.dates}</span>
          <span className="dot-sep">·</span>
          <span>An integration project by Desideriushogeschool</span>
        </div>
      </footer>
    </div>
  );
}

function BrandMark() {
  return (
    <span className="brand-mark" aria-hidden>
      <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
        <rect x="2" y="2" width="24" height="24" rx="7" fill="var(--ink)"/>
        <path d="M9 18.5L14 9.5L19 18.5" stroke="var(--bg)" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"/>
        <circle cx="14" cy="14" r="1.6" fill="var(--accent)"/>
      </svg>
    </span>
  );
}

function BadgeMock() {
  return (
    <div className="badge-mock">
      <div className="badge-strap"/>
      <div className="badge-clip"/>
      <div className="badge-card">
        <div className="badge-card-top">
          <span className="badge-event">SHIFT · 2026</span>
          <span className="badge-day">DAY 01</span>
        </div>
        <div className="badge-name">Léa Marchand</div>
        <div className="badge-role">Studio Twelve · Attendee</div>
        <div className="badge-qr">
          <QrCode value="u-7f3a-2c41-9b8e-018d" size={120}/>
        </div>
        <div className="badge-foot">
          <span>BDG-08412</span>
          <span>12 – 14 May · Desiderius Campus</span>
        </div>
      </div>
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────
   REGISTER
   ────────────────────────────────────────────────────────────── */

function RegisterScreen({ actions, state }) {
  const [form, setForm] = useStateS({
    firstName: "",
    lastName: "",
    email: "",
    password: "",
    passwordConfirm: "",
    dob: "",
    isCompany: false,
    companyName: "",
    vat: "",
  });
  const [errors, setErrors] = useStateS({});
  const [submitting, setSubmitting] = useStateS(false);

  const set = (k) => (v) => setForm((f) => ({ ...f, [k]: v }));

  const submit = (e) => {
    e.preventDefault();
    const errs = {};
    if (!form.firstName.trim()) errs.firstName = "Required";
    if (!form.lastName.trim()) errs.lastName = "Required";
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(form.email)) errs.email = "Enter a valid email";
    if (form.password.length < 8) errs.password = "Must be at least 8 characters";
    if (form.password !== form.passwordConfirm) errs.passwordConfirm = "Passwords do not match";
    if (!form.dob) errs.dob = "Required";
    if (form.isCompany) {
      if (!form.companyName.trim()) errs.companyName = "Required for companies";
      if (!form.vat.trim()) errs.vat = "Required for companies";
    }
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setSubmitting(true);
    setTimeout(() => actions.registerComplete(form), 700);
  };

  return (
    <div className="auth-wrap">
      <AuthSidebar
        title="Get your festival badge."
        sub="Two minutes. Once registered you'll receive a digital badge with a QR code — that's how you check in at the door, the bar, and any session you enrol in."
        bullets={[
          "Save your spot in 84 sessions",
          "Top-up a digital wallet for food, drinks and merch",
          "Invite up to 20 colleagues if you register as a company",
        ]}
      />
      <main className="auth-form-pane">
        <div className="auth-form-inner">
          <button className="link-back" onClick={() => actions.go("landing")}>
            <Icon name="arrow-left" size={14}/> Back
          </button>
          <h1>Create your account</h1>
          <p className="auth-form-sub">Already registered? <a onClick={() => actions.go("login")}>Log in instead</a>.</p>

          <form onSubmit={submit} className="form-grid">
            <div className="row-2">
              <Field label="First name" required error={errors.firstName}>
                <Input value={form.firstName} onChange={set("firstName")}/>
              </Field>
              <Field label="Last name" required error={errors.lastName}>
                <Input value={form.lastName} onChange={set("lastName")}/>
              </Field>
            </div>
            <Field label="Email address" required error={errors.email}>
              <Input type="email" value={form.email} onChange={set("email")}/>
            </Field>
            <div className="row-2">
              <Field label="Password" required error={errors.password} help="At least 8 characters">
                <Input type="password" value={form.password} onChange={set("password")} autoComplete="new-password"/>
              </Field>
              <Field label="Confirm password" required error={errors.passwordConfirm}>
                <Input type="password" value={form.passwordConfirm} onChange={set("passwordConfirm")} autoComplete="new-password"/>
              </Field>
            </div>
            <Field label="Date of birth" required error={errors.dob}>
              <Input type="date" value={form.dob} onChange={set("dob")}/>
            </Field>

            <label className="check-row">
              <input type="checkbox" checked={form.isCompany} onChange={(e) => set("isCompany")(e.target.checked)}/>
              <span>
                <strong>I'm registering as a company</strong>
                <em>Unlocks the team invites dashboard and group billing.</em>
              </span>
            </label>

            {form.isCompany && (
              <fieldset className="reveal">
                <legend>Company details</legend>
                <div className="row-2">
                  <Field label="Company name" required error={errors.companyName}>
                    <Input value={form.companyName} onChange={set("companyName")}/>
                  </Field>
                  <Field label="VAT number" required error={errors.vat} help="Format: BE0XXX.XXX.XXX">
                    <Input value={form.vat} onChange={set("vat")} placeholder="BE0784.512.901"/>
                  </Field>
                </div>
              </fieldset>
            )}

            <div className="form-actions-row">
              <Button type="submit" size="lg" disabled={submitting} iconRight={submitting ? null : "arrow-right"}>
                {submitting ? "Creating your badge…" : "Register"}
              </Button>
              <span className="form-fine">
                By registering you agree to the festival code of conduct and our data policy.
              </span>
            </div>
          </form>
        </div>
      </main>
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────
   LOGIN
   ────────────────────────────────────────────────────────────── */

function LoginScreen({ actions, state }) {
  const [email, setEmail] = useStateS("lea.marchand@studio-twelve.be");
  const [password, setPassword] = useStateS("festival2026");
  const [err, setErr] = useStateS("");
  const [busy, setBusy] = useStateS(false);

  const submit = (e) => {
    e.preventDefault();
    if (!email || password.length < 4) {
      setErr("Please enter a valid email and password.");
      return;
    }
    setErr("");
    setBusy(true);
    setTimeout(() => actions.loginComplete({ email }), 500);
  };

  return (
    <div className="auth-wrap">
      <AuthSidebar
        title="Welcome back."
        sub="Sign in to scan your badge, browse the programme, and manage your sessions."
        bullets={[
          "Your QR badge is always one tap away",
          "Enrol or drop sessions in real time",
          "Wallet balance synced live with the kassa",
        ]}
      />
      <main className="auth-form-pane">
        <div className="auth-form-inner">
          <button className="link-back" onClick={() => actions.go("landing")}>
            <Icon name="arrow-left" size={14}/> Back
          </button>
          <h1>Log in</h1>
          <p className="auth-form-sub">New here? <a onClick={() => actions.go("register")}>Create an account</a>.</p>

          {err && <div className="alert alert-error">{err}</div>}

          <form onSubmit={submit} className="form-grid">
            <Field label="Email address" required>
              <Input type="email" value={email} onChange={setEmail}/>
            </Field>
            <Field label="Password" required>
              <Input type="password" value={password} onChange={setPassword}/>
            </Field>
            <div className="login-extras">
              <label className="check-row check-row-inline">
                <input type="checkbox" defaultChecked/>
                <span>Keep me signed in</span>
              </label>
              <a className="muted-link">Forgot password?</a>
            </div>
            <Button type="submit" size="lg" disabled={busy} full iconRight={busy ? null : "arrow-right"}>
              {busy ? "Signing in…" : "Continue"}
            </Button>

            <div className="demo-hint">
              <Icon name="spark" size={14}/>
              <span>Demo mode: any password (4+ chars) works. The seeded account loads as a company admin.</span>
            </div>
          </form>
        </div>
      </main>
    </div>
  );
}

function AuthSidebar({ title, sub, bullets }) {
  return (
    <aside className="auth-side">
      <div className="auth-side-top">
        <div className="brand">
          <BrandMark/>
          <span className="brand-name">Shift Festival</span>
        </div>
      </div>
      <div className="auth-side-body">
        <h2>{title}</h2>
        <p>{sub}</p>
        <ul>
          {bullets.map((b, i) => (
            <li key={i}><Icon name="check" size={14}/> <span>{b}</span></li>
          ))}
        </ul>
      </div>
      <div className="auth-side-foot">
        <BadgeMockMini/>
      </div>
    </aside>
  );
}

function BadgeMockMini() {
  return (
    <div className="badge-mini">
      <div className="badge-mini-top">
        <span>SHIFT · 2026</span>
        <span>12 – 14 MAY</span>
      </div>
      <div className="badge-mini-body">
        <QrCode value="u-7f3a-2c41-9b8e-018d" size={64}/>
        <div>
          <div className="badge-mini-name">Léa Marchand</div>
          <div className="badge-mini-role">Studio Twelve</div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, {
  LandingScreen, RegisterScreen, LoginScreen, BrandMark, BadgeMock,
});
