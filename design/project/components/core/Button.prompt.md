**Button** — the primary tap/click target across onsplan. Use `primary` for the main action on a screen, `secondary` for neutral actions next to it, `ghost` for tertiary/inline actions, `danger` for destructive confirmations.

```jsx
<Button variant="primary" leadingIcon={<PlusIcon />}>Quick add</Button>
<Button variant="secondary" size="sm">Invite</Button>
```

Sizes: `sm` (10/16), `md` (15/20, default), `lg` (17/26). Pass `fullWidth` for form submits. Icons come from Lucide (mobile) or Phosphor (web) — the button is icon-library-agnostic; hand it a node.
