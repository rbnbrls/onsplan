import React from "react";

/**
 * onsplan Button.
 * Source: component/PrimaryButton (accent fill, radius-md, 15/20 padding,
 * Inter 15/600). Secondary + ghost variants derive from the "Invite" and
 * nav-link treatments seen across the web screens.
 */
export function Button({
  children,
  variant = "primary",
  size = "md",
  fullWidth = false,
  disabled = false,
  leadingIcon = null,
  trailingIcon = null,
  style = {},
  ...rest
}) {
  const sizes = {
    sm: { padding: "10px 16px", fontSize: "14px", gap: "8px" },
    md: { padding: "15px 20px", fontSize: "15px", gap: "8px" },
    lg: { padding: "17px 26px", fontSize: "16px", gap: "10px" },
  };
  const variants = {
    primary: {
      background: "var(--accent)",
      color: "var(--text-inverse)",
      border: "1px solid var(--accent)",
    },
    secondary: {
      background: "var(--surface-card)",
      color: "var(--text-primary)",
      border: "1px solid var(--border)",
    },
    ghost: {
      background: "transparent",
      color: "var(--text-secondary)",
      border: "1px solid transparent",
    },
    danger: {
      background: "var(--error)",
      color: "var(--text-inverse)",
      border: "1px solid var(--error)",
    },
  };
  const s = sizes[size] || sizes.md;
  const v = variants[variant] || variants.primary;
  return (
    <button
      disabled={disabled}
      style={{
        display: "inline-flex",
        alignItems: "center",
        justifyContent: "center",
        gap: s.gap,
        padding: s.padding,
        width: fullWidth ? "100%" : "auto",
        fontFamily: "var(--font-body)",
        fontSize: s.fontSize,
        fontWeight: 600,
        lineHeight: 1,
        borderRadius: "var(--radius-md)",
        cursor: disabled ? "not-allowed" : "pointer",
        opacity: disabled ? 0.5 : 1,
        transition: "background 0.15s ease, opacity 0.15s ease, transform 0.05s ease",
        ...v,
        ...style,
      }}
      onMouseDown={(e) => { if (!disabled) e.currentTarget.style.transform = "scale(0.98)"; }}
      onMouseUp={(e) => { e.currentTarget.style.transform = "scale(1)"; }}
      onMouseLeave={(e) => { e.currentTarget.style.transform = "scale(1)"; }}
      {...rest}
    >
      {leadingIcon}
      {children && <span>{children}</span>}
      {trailingIcon}
    </button>
  );
}
