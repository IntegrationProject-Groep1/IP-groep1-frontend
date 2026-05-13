// Shared UI primitives for the Shift Festival prototype.
// Plain components — no app state. Exported to window at the bottom.

const { useState, useEffect, useRef, useMemo } = React;

/* ──────────────────────────────────────────────────────────────
   ICONS (small inline set; minimal SVG, no decoration)
   ────────────────────────────────────────────────────────────── */

function Icon({ name, size = 18, stroke = 1.6, className = "" }) {
  const props = {
    width: size,
    height: size,
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: stroke,
    strokeLinecap: "round",
    strokeLinejoin: "round",
    className,
  };
  switch (name) {
    case "arrow-right":
      return <svg {...props}><path d="M5 12h14M13 6l6 6-6 6"/></svg>;
    case "arrow-left":
      return <svg {...props}><path d="M19 12H5M11 6l-6 6 6 6"/></svg>;
    case "qr":
      return <svg {...props}><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 14v3M14 19v2M17 21h4M19 17v0"/></svg>;
    case "calendar":
      return <svg {...props}><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>;
    case "users":
      return <svg {...props}><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>;
    case "wallet":
      return <svg {...props}><rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18M17 15h.01"/></svg>;
    case "settings":
      return <svg {...props}><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.03 1.56V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.56-1.03H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1.03-1.56V3a2 2 0 1 1 4 0v.09c0 .68.41 1.29 1.03 1.56a1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.27.62.88 1.03 1.56 1.03H21a2 2 0 1 1 0 4h-.09c-.68 0-1.29.41-1.51 1z"/></svg>;
    case "check":
      return <svg {...props}><path d="M20 6L9 17l-5-5"/></svg>;
    case "x":
      return <svg {...props}><path d="M18 6L6 18M6 6l12 12"/></svg>;
    case "plus":
      return <svg {...props}><path d="M12 5v14M5 12h14"/></svg>;
    case "mail":
      return <svg {...props}><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>;
    case "logout":
      return <svg {...props}><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>;
    case "home":
      return <svg {...props}><path d="M3 11l9-8 9 8M5 10v10h14V10"/></svg>;
    case "search":
      return <svg {...props}><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>;
    case "pin":
      return <svg {...props}><path d="M12 22s7-6.5 7-12a7 7 0 1 0-14 0c0 5.5 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/></svg>;
    case "clock":
      return <svg {...props}><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>;
    case "shield":
      return <svg {...props}><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>;
    case "edit":
      return <svg {...props}><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>;
    case "trash":
      return <svg {...props}><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>;
    case "spark":
      return <svg {...props}><path d="M12 2v6M12 16v6M2 12h6M16 12h6M4.93 4.93l4.24 4.24M14.83 14.83l4.24 4.24M4.93 19.07l4.24-4.24M14.83 9.17l4.24-4.24"/></svg>;
    default:
      return null;
  }
}

/* ──────────────────────────────────────────────────────────────
   BUTTON
   ────────────────────────────────────────────────────────────── */

function Button({ children, variant = "primary", size = "md", onClick, type = "button", disabled, full, icon, iconRight }) {
  const cls = `btn btn-${variant} btn-${size} ${full ? "btn-full" : ""}`;
  return (
    <button className={cls} onClick={onClick} type={type} disabled={disabled}>
      {icon && <Icon name={icon} size={16}/>}
      <span>{children}</span>
      {iconRight && <Icon name={iconRight} size={16}/>}
    </button>
  );
}

/* ──────────────────────────────────────────────────────────────
   FIELD (label + input + error + help)
   ────────────────────────────────────────────────────────────── */

function Field({ label, required, help, error, children }) {
  return (
    <label className={`field ${error ? "field-error" : ""}`}>
      <span className="field-label">
        {label}
        {required && <span className="field-required"> *</span>}
      </span>
      {children}
      {error && <span className="field-msg field-msg-error">{error}</span>}
      {help && !error && <span className="field-msg">{help}</span>}
    </label>
  );
}

function Input({ value, onChange, type = "text", placeholder, readOnly, autoComplete, name }) {
  return (
    <input
      className="input"
      type={type}
      value={value ?? ""}
      onChange={(e) => onChange?.(e.target.value)}
      placeholder={placeholder}
      readOnly={readOnly}
      autoComplete={autoComplete}
      name={name}
    />
  );
}

function Select({ value, onChange, children }) {
  return (
    <div className="select-wrap">
      <select className="input select" value={value ?? ""} onChange={(e) => onChange?.(e.target.value)}>
        {children}
      </select>
      <Icon name="arrow-right" size={14} className="select-caret"/>
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────
   CARD
   ────────────────────────────────────────────────────────────── */

function Card({ children, className = "", padding = "lg" }) {
  return <div className={`card card-pad-${padding} ${className}`}>{children}</div>;
}

function SectionLabel({ children, action }) {
  return (
    <div className="section-label-row">
      <span className="section-label">{children}</span>
      {action}
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────
   BADGE / TAG
   ────────────────────────────────────────────────────────────── */

function Tag({ children, tone = "neutral" }) {
  return <span className={`tag tag-${tone}`}>{children}</span>;
}

/* ──────────────────────────────────────────────────────────────
   QR CODE (deterministic, generated from a string)
   Renders an SVG grid based on a tiny hash — no library.
   Looks like a QR code, isn't scannable. That's fine for a prototype.
   ────────────────────────────────────────────────────────────── */

function QrCode({ value, size = 220 }) {
  const grid = useMemo(() => buildQrGrid(value, 29), [value]);
  const cell = size / grid.length;
  return (
    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="qr-svg" role="img" aria-label={`QR code for ${value}`}>
      <rect x="0" y="0" width={size} height={size} fill="#fff"/>
      {grid.map((row, y) => row.map((on, x) => on ? (
        <rect key={`${x}-${y}`} x={x*cell} y={y*cell} width={cell} height={cell} fill="#0c1135" rx={cell*0.15}/>
      ) : null))}
    </svg>
  );
}

function buildQrGrid(seed, n) {
  // Simple deterministic pseudo-random based on a string seed.
  let h = 2166136261;
  for (let i = 0; i < seed.length; i++) {
    h ^= seed.charCodeAt(i);
    h = Math.imul(h, 16777619) >>> 0;
  }
  const rand = () => {
    h ^= h << 13; h ^= h >>> 17; h ^= h << 5;
    return ((h >>> 0) % 1000) / 1000;
  };

  const grid = Array.from({ length: n }, () => Array(n).fill(0));

  // Fill body
  for (let y = 0; y < n; y++) {
    for (let x = 0; x < n; x++) {
      grid[y][x] = rand() > 0.52 ? 1 : 0;
    }
  }

  // Stamp position markers (3 corner squares like a real QR)
  const stamp = (cx, cy) => {
    for (let y = 0; y < 7; y++) for (let x = 0; x < 7; x++) {
      const isBorder = x === 0 || x === 6 || y === 0 || y === 6;
      const isInner = x >= 2 && x <= 4 && y >= 2 && y <= 4;
      grid[cy + y][cx + x] = isBorder || isInner ? 1 : 0;
    }
    // Quiet zone around it
    for (let y = -1; y <= 7; y++) for (let x = -1; x <= 7; x++) {
      if (x === -1 || x === 7 || y === -1 || y === 7) {
        const px = cx + x, py = cy + y;
        if (px >= 0 && py >= 0 && px < n && py < n) grid[py][px] = 0;
      }
    }
  };
  stamp(0, 0); stamp(n - 7, 0); stamp(0, n - 7);

  // Small alignment square bottom-right
  const ax = n - 9, ay = n - 9;
  for (let y = 0; y < 5; y++) for (let x = 0; x < 5; x++) {
    const isBorder = x === 0 || x === 4 || y === 0 || y === 4;
    const isCenter = x === 2 && y === 2;
    grid[ay + y][ax + x] = isBorder || isCenter ? 1 : 0;
  }

  return grid;
}

/* ──────────────────────────────────────────────────────────────
   PROGRESS BAR (used for session capacity)
   ────────────────────────────────────────────────────────────── */

function CapacityBar({ enrolled, capacity }) {
  const pct = Math.min(100, Math.round((enrolled / capacity) * 100));
  const tone = pct > 90 ? "hot" : pct > 70 ? "warm" : "cool";
  return (
    <div className="capacity">
      <div className="capacity-bar">
        <div className={`capacity-fill capacity-${tone}`} style={{ width: `${pct}%` }}/>
      </div>
      <div className="capacity-meta">
        <span>{enrolled} / {capacity}</span>
        <span className="capacity-pct">{pct}% full</span>
      </div>
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────
   TOAST
   ────────────────────────────────────────────────────────────── */

function Toast({ toast }) {
  if (!toast) return null;
  return (
    <div className={`toast toast-${toast.tone || "ok"}`}>
      <Icon name={toast.tone === "error" ? "x" : "check"} size={16}/>
      <span>{toast.message}</span>
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────
   EMPTY STATE
   ────────────────────────────────────────────────────────────── */

function EmptyState({ title, body, action }) {
  return (
    <div className="empty">
      <div className="empty-dot"/>
      <h3>{title}</h3>
      <p>{body}</p>
      {action}
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────
   STAT
   ────────────────────────────────────────────────────────────── */

function Stat({ label, value, sub }) {
  return (
    <div className="stat">
      <div className="stat-label">{label}</div>
      <div className="stat-value">{value}</div>
      {sub && <div className="stat-sub">{sub}</div>}
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────
   EXPORT
   ────────────────────────────────────────────────────────────── */
Object.assign(window, {
  Icon, Button, Field, Input, Select, Card, SectionLabel, Tag,
  QrCode, CapacityBar, Toast, EmptyState, Stat,
});
