import React from "react";

/**
 * onsplan Badge — small status / count pill. Neutral, accent, warning,
 * error and outline variants. Used for task counts, assignees, statuses.
 */
export function Badge({
  children,
  variant = "subtle",
  dot = false,
  style = {},
  ...rest
}) {
  const variants = {
    subtle: { background: "var(--accent-subtle)", color: "var(--accent)", border: "1px solid transparent" },
    solid: { background: "var(--accent)", color: "var(--text-inverse)", border: "1px solid var(--accent)" },
    warning: { background: "var(--warning-subtle)", color: "#9A6B21", border: "1px solid transparent" },
    error: { background: "var(--error-subtle)", color: "var(--error)", border: "1px solid transparent" },
    neutral: { background: "var(--surface)", color: "var(--text-secondary)", border: "1px solid var(--border)" },
    outline: { background: "transparent", color: "var(--text-secondary)", border: "1px solid var(--border)" },
  };
  const dotColor = {
    subtle: "var(--accent)", solid: "var(--text-inverse)", warning: "var(--warning)",
    error: "var(--error)", neutral: "var(--text-muted)", outline: "var(--text-muted)",
  };
  const v = variants[variant] || variants.subtle;
  return (
    <span style={{
      display: "inline-flex", alignItems: "center", gap: 6,
      padding: "4px 10px", borderRadius: "var(--radius-full)",
      fontFamily: "var(--font-body)", fontSize: "12px", fontWeight: 600,
      lineHeight: 1.4, whiteSpace: "nowrap", ...v, ...style,
    }} {...rest}>
      {dot && <span style={{ width: 6, height: 6, borderRadius: "50%", background: dotColor[variant] }} />}
      {children}
    </span>
  );
}
