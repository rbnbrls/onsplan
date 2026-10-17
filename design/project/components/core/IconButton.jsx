import React from "react";

/**
 * onsplan IconButton — a square/circular button holding a single icon.
 * Mirrors the icon-well treatment (accent-subtle round wells, bordered
 * surface squares) used in the top nav and quick-action rows.
 */
export function IconButton({
  icon,
  variant = "subtle",
  shape = "circle",
  size = 40,
  disabled = false,
  ariaLabel,
  style = {},
  ...rest
}) {
  const variants = {
    subtle: { background: "var(--accent-subtle)", color: "var(--accent)", border: "1px solid transparent" },
    surface: { background: "var(--surface-card)", color: "var(--text-secondary)", border: "1px solid var(--border)" },
    solid: { background: "var(--accent)", color: "var(--text-inverse)", border: "1px solid var(--accent)" },
    ghost: { background: "transparent", color: "var(--text-secondary)", border: "1px solid transparent" },
  };
  const v = variants[variant] || variants.subtle;
  return (
    <button
      aria-label={ariaLabel}
      disabled={disabled}
      style={{
        display: "inline-flex",
        alignItems: "center",
        justifyContent: "center",
        width: size,
        height: size,
        borderRadius: shape === "circle" ? "var(--radius-full)" : "var(--radius-md)",
        cursor: disabled ? "not-allowed" : "pointer",
        opacity: disabled ? 0.5 : 1,
        transition: "background 0.15s ease, transform 0.05s ease",
        ...v,
        ...style,
      }}
      {...rest}
    >
      {icon}
    </button>
  );
}
