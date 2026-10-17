import React from "react";

export interface TopNavLink {
  id: string;
  label: string;
}

export interface TopNavUser {
  initials: string;
  tint?: "sage" | "blue" | "clay" | "lilac" | "accent" | "subtle";
}

export interface TopNavProps {
  /** Wordmark text. Default "onsplan". */
  brand?: string;
  /** Optional brand-mark icon node rendered before the wordmark. */
  mark?: React.ReactNode;
  links?: TopNavLink[];
  /** id of the active nav link. */
  activeLink?: string;
  onNavigate?: (id: string) => void;
  /** Family switcher label; omit to hide. */
  familyName?: string | null;
  /** Signed-in user for the avatar trigger. */
  user?: TopNavUser | null;
  /** Extra nodes (e.g. buttons) rendered left of the switcher. */
  right?: React.ReactNode;
  style?: React.CSSProperties;
}

/**
 * Web product top navigation bar — the source TopNav.
 * @startingPoint section="Navigation" subtitle="Web top navigation bar" viewport="1440x120"
 */
export function TopNav(props: TopNavProps): JSX.Element;
