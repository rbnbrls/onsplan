**IconButton** — single-icon control. Use for toolbar actions, avatars-as-buttons, and the round icon wells seen throughout onsplan. Always pass `ariaLabel`.

```jsx
<IconButton icon={<BellIcon />} variant="subtle" ariaLabel="Notifications" />
<IconButton icon={<PlusIcon />} variant="solid" shape="square" ariaLabel="Add" />
```

Variants: `subtle` (accent-subtle well — default), `surface` (bordered white), `solid` (accent), `ghost`.
