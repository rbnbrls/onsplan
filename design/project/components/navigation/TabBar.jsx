import React from "react";

/**
 * onsplan TabBar — the floating mobile bottom navigation. Translucent
 * white pill, soft shadow, pill-highlighted active tab (accent-subtle well
 * with accent icon+label). Items pass their own icon nodes (Lucide).
 */
export function TabBar({ items = [], activeId, onSelect, style = {} }) {
  return (
    <div style={{
      display: "flex", justifyContent: "center",
      padding: "0 16px 12px", ...style,
    }}>
      <div style={{
        display: "flex", alignItems: "center", justifyContent: "space-around",
        width: "100%", height: "var(--tabbar-height)",
        background: "rgba(255,255,255,0.72)",
        backdropFilter: "blur(16px)", WebkitBackdropFilter: "blur(16px)",
        borderRadius: "var(--radius-full)",
        boxShadow: "var(--shadow-md)", padding: "0 6px",
      }}>
        {items.map((it) => {
          const active = it.id === activeId;
          return (
            <button
              key={it.id}
              onClick={() => onSelect && onSelect(it.id)}
              style={{
                display: "flex", flexDirection: "column", alignItems: "center", gap: 4,
                padding: "6px 12px", border: "none", cursor: "pointer",
                background: active ? "var(--accent-subtle)" : "transparent",
                color: active ? "var(--accent)" : "var(--text-muted)",
                borderRadius: "var(--radius-full)",
                transition: "background 0.15s ease, color 0.15s ease",
              }}
            >
              <span style={{ display: "inline-flex", width: 22, height: 22 }}>{it.icon}</span>
              <span style={{ fontFamily: "var(--font-body)", fontSize: "10px", fontWeight: active ? 600 : 400 }}>
                {it.label}
              </span>
            </button>
          );
        })}
      </div>
    </div>
  );
}
