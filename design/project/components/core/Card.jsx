import React from "react";

/**
 * onsplan Card — the white surface panel used everywhere (task rows,
 * stat cards, list panels). Soft border, radius-md/lg, optional shadow.
 */
export function Card({
  children,
  elevated = false,
  padding = 18,
  radius = "md",
  accentBar = null,
  style = {},
  ...rest
}) {
  const radii = { md: "var(--radius-md)", lg: "var(--radius-lg)" };
  const body = (
    <div style={{
      background: "var(--surface-card)",
      border: "1px solid var(--border)",
      borderRadius: radii[radius] || radii.md,
      boxShadow: elevated ? "var(--shadow-sm)" : "none",
      padding,
      ...style,
    }} {...rest}>
      {children}
    </div>
  );
  if (!accentBar) return body;
  // Left accent bar variant (schedule / event rows)
  return (
    <div style={{
      display: "flex", alignItems: "stretch", gap: 12,
      background: "var(--surface-card)",
      border: "1px solid var(--border)",
      borderRadius: radii[radius] || radii.md,
      boxShadow: elevated ? "var(--shadow-sm)" : "none",
      padding,
      ...style,
    }} {...rest}>
      <div style={{ width: 3, borderRadius: "var(--radius-full)", background: accentBar, flex: "0 0 auto" }} />
      <div style={{ flex: 1, minWidth: 0 }}>{children}</div>
    </div>
  );
}
