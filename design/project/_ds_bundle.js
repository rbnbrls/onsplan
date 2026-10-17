/* @ds-bundle: {"format":4,"namespace":"OnsplanDesignSystem_c7911f","components":[{"name":"AVATAR_TINTS","sourcePath":"components/core/Avatar.jsx"},{"name":"Avatar","sourcePath":"components/core/Avatar.jsx"},{"name":"Badge","sourcePath":"components/core/Badge.jsx"},{"name":"Button","sourcePath":"components/core/Button.jsx"},{"name":"Card","sourcePath":"components/core/Card.jsx"},{"name":"Checkbox","sourcePath":"components/core/Checkbox.jsx"},{"name":"IconButton","sourcePath":"components/core/IconButton.jsx"},{"name":"Input","sourcePath":"components/core/Input.jsx"},{"name":"TabBar","sourcePath":"components/navigation/TabBar.jsx"},{"name":"TopNav","sourcePath":"components/navigation/TopNav.jsx"}],"sourceHashes":{"components/core/Avatar.jsx":"0415c1418c60","components/core/Badge.jsx":"60c29210c931","components/core/Button.jsx":"91e070dde356","components/core/Card.jsx":"125a54a965fe","components/core/Checkbox.jsx":"d9b5272f8cee","components/core/IconButton.jsx":"b064a3a25241","components/core/Input.jsx":"a3bbddcd94ad","components/navigation/TabBar.jsx":"7c37040f01e4","components/navigation/TopNav.jsx":"11a9ac193bd6","ui_kits/_shared/icons.jsx":"fb3cb404f653","ui_kits/web-app/WebDashboard.jsx":"7902fa762c46","ui_kits/web-app/WebLogin.jsx":"70f38ed91718","ui_kits/web-app/WebTodos.jsx":"65214d695b28"},"inlinedExternals":[],"unexposedExports":[]} */

(() => {

const __ds_ns = (window.OnsplanDesignSystem_c7911f = window.OnsplanDesignSystem_c7911f || {});

const __ds_scope = {};

(__ds_ns.__errors = __ds_ns.__errors || []);

// components/core/Avatar.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/** The soft pastel avatar tints used for family members in the source. */
const AVATAR_TINTS = {
  sage: {
    bg: "#C8DBBC",
    fg: "#1B3A28"
  },
  blue: {
    bg: "#B8C9D4",
    fg: "#1B3A28"
  },
  clay: {
    bg: "#E6D4C8",
    fg: "#1B3A28"
  },
  lilac: {
    bg: "#D4C8E6",
    fg: "#1B3A28"
  },
  accent: {
    bg: "var(--accent)",
    fg: "var(--text-inverse)"
  },
  subtle: {
    bg: "var(--accent-subtle)",
    fg: "var(--accent)"
  }
};

/**
 * onsplan Avatar — circular, initial-based (Playfair) or image.
 * Tints match the family-member swatches from the source screens.
 */
function Avatar({
  initial,
  src = null,
  tint = "sage",
  size = 44,
  style = {},
  ...rest
}) {
  const t = AVATAR_TINTS[tint] || AVATAR_TINTS.sage;
  return /*#__PURE__*/React.createElement("span", _extends({
    style: {
      display: "inline-flex",
      alignItems: "center",
      justifyContent: "center",
      width: size,
      height: size,
      flex: "0 0 auto",
      borderRadius: "var(--radius-full)",
      overflow: "hidden",
      background: t.bg,
      color: t.fg,
      fontFamily: "var(--font-heading)",
      fontWeight: 500,
      fontSize: Math.round(size * 0.4),
      ...style
    }
  }, rest), src ? /*#__PURE__*/React.createElement("img", {
    src: src,
    alt: initial || "",
    style: {
      width: "100%",
      height: "100%",
      objectFit: "cover"
    }
  }) : initial);
}
Object.assign(__ds_scope, { AVATAR_TINTS, Avatar });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Avatar.jsx", error: String((e && e.message) || e) }); }

// components/core/Badge.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * onsplan Badge — small status / count pill. Neutral, accent, warning,
 * error and outline variants. Used for task counts, assignees, statuses.
 */
function Badge({
  children,
  variant = "subtle",
  dot = false,
  style = {},
  ...rest
}) {
  const variants = {
    subtle: {
      background: "var(--accent-subtle)",
      color: "var(--accent)",
      border: "1px solid transparent"
    },
    solid: {
      background: "var(--accent)",
      color: "var(--text-inverse)",
      border: "1px solid var(--accent)"
    },
    warning: {
      background: "var(--warning-subtle)",
      color: "#9A6B21",
      border: "1px solid transparent"
    },
    error: {
      background: "var(--error-subtle)",
      color: "var(--error)",
      border: "1px solid transparent"
    },
    neutral: {
      background: "var(--surface)",
      color: "var(--text-secondary)",
      border: "1px solid var(--border)"
    },
    outline: {
      background: "transparent",
      color: "var(--text-secondary)",
      border: "1px solid var(--border)"
    }
  };
  const dotColor = {
    subtle: "var(--accent)",
    solid: "var(--text-inverse)",
    warning: "var(--warning)",
    error: "var(--error)",
    neutral: "var(--text-muted)",
    outline: "var(--text-muted)"
  };
  const v = variants[variant] || variants.subtle;
  return /*#__PURE__*/React.createElement("span", _extends({
    style: {
      display: "inline-flex",
      alignItems: "center",
      gap: 6,
      padding: "4px 10px",
      borderRadius: "var(--radius-full)",
      fontFamily: "var(--font-body)",
      fontSize: "12px",
      fontWeight: 600,
      lineHeight: 1.4,
      whiteSpace: "nowrap",
      ...v,
      ...style
    }
  }, rest), dot && /*#__PURE__*/React.createElement("span", {
    style: {
      width: 6,
      height: 6,
      borderRadius: "50%",
      background: dotColor[variant]
    }
  }), children);
}
Object.assign(__ds_scope, { Badge });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Badge.jsx", error: String((e && e.message) || e) }); }

// components/core/Button.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * onsplan Button.
 * Source: component/PrimaryButton (accent fill, radius-md, 15/20 padding,
 * Inter 15/600). Secondary + ghost variants derive from the "Invite" and
 * nav-link treatments seen across the web screens.
 */
function Button({
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
    sm: {
      padding: "10px 16px",
      fontSize: "14px",
      gap: "8px"
    },
    md: {
      padding: "15px 20px",
      fontSize: "15px",
      gap: "8px"
    },
    lg: {
      padding: "17px 26px",
      fontSize: "16px",
      gap: "10px"
    }
  };
  const variants = {
    primary: {
      background: "var(--accent)",
      color: "var(--text-inverse)",
      border: "1px solid var(--accent)"
    },
    secondary: {
      background: "var(--surface-card)",
      color: "var(--text-primary)",
      border: "1px solid var(--border)"
    },
    ghost: {
      background: "transparent",
      color: "var(--text-secondary)",
      border: "1px solid transparent"
    },
    danger: {
      background: "var(--error)",
      color: "var(--text-inverse)",
      border: "1px solid var(--error)"
    }
  };
  const s = sizes[size] || sizes.md;
  const v = variants[variant] || variants.primary;
  return /*#__PURE__*/React.createElement("button", _extends({
    disabled: disabled,
    style: {
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
      ...style
    },
    onMouseDown: e => {
      if (!disabled) e.currentTarget.style.transform = "scale(0.98)";
    },
    onMouseUp: e => {
      e.currentTarget.style.transform = "scale(1)";
    },
    onMouseLeave: e => {
      e.currentTarget.style.transform = "scale(1)";
    }
  }, rest), leadingIcon, children && /*#__PURE__*/React.createElement("span", null, children), trailingIcon);
}
Object.assign(__ds_scope, { Button });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Button.jsx", error: String((e && e.message) || e) }); }

// components/core/Card.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * onsplan Card — the white surface panel used everywhere (task rows,
 * stat cards, list panels). Soft border, radius-md/lg, optional shadow.
 */
function Card({
  children,
  elevated = false,
  padding = 18,
  radius = "md",
  accentBar = null,
  style = {},
  ...rest
}) {
  const radii = {
    md: "var(--radius-md)",
    lg: "var(--radius-lg)"
  };
  const body = /*#__PURE__*/React.createElement("div", _extends({
    style: {
      background: "var(--surface-card)",
      border: "1px solid var(--border)",
      borderRadius: radii[radius] || radii.md,
      boxShadow: elevated ? "var(--shadow-sm)" : "none",
      padding,
      ...style
    }
  }, rest), children);
  if (!accentBar) return body;
  // Left accent bar variant (schedule / event rows)
  return /*#__PURE__*/React.createElement("div", _extends({
    style: {
      display: "flex",
      alignItems: "stretch",
      gap: 12,
      background: "var(--surface-card)",
      border: "1px solid var(--border)",
      borderRadius: radii[radius] || radii.md,
      boxShadow: elevated ? "var(--shadow-sm)" : "none",
      padding,
      ...style
    }
  }, rest), /*#__PURE__*/React.createElement("div", {
    style: {
      width: 3,
      borderRadius: "var(--radius-full)",
      background: accentBar,
      flex: "0 0 auto"
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      minWidth: 0
    }
  }, children));
}
Object.assign(__ds_scope, { Card });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Card.jsx", error: String((e && e.message) || e) }); }

// components/core/Checkbox.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * onsplan Checkbox — 22px rounded (radius-sm) box. Unchecked = bordered,
 * checked = accent fill with white tick. Matches the task-list rows.
 */
function Checkbox({
  checked = false,
  onChange,
  disabled = false,
  size = 22,
  label = null,
  style = {},
  ...rest
}) {
  return /*#__PURE__*/React.createElement("label", {
    style: {
      display: "inline-flex",
      alignItems: "center",
      gap: 12,
      cursor: disabled ? "not-allowed" : "pointer",
      opacity: disabled ? 0.5 : 1,
      ...style
    }
  }, /*#__PURE__*/React.createElement("span", _extends({
    role: "checkbox",
    "aria-checked": checked,
    onClick: () => !disabled && onChange && onChange(!checked),
    style: {
      display: "inline-flex",
      alignItems: "center",
      justifyContent: "center",
      width: size,
      height: size,
      flex: "0 0 auto",
      borderRadius: "var(--radius-sm)",
      border: `1.5px solid ${checked ? "var(--accent)" : "var(--border)"}`,
      background: checked ? "var(--accent)" : "transparent",
      transition: "background 0.12s ease, border-color 0.12s ease"
    }
  }, rest), checked && /*#__PURE__*/React.createElement("svg", {
    width: size * 0.64,
    height: size * 0.64,
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "var(--text-inverse)",
    strokeWidth: "3",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, /*#__PURE__*/React.createElement("polyline", {
    points: "20 6 9 17 4 12"
  }))), label && /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: "var(--font-body)",
      fontSize: "15px",
      color: checked ? "var(--text-muted)" : "var(--text-primary)",
      textDecoration: checked ? "none" : "none"
    }
  }, label));
}
Object.assign(__ds_scope, { Checkbox });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Checkbox.jsx", error: String((e && e.message) || e) }); }

// components/core/IconButton.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * onsplan IconButton — a square/circular button holding a single icon.
 * Mirrors the icon-well treatment (accent-subtle round wells, bordered
 * surface squares) used in the top nav and quick-action rows.
 */
function IconButton({
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
    subtle: {
      background: "var(--accent-subtle)",
      color: "var(--accent)",
      border: "1px solid transparent"
    },
    surface: {
      background: "var(--surface-card)",
      color: "var(--text-secondary)",
      border: "1px solid var(--border)"
    },
    solid: {
      background: "var(--accent)",
      color: "var(--text-inverse)",
      border: "1px solid var(--accent)"
    },
    ghost: {
      background: "transparent",
      color: "var(--text-secondary)",
      border: "1px solid transparent"
    }
  };
  const v = variants[variant] || variants.subtle;
  return /*#__PURE__*/React.createElement("button", _extends({
    "aria-label": ariaLabel,
    disabled: disabled,
    style: {
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
      ...style
    }
  }, rest), icon);
}
Object.assign(__ds_scope, { IconButton });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/IconButton.jsx", error: String((e && e.message) || e) }); }

// components/core/Input.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * onsplan InputField.
 * Source: component/InputField — label (Inter 14/500) + white box with
 * 1px border, radius-md, 14/16 padding, muted placeholder, optional helper
 * (error state turns border + helper red).
 */
function Input({
  label,
  placeholder = "",
  value,
  defaultValue,
  onChange,
  helper,
  error = false,
  disabled = false,
  type = "text",
  leadingIcon = null,
  id,
  style = {},
  ...rest
}) {
  const [focused, setFocused] = React.useState(false);
  const borderColor = error ? "var(--error)" : focused ? "var(--accent)" : "var(--border)";
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      flexDirection: "column",
      gap: "8px",
      width: "100%",
      ...style
    }
  }, label && /*#__PURE__*/React.createElement("label", {
    htmlFor: id,
    style: {
      fontFamily: "var(--font-body)",
      fontSize: "14px",
      fontWeight: 500,
      color: "var(--text-primary)"
    }
  }, label), /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      alignItems: "center",
      gap: "10px",
      background: disabled ? "var(--surface)" : "var(--surface-card)",
      border: `1px solid ${borderColor}`,
      boxShadow: focused && !error ? "0 0 0 3px var(--accent-subtle)" : "none",
      borderRadius: "var(--radius-md)",
      padding: "14px 16px",
      transition: "border-color 0.15s ease, box-shadow 0.15s ease",
      opacity: disabled ? 0.6 : 1
    }
  }, leadingIcon, /*#__PURE__*/React.createElement("input", _extends({
    id: id,
    type: type,
    value: value,
    defaultValue: defaultValue,
    onChange: onChange,
    placeholder: placeholder,
    disabled: disabled,
    onFocus: () => setFocused(true),
    onBlur: () => setFocused(false),
    style: {
      flex: 1,
      border: "none",
      outline: "none",
      background: "transparent",
      fontFamily: "var(--font-body)",
      fontSize: "15px",
      color: "var(--text-primary)",
      padding: 0,
      minWidth: 0
    }
  }, rest))), helper && /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: "var(--font-body)",
      fontSize: "13px",
      color: error ? "var(--error)" : "var(--text-muted)"
    }
  }, helper));
}
Object.assign(__ds_scope, { Input });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Input.jsx", error: String((e && e.message) || e) }); }

// components/navigation/TabBar.jsx
try { (() => {
/**
 * onsplan TabBar — the floating mobile bottom navigation. Translucent
 * white pill, soft shadow, pill-highlighted active tab (accent-subtle well
 * with accent icon+label). Items pass their own icon nodes (Lucide).
 */
function TabBar({
  items = [],
  activeId,
  onSelect,
  style = {}
}) {
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      justifyContent: "center",
      padding: "0 16px 12px",
      ...style
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      alignItems: "center",
      justifyContent: "space-around",
      width: "100%",
      height: "var(--tabbar-height)",
      background: "rgba(255,255,255,0.72)",
      backdropFilter: "blur(16px)",
      WebkitBackdropFilter: "blur(16px)",
      borderRadius: "var(--radius-full)",
      boxShadow: "var(--shadow-md)",
      padding: "0 6px"
    }
  }, items.map(it => {
    const active = it.id === activeId;
    return /*#__PURE__*/React.createElement("button", {
      key: it.id,
      onClick: () => onSelect && onSelect(it.id),
      style: {
        display: "flex",
        flexDirection: "column",
        alignItems: "center",
        gap: 4,
        padding: "6px 12px",
        border: "none",
        cursor: "pointer",
        background: active ? "var(--accent-subtle)" : "transparent",
        color: active ? "var(--accent)" : "var(--text-muted)",
        borderRadius: "var(--radius-full)",
        transition: "background 0.15s ease, color 0.15s ease"
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        display: "inline-flex",
        width: 22,
        height: 22
      }
    }, it.icon), /*#__PURE__*/React.createElement("span", {
      style: {
        fontFamily: "var(--font-body)",
        fontSize: "10px",
        fontWeight: active ? 600 : 400
      }
    }, it.label));
  })));
}
Object.assign(__ds_scope, { TabBar });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/navigation/TabBar.jsx", error: String((e && e.message) || e) }); }

// components/navigation/TopNav.jsx
try { (() => {
/**
 * onsplan TopNav.
 * Source: component/TopNav — white bar, 64px, bottom border. Brand mark +
 * wordmark (Playfair) on the left with nav links; family switcher + user
 * trigger on the right.
 */
function TopNav({
  brand = "onsplan",
  mark = null,
  links = [],
  activeLink,
  onNavigate,
  familyName = null,
  user = null,
  right = null,
  style = {}
}) {
  return /*#__PURE__*/React.createElement("header", {
    style: {
      display: "flex",
      alignItems: "center",
      justifyContent: "space-between",
      height: "var(--nav-height)",
      padding: "0 32px",
      background: "var(--surface-card)",
      borderBottom: "1px solid var(--border)",
      ...style
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      alignItems: "center",
      gap: 40
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      alignItems: "center",
      gap: 8
    }
  }, mark && /*#__PURE__*/React.createElement("span", {
    style: {
      display: "inline-flex",
      color: "var(--accent)"
    }
  }, mark), /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: "var(--font-heading)",
      fontSize: 20,
      fontWeight: 600,
      color: "var(--text-primary)"
    }
  }, brand)), /*#__PURE__*/React.createElement("nav", {
    style: {
      display: "flex",
      alignItems: "center",
      gap: 28
    }
  }, links.map(l => {
    const active = l.id === activeLink;
    return /*#__PURE__*/React.createElement("button", {
      key: l.id,
      onClick: () => onNavigate && onNavigate(l.id),
      style: {
        border: "none",
        background: "transparent",
        cursor: "pointer",
        fontFamily: "var(--font-body)",
        fontSize: 15,
        fontWeight: active ? 600 : 500,
        color: active ? "var(--accent)" : "var(--text-muted)",
        padding: 0
      }
    }, l.label);
  }))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      alignItems: "center",
      gap: 12
    }
  }, right, familyName && /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      alignItems: "center",
      gap: 8,
      background: "var(--accent-subtle)",
      border: "1px solid var(--accent-light)",
      borderRadius: "var(--radius-full)",
      padding: "8px 14px",
      color: "var(--accent)",
      fontFamily: "var(--font-body)",
      fontSize: 14,
      fontWeight: 600
    }
  }, /*#__PURE__*/React.createElement("span", {
    "aria-hidden": true,
    style: {
      width: 8,
      height: 8,
      borderRadius: "50%",
      background: "var(--accent)"
    }
  }), familyName, /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 11
    }
  }, "\u25BE")), user && /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      alignItems: "center",
      gap: 6,
      background: "var(--surface)",
      border: "1px solid var(--border)",
      borderRadius: "var(--radius-full)",
      padding: "4px 8px 4px 4px"
    }
  }, /*#__PURE__*/React.createElement(__ds_scope.Avatar, {
    initial: user.initials,
    tint: user.tint || "accent",
    size: 32
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 11,
      color: "var(--text-muted)"
    }
  }, "\u25BE"))));
}
Object.assign(__ds_scope, { TopNav });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/navigation/TopNav.jsx", error: String((e && e.message) || e) }); }

// ui_kits/_shared/icons.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
// Shared inline icon set for the onsplan UI kits.
// Phosphor/Lucide-style strokes at 1.75–2px. Exported to window for
// cross-<script> sharing (Babel scripts don't share scope).
const Ic = ({
  d,
  size = 20,
  sw = 1.9,
  fill = false,
  children
}) => /*#__PURE__*/React.createElement("svg", {
  width: size,
  height: size,
  viewBox: "0 0 24 24",
  fill: fill ? "currentColor" : "none",
  stroke: "currentColor",
  strokeWidth: sw,
  strokeLinecap: "round",
  strokeLinejoin: "round"
}, children || /*#__PURE__*/React.createElement("path", {
  d: d
}));
const Icons = {
  leaf: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("path", {
    d: "M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"
  })),
  house: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("path", {
    d: "M3 9.5 12 3l9 6.5"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M5 10v10h5v-6h4v6h5V10"
  })),
  calendar: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("rect", {
    x: "3",
    y: "4",
    width: "18",
    height: "18",
    rx: "2"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M16 2v4M8 2v4M3 10h18"
  })),
  list: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("path", {
    d: "M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"
  })),
  listChecks: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("path", {
    d: "M11 6h10M11 12h10M11 18h10"
  }), /*#__PURE__*/React.createElement("path", {
    d: "m3 6 1.5 1.5L7 5M3 12l1.5 1.5L7 11M3 18l1.5 1.5L7 17"
  })),
  users: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("path", {
    d: "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "9",
    cy: "7",
    r: "4"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M22 21v-2a4 4 0 0 0-3-3.87"
  })),
  settings: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("circle", {
    cx: "12",
    cy: "12",
    r: "3"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"
  })),
  cart: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("circle", {
    cx: "8",
    cy: "21",
    r: "1"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "19",
    cy: "21",
    r: "1"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 2-1.58l1.65-7.42H5.12"
  })),
  plus: p => /*#__PURE__*/React.createElement(Ic, _extends({}, p, {
    sw: 2.2
  }), /*#__PURE__*/React.createElement("path", {
    d: "M12 5v14M5 12h14"
  })),
  chevronRight: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("path", {
    d: "m9 18 6-6-6-6"
  })),
  chevronDown: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("path", {
    d: "m6 9 6 6 6-6"
  })),
  store: p => /*#__PURE__*/React.createElement(Ic, _extends({}, p, {
    sw: 1.7
  }), /*#__PURE__*/React.createElement("path", {
    d: "M4 9v11h16V9M2 7l2-4h16l2 4a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z"
  })),
  userPlus: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("path", {
    d: "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "9",
    cy: "7",
    r: "4"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M19 8v6M22 11h-6"
  })),
  bell: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("path", {
    d: "M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M10.3 21a1.94 1.94 0 0 0 3.4 0"
  })),
  clock: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("circle", {
    cx: "12",
    cy: "12",
    r: "9"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M12 7v5l3 2"
  })),
  mapPin: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("path", {
    d: "M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "12",
    cy: "10",
    r: "3"
  })),
  dots: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("circle", {
    cx: "12",
    cy: "5",
    r: "1.4"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "12",
    cy: "12",
    r: "1.4"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "12",
    cy: "19",
    r: "1.4"
  })),
  search: p => /*#__PURE__*/React.createElement(Ic, p, /*#__PURE__*/React.createElement("circle", {
    cx: "11",
    cy: "11",
    r: "7"
  }), /*#__PURE__*/React.createElement("path", {
    d: "m21 21-4.3-4.3"
  })),
  signal: p => /*#__PURE__*/React.createElement(Ic, _extends({}, p, {
    sw: 2
  }), /*#__PURE__*/React.createElement("path", {
    d: "M2 20h.01M7 20v-4M12 20v-8M17 20V8"
  })),
  wifi: p => /*#__PURE__*/React.createElement(Ic, _extends({}, p, {
    sw: 2
  }), /*#__PURE__*/React.createElement("path", {
    d: "M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M12 19.5h.01"
  })),
  battery: p => /*#__PURE__*/React.createElement(Ic, _extends({}, p, {
    sw: 1.8
  }), /*#__PURE__*/React.createElement("rect", {
    x: "2",
    y: "8",
    width: "17",
    height: "8",
    rx: "2"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M22 11v2"
  }), /*#__PURE__*/React.createElement("rect", {
    x: "4",
    y: "10",
    width: "12",
    height: "4",
    rx: "1",
    fill: "currentColor",
    stroke: "none"
  })),
  check: p => /*#__PURE__*/React.createElement(Ic, _extends({}, p, {
    sw: 2.4
  }), /*#__PURE__*/React.createElement("path", {
    d: "M20 6 9 17l-5-5"
  }))
};
Object.assign(window, {
  Icons,
  Ic
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/_shared/icons.jsx", error: String((e && e.message) || e) }); }

// ui_kits/web-app/WebDashboard.jsx
try { (() => {
// onsplan Web · Core Dashboard — stat row, Today card, side rail.
const {
  Card,
  Badge,
  Avatar,
  Checkbox,
  Button: FWButton
} = window.OnsplanDesignSystem_c7911f;
function Stat({
  icon,
  well,
  iconColor,
  num,
  label
}) {
  return /*#__PURE__*/React.createElement(Card, {
    radius: "lg",
    style: {
      flex: 1,
      display: "flex",
      alignItems: "center",
      gap: 14
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      width: 44,
      height: 44,
      borderRadius: "var(--radius-md)",
      background: well,
      display: "flex",
      alignItems: "center",
      justifyContent: "center",
      color: iconColor
    }
  }, icon), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    style: {
      fontFamily: "var(--font-heading)",
      fontSize: 24,
      fontWeight: 600
    }
  }, num), /*#__PURE__*/React.createElement("div", {
    style: {
      color: "var(--text-secondary)",
      fontSize: 13
    }
  }, label)));
}
function EventRow({
  bar,
  title,
  who,
  time
}) {
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      alignItems: "center",
      gap: 12,
      padding: "12px 0",
      borderBottom: "1px solid var(--border)"
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      width: 3,
      height: 34,
      borderRadius: 99,
      background: bar
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      fontWeight: 500,
      fontSize: 15
    }
  }, title), /*#__PURE__*/React.createElement("div", {
    style: {
      color: "var(--text-secondary)",
      fontSize: 13
    }
  }, who)), /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: "var(--font-mono)",
      fontSize: 12,
      color: "var(--text-muted)"
    }
  }, time));
}
function WebDashboard() {
  const I = window.Icons;
  const [tasks, setTasks] = React.useState([{
    t: "Buy groceries for dinner",
    who: "Maya",
    done: false
  }, {
    t: "Pick up dry cleaning",
    who: "Dad",
    done: true
  }, {
    t: "Book summer camp",
    who: "Maya",
    done: false
  }, {
    t: "RSVP to the Chen's BBQ",
    who: "Dad",
    done: false
  }]);
  const toggle = i => setTasks(p => p.map((x, k) => k === i ? {
    ...x,
    done: !x.done
  } : x));
  return /*#__PURE__*/React.createElement("div", {
    style: {
      padding: "36px 48px",
      display: "flex",
      flexDirection: "column",
      gap: 28,
      height: "100%",
      boxSizing: "border-box",
      overflow: "auto"
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      justifyContent: "space-between",
      alignItems: "center"
    }
  }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("h1", {
    style: {
      fontFamily: "var(--font-heading)",
      fontSize: 30,
      fontWeight: 600
    }
  }, "Good afternoon, Maya"), /*#__PURE__*/React.createElement("p", {
    style: {
      color: "var(--text-secondary)",
      fontSize: 15,
      marginTop: 5
    }
  }, "Tuesday, July 15 \xB7 3 things need your attention today")), /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      gap: 12
    }
  }, /*#__PURE__*/React.createElement(FWButton, {
    variant: "secondary",
    size: "sm",
    leadingIcon: /*#__PURE__*/React.createElement(I.userPlus, {
      size: 16
    })
  }, "Invite"), /*#__PURE__*/React.createElement(FWButton, {
    variant: "primary",
    size: "sm",
    leadingIcon: /*#__PURE__*/React.createElement(I.plus, {
      size: 16
    })
  }, "Quick add"))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      gap: 24,
      flex: 1,
      minHeight: 0
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      display: "flex",
      flexDirection: "column",
      gap: 24
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      gap: 16
    }
  }, /*#__PURE__*/React.createElement(Stat, {
    icon: /*#__PURE__*/React.createElement(I.listChecks, {
      size: 22
    }),
    well: "var(--accent-subtle)",
    iconColor: "var(--accent)",
    num: "5",
    label: "Open tasks"
  }), /*#__PURE__*/React.createElement(Stat, {
    icon: /*#__PURE__*/React.createElement(I.cart, {
      size: 22
    }),
    well: "var(--warning-subtle)",
    iconColor: "var(--warning)",
    num: "12",
    label: "Items to buy"
  }), /*#__PURE__*/React.createElement(Stat, {
    icon: /*#__PURE__*/React.createElement(I.calendar, {
      size: 22
    }),
    well: "var(--accent-subtle)",
    iconColor: "var(--accent)",
    num: "3",
    label: "Events today"
  })), /*#__PURE__*/React.createElement(Card, {
    radius: "lg",
    padding: 24,
    style: {
      flex: 1
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      justifyContent: "space-between",
      alignItems: "center",
      marginBottom: 8
    }
  }, /*#__PURE__*/React.createElement("h2", {
    style: {
      fontFamily: "var(--font-heading)",
      fontSize: 20,
      fontWeight: 600
    }
  }, "Today's schedule"), /*#__PURE__*/React.createElement("a", {
    href: "#",
    style: {
      fontSize: 14
    }
  }, "Open calendar")), /*#__PURE__*/React.createElement(EventRow, {
    bar: "var(--accent)",
    title: "School drop-off",
    who: "Emma",
    time: "9:00"
  }), /*#__PURE__*/React.createElement(EventRow, {
    bar: "var(--warning)",
    title: "Dentist appointment",
    who: "Leo",
    time: "11:30"
  }), /*#__PURE__*/React.createElement(EventRow, {
    bar: "var(--accent-light)",
    title: "Soccer practice",
    who: "Emma",
    time: "16:00"
  }))), /*#__PURE__*/React.createElement("div", {
    style: {
      width: 340,
      flex: "0 0 auto",
      display: "flex",
      flexDirection: "column",
      gap: 24
    }
  }, /*#__PURE__*/React.createElement(Card, {
    radius: "lg",
    padding: 24
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      justifyContent: "space-between",
      alignItems: "center",
      marginBottom: 14
    }
  }, /*#__PURE__*/React.createElement("h2", {
    style: {
      fontFamily: "var(--font-heading)",
      fontSize: 20,
      fontWeight: 600
    }
  }, "Shared tasks"), /*#__PURE__*/React.createElement(Badge, null, tasks.filter(x => !x.done).length, " left")), /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      flexDirection: "column",
      gap: 14
    }
  }, tasks.map((t, i) => /*#__PURE__*/React.createElement("div", {
    key: i,
    style: {
      display: "flex",
      alignItems: "center",
      gap: 12
    }
  }, /*#__PURE__*/React.createElement(Checkbox, {
    checked: t.done,
    onChange: () => toggle(i)
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 15,
      color: t.done ? "var(--text-muted)" : "var(--text-primary)",
      textDecoration: t.done ? "line-through" : "none"
    }
  }, t.t), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 12,
      color: "var(--text-muted)"
    }
  }, t.who)))))), /*#__PURE__*/React.createElement(Card, {
    radius: "lg",
    padding: 0,
    style: {
      overflow: "hidden"
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      height: 120,
      backgroundImage: "url(../../assets/img/scene-2.png)",
      backgroundSize: "cover",
      backgroundPosition: "center"
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      padding: 20
    }
  }, /*#__PURE__*/React.createElement("h3", {
    style: {
      fontFamily: "var(--font-heading)",
      fontSize: 17,
      fontWeight: 600
    }
  }, "Weekend at the cabin"), /*#__PURE__*/React.createElement("p", {
    style: {
      color: "var(--text-secondary)",
      fontSize: 13,
      marginTop: 4
    }
  }, "Sat\u2013Sun \xB7 4 going \xB7 Packing list started"))))));
}
window.WebDashboard = WebDashboard;
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/web-app/WebDashboard.jsx", error: String((e && e.message) || e) }); }

// ui_kits/web-app/WebLogin.jsx
try { (() => {
// onsplan Web · Login — split brand panel + form.
const {
  Button,
  Input
} = window.OnsplanDesignSystem_c7911f;
function WebLogin({
  onSignIn
}) {
  const I = window.Icons;
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      height: "100%",
      background: "var(--bg)"
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      width: 600,
      flex: "0 0 auto",
      background: "var(--surface-dark)",
      padding: 56,
      display: "flex",
      flexDirection: "column",
      justifyContent: "space-between"
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      alignItems: "center",
      gap: 10,
      color: "var(--accent-light)"
    }
  }, /*#__PURE__*/React.createElement(I.leaf, {
    size: 26
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: "var(--font-heading)",
      fontSize: 24,
      fontWeight: 600,
      color: "var(--text-inverse)"
    }
  }, "onsplan")), /*#__PURE__*/React.createElement("div", {
    style: {
      height: 340,
      borderRadius: "var(--radius-lg)",
      overflow: "hidden",
      backgroundImage: "url(../../assets/img/scene-1.png)",
      backgroundSize: "cover",
      backgroundPosition: "center"
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      maxWidth: 400
    }
  }, /*#__PURE__*/React.createElement("h1", {
    style: {
      fontFamily: "var(--font-heading)",
      fontSize: 34,
      fontWeight: 500,
      color: "var(--text-inverse)",
      lineHeight: 1.15
    }
  }, "Where your family finds its rhythm."), /*#__PURE__*/React.createElement("p", {
    style: {
      color: "var(--accent-light)",
      fontSize: 15,
      marginTop: 12
    }
  }, "Calm, shared organization for the people who matter most."))), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      display: "flex",
      alignItems: "center",
      justifyContent: "center",
      padding: 48
    }
  }, /*#__PURE__*/React.createElement("form", {
    style: {
      width: 420,
      display: "flex",
      flexDirection: "column",
      gap: 24
    },
    onSubmit: e => {
      e.preventDefault();
      onSignIn();
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      flexDirection: "column",
      gap: 8
    }
  }, /*#__PURE__*/React.createElement("h2", {
    style: {
      fontFamily: "var(--font-heading)",
      fontSize: 32,
      fontWeight: 600
    }
  }, "Welcome back"), /*#__PURE__*/React.createElement("p", {
    style: {
      color: "var(--text-secondary)",
      fontSize: 15
    }
  }, "Sign in to keep your family in sync.")), /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      flexDirection: "column",
      gap: 18
    }
  }, /*#__PURE__*/React.createElement(Input, {
    label: "Email",
    type: "email",
    placeholder: "you@example.com",
    defaultValue: "maya@rivera.com"
  }), /*#__PURE__*/React.createElement(Input, {
    label: "Password",
    type: "password",
    placeholder: "\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022",
    defaultValue: "password"
  })), /*#__PURE__*/React.createElement(Button, {
    variant: "primary",
    fullWidth: true,
    type: "submit"
  }, "Sign in"), /*#__PURE__*/React.createElement("p", {
    style: {
      textAlign: "center",
      fontSize: 14,
      color: "var(--text-muted)"
    }
  }, "New here? ", /*#__PURE__*/React.createElement("a", {
    href: "#",
    onClick: e => {
      e.preventDefault();
      onSignIn();
    }
  }, "Create a family")))));
}
window.WebLogin = WebLogin;
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/web-app/WebLogin.jsx", error: String((e && e.message) || e) }); }

// ui_kits/web-app/WebTodos.jsx
try { (() => {
// onsplan Web · Todos & Shopping — two-pane list view.
const {
  Card,
  Badge,
  Checkbox,
  Button: TBtn
} = window.OnsplanDesignSystem_c7911f;
function WebTodos() {
  const I = window.Icons;
  const lists = [{
    name: "Weekly Groceries",
    store: "Whole Foods",
    done: 8,
    total: 20,
    active: true
  }, {
    name: "Household",
    store: "Target",
    done: 2,
    total: 6,
    active: false
  }, {
    name: "Pharmacy",
    store: "CVS",
    done: 1,
    total: 3,
    active: false
  }];
  const [items, setItems] = React.useState([{
    t: "Whole milk",
    qty: "2 gal",
    cat: "Dairy",
    done: true
  }, {
    t: "Sourdough loaf",
    qty: "1",
    cat: "Bakery",
    done: true
  }, {
    t: "Baby spinach",
    qty: "2 bags",
    cat: "Produce",
    done: false
  }, {
    t: "Roma tomatoes",
    qty: "6",
    cat: "Produce",
    done: false
  }, {
    t: "Chicken thighs",
    qty: "3 lb",
    cat: "Meat",
    done: false
  }, {
    t: "Olive oil",
    qty: "1",
    cat: "Pantry",
    done: false
  }]);
  const toggle = i => setItems(p => p.map((x, k) => k === i ? {
    ...x,
    done: !x.done
  } : x));
  const done = items.filter(x => x.done).length;
  return /*#__PURE__*/React.createElement("div", {
    style: {
      padding: "36px 48px",
      display: "flex",
      gap: 24,
      height: "100%",
      boxSizing: "border-box"
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      width: 320,
      flex: "0 0 auto",
      display: "flex",
      flexDirection: "column",
      gap: 16
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      justifyContent: "space-between",
      alignItems: "center"
    }
  }, /*#__PURE__*/React.createElement("h1", {
    style: {
      fontFamily: "var(--font-heading)",
      fontSize: 26,
      fontWeight: 600
    }
  }, "Shopping"), /*#__PURE__*/React.createElement(TBtn, {
    variant: "primary",
    size: "sm",
    leadingIcon: /*#__PURE__*/React.createElement(I.plus, {
      size: 16
    })
  }, "New list")), lists.map((l, i) => /*#__PURE__*/React.createElement(Card, {
    key: i,
    radius: "lg",
    style: {
      display: "flex",
      flexDirection: "column",
      gap: 12,
      outline: l.active ? "2px solid var(--accent)" : "none",
      cursor: "pointer"
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      alignItems: "center",
      gap: 12
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      width: 40,
      height: 40,
      borderRadius: "var(--radius-md)",
      background: "var(--accent-subtle)",
      color: "var(--accent)",
      display: "flex",
      alignItems: "center",
      justifyContent: "center"
    }
  }, /*#__PURE__*/React.createElement(I.cart, {
    size: 20
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      fontWeight: 600,
      fontSize: 16
    }
  }, l.name), /*#__PURE__*/React.createElement("div", {
    style: {
      display: "flex",
      alignItems: "center",
      gap: 5,
      color: "var(--text-muted)",
      fontSize: 12
    }
  }, /*#__PURE__*/React.createElement(I.store, {
    size: 12
  }), l.store)), /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: "var(--font-mono)",
      fontSize: 12,
      color: "var(--text-muted)"
    }
  }, l.done, "/", l.total)), /*#__PURE__*/React.createElement("div", {
    style: {
      height: 6,
      background: "var(--border)",
      borderRadius: 99,
      overflow: "hidden"
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      width: `${l.done / l.total * 100}%`,
      height: "100%",
      background: "var(--accent)"
    }
  }))))), /*#__PURE__*/React.createElement(Card, {
    radius: "lg",
    padding: 0,
    style: {
      flex: 1,
      display: "flex",
      flexDirection: "column",
      overflow: "hidden"
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      padding: "22px 24px",
      borderBottom: "1px solid var(--border)",
      display: "flex",
      justifyContent: "space-between",
      alignItems: "center"
    }
  }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("h2", {
    style: {
      fontFamily: "var(--font-heading)",
      fontSize: 22,
      fontWeight: 600
    }
  }, "Weekly Groceries"), /*#__PURE__*/React.createElement("p", {
    style: {
      color: "var(--text-secondary)",
      fontSize: 13,
      marginTop: 3
    }
  }, done, " of ", items.length, " picked up \xB7 Whole Foods")), /*#__PURE__*/React.createElement(Badge, {
    variant: "subtle"
  }, items.length - done, " to buy")), /*#__PURE__*/React.createElement("div", {
    style: {
      padding: "8px 12px",
      overflow: "auto",
      flex: 1
    }
  }, items.map((it, i) => /*#__PURE__*/React.createElement("div", {
    key: i,
    style: {
      display: "flex",
      alignItems: "center",
      gap: 14,
      padding: "12px 12px",
      borderRadius: "var(--radius-md)"
    }
  }, /*#__PURE__*/React.createElement(Checkbox, {
    checked: it.done,
    onChange: () => toggle(i)
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      fontSize: 15,
      color: it.done ? "var(--text-muted)" : "var(--text-primary)",
      textDecoration: it.done ? "line-through" : "none"
    }
  }, it.t), /*#__PURE__*/React.createElement(Badge, {
    variant: "neutral"
  }, it.cat), /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: "var(--font-mono)",
      fontSize: 12,
      color: "var(--text-muted)",
      width: 48,
      textAlign: "right"
    }
  }, it.qty))))));
}
window.WebTodos = WebTodos;
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/web-app/WebTodos.jsx", error: String((e && e.message) || e) }); }

__ds_ns.AVATAR_TINTS = __ds_scope.AVATAR_TINTS;

__ds_ns.Avatar = __ds_scope.Avatar;

__ds_ns.Badge = __ds_scope.Badge;

__ds_ns.Button = __ds_scope.Button;

__ds_ns.Card = __ds_scope.Card;

__ds_ns.Checkbox = __ds_scope.Checkbox;

__ds_ns.IconButton = __ds_scope.IconButton;

__ds_ns.Input = __ds_scope.Input;

__ds_ns.TabBar = __ds_scope.TabBar;

__ds_ns.TopNav = __ds_scope.TopNav;

})();
