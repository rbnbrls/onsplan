import React from "react";
import { Avatar } from "../core/Avatar.jsx";

/**
 * onsplan TopNav.
 * Source: component/TopNav — white bar, 64px, bottom border. Brand mark +
 * wordmark (Playfair) on the left with nav links; family switcher + user
 * trigger on the right.
 */
export function TopNav({
  brand = "onsplan",
  mark = null,
  links = [],
  activeLink,
  onNavigate,
  familyName = null,
  user = null,
  right = null,
  style = {},
}) {
  return (
    <header style={{
      display: "flex", alignItems: "center", justifyContent: "space-between",
      height: "var(--nav-height)", padding: "0 32px",
      background: "var(--surface-card)", borderBottom: "1px solid var(--border)",
      ...style,
    }}>
      <div style={{ display: "flex", alignItems: "center", gap: 40 }}>
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          {mark && <span style={{ display: "inline-flex", color: "var(--accent)" }}>{mark}</span>}
          <span style={{ fontFamily: "var(--font-heading)", fontSize: 20, fontWeight: 600, color: "var(--text-primary)" }}>
            {brand}
          </span>
        </div>
        <nav style={{ display: "flex", alignItems: "center", gap: 28 }}>
          {links.map((l) => {
            const active = l.id === activeLink;
            return (
              <button key={l.id} onClick={() => onNavigate && onNavigate(l.id)}
                style={{
                  border: "none", background: "transparent", cursor: "pointer",
                  fontFamily: "var(--font-body)", fontSize: 15,
                  fontWeight: active ? 600 : 500,
                  color: active ? "var(--accent)" : "var(--text-muted)",
                  padding: 0,
                }}>
                {l.label}
              </button>
            );
          })}
        </nav>
      </div>
      <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
        {right}
        {familyName && (
          <div style={{
            display: "flex", alignItems: "center", gap: 8,
            background: "var(--accent-subtle)", border: "1px solid var(--accent-light)",
            borderRadius: "var(--radius-full)", padding: "8px 14px",
            color: "var(--accent)", fontFamily: "var(--font-body)", fontSize: 14, fontWeight: 600,
          }}>
            <span aria-hidden style={{ width: 8, height: 8, borderRadius: "50%", background: "var(--accent)" }} />
            {familyName}
            <span style={{ fontSize: 11 }}>▾</span>
          </div>
        )}
        {user && (
          <div style={{
            display: "flex", alignItems: "center", gap: 6,
            background: "var(--surface)", border: "1px solid var(--border)",
            borderRadius: "var(--radius-full)", padding: "4px 8px 4px 4px",
          }}>
            <Avatar initial={user.initials} tint={user.tint || "accent"} size={32} />
            <span style={{ fontSize: 11, color: "var(--text-muted)" }}>▾</span>
          </div>
        )}
      </div>
    </header>
  );
}
