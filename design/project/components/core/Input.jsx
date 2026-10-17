import React from "react";

/**
 * onsplan InputField.
 * Source: component/InputField — label (Inter 14/500) + white box with
 * 1px border, radius-md, 14/16 padding, muted placeholder, optional helper
 * (error state turns border + helper red).
 */
export function Input({
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
  const borderColor = error
    ? "var(--error)"
    : focused
    ? "var(--accent)"
    : "var(--border)";
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: "8px", width: "100%", ...style }}>
      {label && (
        <label htmlFor={id} style={{
          fontFamily: "var(--font-body)", fontSize: "14px", fontWeight: 500,
          color: "var(--text-primary)",
        }}>{label}</label>
      )}
      <div style={{
        display: "flex", alignItems: "center", gap: "10px",
        background: disabled ? "var(--surface)" : "var(--surface-card)",
        border: `1px solid ${borderColor}`,
        boxShadow: focused && !error ? "0 0 0 3px var(--accent-subtle)" : "none",
        borderRadius: "var(--radius-md)", padding: "14px 16px",
        transition: "border-color 0.15s ease, box-shadow 0.15s ease",
        opacity: disabled ? 0.6 : 1,
      }}>
        {leadingIcon}
        <input
          id={id}
          type={type}
          value={value}
          defaultValue={defaultValue}
          onChange={onChange}
          placeholder={placeholder}
          disabled={disabled}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          style={{
            flex: 1, border: "none", outline: "none", background: "transparent",
            fontFamily: "var(--font-body)", fontSize: "15px", color: "var(--text-primary)",
            padding: 0, minWidth: 0,
          }}
          {...rest}
        />
      </div>
      {helper && (
        <span style={{
          fontFamily: "var(--font-body)", fontSize: "13px",
          color: error ? "var(--error)" : "var(--text-muted)",
        }}>{helper}</span>
      )}
    </div>
  );
}
