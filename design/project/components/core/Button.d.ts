import React from "react";

export type ButtonVariant = "primary" | "secondary" | "ghost" | "danger";
export type ButtonSize = "sm" | "md" | "lg";

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  /** Visual style. Primary = accent-green fill (the source PrimaryButton). */
  variant?: ButtonVariant;
  /** Control height / padding. Default "md" (15/20 padding). */
  size?: ButtonSize;
  /** Stretch to fill the container width. */
  fullWidth?: boolean;
  /** Icon node rendered before the label (e.g. a Lucide/Phosphor SVG). */
  leadingIcon?: React.ReactNode;
  /** Icon node rendered after the label. */
  trailingIcon?: React.ReactNode;
  children?: React.ReactNode;
}

/**
 * Primary call-to-action button in the onsplan UI.
 * @startingPoint section="Core" subtitle="Accent, secondary, ghost & danger buttons" viewport="700x200"
 */
export function Button(props: ButtonProps): JSX.Element;
