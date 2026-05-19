// App root, router, state, tweaks integration.
const { useState: useStateApp, useEffect: useEffectApp, useMemo: useMemoApp } = React;

const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "accent": "indigo",
  "density": "comfy",
  "darkSidebar": true,
  "showProgramOnDash": true
}/*EDITMODE-END*/;

function applyAccent(name) {
  const map = {
    indigo:  { primary: "#1f3a8a", primaryDark: "#172a63", accent: "#0e7c66" },
    plum:    { primary: "#4f3a8a", primaryDark: "#382764", accent: "#0e7c66" },
    forest:  { primary: "#0e6e51", primaryDark: "#0a543d", accent: "#1f3a8a" },
    crimson: { primary: "#8a1f2c", primaryDark: "#681720", accent: "#1f3a8a" },
  };
  const c = map[name] || map.indigo;
  document.documentElement.style.setProperty("--primary", c.primary);
  document.documentElement.style.setProperty("--primary-dark", c.primaryDark);
  document.documentElement.style.setProperty("--accent", c.accent);
}

function App() {
  const [t, setT] = useTweaks(TWEAK_DEFAULTS);

  useEffectApp(() => { applyAccent(t.accent); }, [t.accent]);
  useEffectApp(() => {
    document.documentElement.dataset.density = t.density;
    document.documentElement.dataset.sidebar = t.darkSidebar ? "dark" : "light";
  }, [t.density, t.darkSidebar]);

  const [state, setState] = useStateApp({
    route: "landing",
    user: null,
    enrollments: window.SHIFT_DATA.initialEnrollments.slice(),
    invites: window.SHIFT_DATA.initialInvites.slice(),
    wallet: {
      balance: window.SHIFT_DATA.initialWallet.balance,
      transactions: window.SHIFT_DATA.initialWallet.transactions.slice(),
    },
    toast: null,
  });

  // Toast auto-dismiss.
  useEffectApp(() => {
    if (!state.toast) return;
    const id = setTimeout(() => setState((s) => ({ ...s, toast: null })), 3200);
    return () => clearTimeout(id);
  }, [state.toast]);

  // Scroll to top on route change.
  useEffectApp(() => {
    const el = document.querySelector(".shell-content") || document.querySelector(".app-root");
    if (el) el.scrollTop = 0;
  }, [state.route]);

  const actions = useMemoApp(() => ({
    go: (route) => setState((s) => ({ ...s, route })),
    toast: (toast) => setState((s) => ({ ...s, toast })),

    loginComplete: ({ email }) => {
      setState((s) => ({
        ...s,
        user: { ...window.SHIFT_DATA.seedUser, email: email || window.SHIFT_DATA.seedUser.email },
        route: "dashboard",
        toast: { tone: "ok", message: "Signed in. Welcome back to Shift." },
      }));
    },

    registerComplete: (form) => {
      const u = {
        firstName: form.firstName,
        lastName: form.lastName,
        email: form.email,
        company: form.isCompany ? form.companyName : null,
        role: form.isCompany ? "company-admin" : "attendee",
        masterUuid: "u-" + Math.random().toString(16).slice(2, 6) + "-" + Math.random().toString(16).slice(2, 6),
        badgeId: "BDG-" + Math.floor(10000 + Math.random() * 89999),
        vat: form.vat,
      };
      setState((s) => ({
        ...s,
        user: u,
        enrollments: [],
        route: "dashboard",
        toast: { tone: "ok", message: `Welcome, ${u.firstName}. Your digital badge is ready.` },
      }));
    },

    logout: () => setState((s) => ({ ...s, user: null, route: "landing", toast: { tone: "ok", message: "Signed out." } })),

    enroll: (id) => setState((s) => {
      if (s.enrollments.includes(id)) return s;
      const sess = window.SHIFT_DATA.sessions.find((x) => x.id === id);
      return {
        ...s,
        enrollments: [...s.enrollments, id],
        toast: { tone: "ok", message: `Enrolled in "${sess?.title}". Calendar invite on its way.` },
      };
    }),

    drop: (id) => setState((s) => {
      const sess = window.SHIFT_DATA.sessions.find((x) => x.id === id);
      return {
        ...s,
        enrollments: s.enrollments.filter((x) => x !== id),
        toast: { tone: "ok", message: `Dropped "${sess?.title}".` },
      };
    }),

    walletTopup: (amount, method) => setState((s) => {
      const tx = {
        id: "T-" + Math.floor(100 + Math.random() * 899),
        date: "Today",
        label: `Top-up · ${method}`,
        amount: amount,
        kind: "credit",
      };
      return {
        ...s,
        wallet: {
          balance: +(s.wallet.balance + amount).toFixed(2),
          transactions: [tx, ...s.wallet.transactions],
        },
        toast: { tone: "ok", message: `€${amount.toFixed(2)} added to your wallet.` },
      };
    }),

    invite: (email) => setState((s) => ({
      ...s,
      invites: [...s.invites, { email, sent: "Today", status: "pending", name: null }],
      toast: { tone: "ok", message: `Invite sent to ${email}.` },
    })),

    resendInvite: (idx) => setState((s) => ({
      ...s,
      toast: { tone: "ok", message: `Invite re-sent to ${s.invites[idx].email}.` },
    })),

    removeInvite: (idx) => setState((s) => ({
      ...s,
      invites: s.invites.filter((_, i) => i !== idx),
      toast: { tone: "ok", message: `Invite removed.` },
    })),
  }), []);

  const renderRoute = () => {
    if (!state.user) {
      if (state.route === "register") return <RegisterScreen state={state} actions={actions}/>;
      if (state.route === "login") return <LoginScreen state={state} actions={actions}/>;
      return <LandingScreen state={state} actions={actions}/>;
    }
    const r = state.route;
    let content;
    if (r === "badge") content = <BadgeScreen state={state} actions={actions}/>;
    else if (r === "sessions") content = <SessionsScreen state={state} actions={actions}/>;
    else if (r === "my-sessions") content = <MySessionsScreen state={state} actions={actions}/>;
    else if (r === "wallet") content = <WalletScreen state={state} actions={actions}/>;
    else if (r === "company") content = <CompanyScreen state={state} actions={actions}/>;
    else if (r === "admin-sessions") content = <AdminSessionsScreen state={state} actions={actions}/>;
    else content = <DashboardScreen state={state} actions={actions}/>;

    return (
      <AppShell state={state} actions={actions}>
        {content}
      </AppShell>
    );
  };

  return (
    <div className="app-root">
      {renderRoute()}
      <Toast toast={state.toast}/>

      <TweaksPanel title="Tweaks">
        <TweakSection title="Visual">
          <TweakRadio
            label="Accent"
            value={t.accent}
            onChange={(v) => setT("accent", v)}
            options={[
              { value: "indigo", label: "Indigo" },
              { value: "plum", label: "Plum" },
              { value: "forest", label: "Forest" },
              { value: "crimson", label: "Crimson" },
            ]}
          />
          <TweakRadio
            label="Density"
            value={t.density}
            onChange={(v) => setT("density", v)}
            options={[
              { value: "comfy", label: "Comfy" },
              { value: "compact", label: "Compact" },
            ]}
          />
          <TweakToggle
            label="Dark sidebar"
            value={t.darkSidebar}
            onChange={(v) => setT("darkSidebar", v)}
          />
        </TweakSection>
        <TweakSection title="Flow shortcuts">
          <TweakButton onClick={() => actions.go("landing")}>Go to landing</TweakButton>
          <TweakButton onClick={() => actions.loginComplete({ email: window.SHIFT_DATA.seedUser.email })}>Auto-login as Léa</TweakButton>
          <TweakButton onClick={() => actions.logout()}>Sign out</TweakButton>
        </TweakSection>
      </TweaksPanel>
    </div>
  );
}

ReactDOM.createRoot(document.getElementById("root")).render(<App/>);
