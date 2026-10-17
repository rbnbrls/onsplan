import React from "react";

/**
 * onsplan Checkbox — 22px rounded (radius-sm) box. Unchecked = bordered,
 * checked = accent fill with white tick. Matches the task-list rows.
 */
export function Checkbox({
  checked = false,
  onChange,
  disabled = false,
  size = 22,
  label = null,
  style = {},
  ...rest
}) {
  return (
    <label style={{
      display: "inline-flex", alignItems: "center", gap: 12,
      cursor: disabled ? "not-allowed" : "pointer", opacity: disabled ? 0.5 : 1, ...style,
    }}>
      <span
        role="checkbox"
        aria-checked={checked}
        onClick={() => !disabled && onChange && onChange(!checked)}
        style={{
          display: "inline-flex", alignItems: "center", justifyContent: "center",
          width: size, height: size, flex: "0 0 auto",
          borderRadius: "var(--radius-sm)",
          border: `1.5px solid ${checked ? "var(--accent)" : "var(--border)"}`,
          background: checked ? "var(--accent)" : "transparent",
          transition: "background 0.12s ease, border-color 0.12s ease",
        }}
        {...rest}
      >
        {checked && (
          <svg width={size * 0.64} height={size * 0.64} viewBox="0 0 24 24" fill="none"
            stroke="var(--text-inverse)" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
            <polyline points="20 6 9 17 4 12" />
          </svg>
        )}
      </span>
      {label && (
        <span style={{
          fontFamily: "var(--font-body)", fontSize: "15px",
          color: checked ? "var(--text-muted)" : "var(--text-primary)",
          textDecoration: checked ? "none" : "none",
        }}>{label}</span>
      )}
    </label>
  );
}
