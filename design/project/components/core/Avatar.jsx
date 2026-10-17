import React from "react";

/** The soft pastel avatar tints used for family members in the source. */
export const AVATAR_TINTS = {
  sage: { bg: "#C8DBBC", fg: "#1B3A28" },
  blue: { bg: "#B8C9D4", fg: "#1B3A28" },
  clay: { bg: "#E6D4C8", fg: "#1B3A28" },
  lilac: { bg: "#D4C8E6", fg: "#1B3A28" },
  accent: { bg: "var(--accent)", fg: "var(--text-inverse)" },
  subtle: { bg: "var(--accent-subtle)", fg: "var(--accent)" },
};

/**
 * onsplan Avatar — circular, initial-based (Playfair) or image.
 * Tints match the family-member swatches from the source screens.
 */
export function Avatar({
  initial,
  src = null,
  tint = "sage",
  size = 44,
  style = {},
  ...rest
}) {
  const t = AVATAR_TINTS[tint] || AVATAR_TINTS.sage;
  return (
    <span
      style={{
        display: "inline-flex", alignItems: "center", justifyContent: "center",
        width: size, height: size, flex: "0 0 auto",
        borderRadius: "var(--radius-full)", overflow: "hidden",
        background: t.bg, color: t.fg,
        fontFamily: "var(--font-heading)", fontWeight: 500,
        fontSize: Math.round(size * 0.4),
        ...style,
      }}
      {...rest}
    >
      {src ? (
        <img src={src} alt={initial || ""} style={{ width: "100%", height: "100%", objectFit: "cover" }} />
      ) : (
        initial
      )}
    </span>
  );
}
