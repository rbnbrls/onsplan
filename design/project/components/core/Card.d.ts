import React from "react";

export interface CardProps extends React.HTMLAttributes<HTMLDivElement> {
  /** Add a soft drop shadow (var(--shadow-sm)). */
  elevated?: boolean;
  /** Inner padding in px (or any CSS length). Default 18. */
  padding?: number | string;
  /** Corner radius token: "md" (12) default, "lg" (16) for large panels. */
  radius?: "md" | "lg";
  /** A CSS color for the 3px left accent bar (schedule/event rows). */
  accentBar?: string;
  children?: React.ReactNode;
}

/**
 * Surface panel — the white card used across onsplan.
 * @startingPoint section="Core" subtitle="White surface panel, optional accent bar" viewport="700x200"
 */
export function Card(props: CardProps): JSX.Element;
