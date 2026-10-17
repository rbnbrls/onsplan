// onsplan Web · Todos & Shopping — two-pane list view.
const { Card, Badge, Checkbox, Button: TBtn } = window.OnsplanDesignSystem_c7911f;

function WebTodos() {
  const I = window.Icons;
  const lists = [
    { name: "Weekly Groceries", store: "Whole Foods", done: 8, total: 20, active: true },
    { name: "Household", store: "Target", done: 2, total: 6, active: false },
    { name: "Pharmacy", store: "CVS", done: 1, total: 3, active: false },
  ];
  const [items, setItems] = React.useState([
    { t: "Whole milk", qty: "2 gal", cat: "Dairy", done: true },
    { t: "Sourdough loaf", qty: "1", cat: "Bakery", done: true },
    { t: "Baby spinach", qty: "2 bags", cat: "Produce", done: false },
    { t: "Roma tomatoes", qty: "6", cat: "Produce", done: false },
    { t: "Chicken thighs", qty: "3 lb", cat: "Meat", done: false },
    { t: "Olive oil", qty: "1", cat: "Pantry", done: false },
  ]);
  const toggle = (i) => setItems((p) => p.map((x, k) => k === i ? { ...x, done: !x.done } : x));
  const done = items.filter((x) => x.done).length;
  return (
    <div style={{ padding: "36px 48px", display: "flex", gap: 24, height: "100%", boxSizing: "border-box" }}>
      <div style={{ width: 320, flex: "0 0 auto", display: "flex", flexDirection: "column", gap: 16 }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <h1 style={{ fontFamily: "var(--font-heading)", fontSize: 26, fontWeight: 600 }}>Shopping</h1>
          <TBtn variant="primary" size="sm" leadingIcon={<I.plus size={16} />}>New list</TBtn>
        </div>
        {lists.map((l, i) => (
          <Card key={i} radius="lg" style={{ display: "flex", flexDirection: "column", gap: 12,
            outline: l.active ? "2px solid var(--accent)" : "none", cursor: "pointer" }}>
            <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
              <div style={{ width: 40, height: 40, borderRadius: "var(--radius-md)", background: "var(--accent-subtle)",
                color: "var(--accent)", display: "flex", alignItems: "center", justifyContent: "center" }}><I.cart size={20} /></div>
              <div style={{ flex: 1 }}>
                <div style={{ fontWeight: 600, fontSize: 16 }}>{l.name}</div>
                <div style={{ display: "flex", alignItems: "center", gap: 5, color: "var(--text-muted)", fontSize: 12 }}>
                  <I.store size={12} />{l.store}
                </div>
              </div>
              <span style={{ fontFamily: "var(--font-mono)", fontSize: 12, color: "var(--text-muted)" }}>{l.done}/{l.total}</span>
            </div>
            <div style={{ height: 6, background: "var(--border)", borderRadius: 99, overflow: "hidden" }}>
              <div style={{ width: `${(l.done / l.total) * 100}%`, height: "100%", background: "var(--accent)" }} />
            </div>
          </Card>
        ))}
      </div>
      <Card radius="lg" padding={0} style={{ flex: 1, display: "flex", flexDirection: "column", overflow: "hidden" }}>
        <div style={{ padding: "22px 24px", borderBottom: "1px solid var(--border)", display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <div>
            <h2 style={{ fontFamily: "var(--font-heading)", fontSize: 22, fontWeight: 600 }}>Weekly Groceries</h2>
            <p style={{ color: "var(--text-secondary)", fontSize: 13, marginTop: 3 }}>{done} of {items.length} picked up · Whole Foods</p>
          </div>
          <Badge variant="subtle">{items.length - done} to buy</Badge>
        </div>
        <div style={{ padding: "8px 12px", overflow: "auto", flex: 1 }}>
          {items.map((it, i) => (
            <div key={i} style={{ display: "flex", alignItems: "center", gap: 14, padding: "12px 12px", borderRadius: "var(--radius-md)" }}>
              <Checkbox checked={it.done} onChange={() => toggle(i)} />
              <div style={{ flex: 1, fontSize: 15, color: it.done ? "var(--text-muted)" : "var(--text-primary)", textDecoration: it.done ? "line-through" : "none" }}>{it.t}</div>
              <Badge variant="neutral">{it.cat}</Badge>
              <span style={{ fontFamily: "var(--font-mono)", fontSize: 12, color: "var(--text-muted)", width: 48, textAlign: "right" }}>{it.qty}</span>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
window.WebTodos = WebTodos;
