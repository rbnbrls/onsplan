// onsplan Web · Core Dashboard — stat row, Today card, side rail.
const { Card, Badge, Avatar, Checkbox, Button: FWButton } = window.OnsplanDesignSystem_c7911f;

function Stat({ icon, well, iconColor, num, label }) {
  return (
    <Card radius="lg" style={{ flex: 1, display: "flex", alignItems: "center", gap: 14 }}>
      <div style={{ width: 44, height: 44, borderRadius: "var(--radius-md)", background: well,
        display: "flex", alignItems: "center", justifyContent: "center", color: iconColor }}>{icon}</div>
      <div>
        <div style={{ fontFamily: "var(--font-heading)", fontSize: 24, fontWeight: 600 }}>{num}</div>
        <div style={{ color: "var(--text-secondary)", fontSize: 13 }}>{label}</div>
      </div>
    </Card>
  );
}

function EventRow({ bar, title, who, time }) {
  return (
    <div style={{ display: "flex", alignItems: "center", gap: 12, padding: "12px 0", borderBottom: "1px solid var(--border)" }}>
      <div style={{ width: 3, height: 34, borderRadius: 99, background: bar }} />
      <div style={{ flex: 1 }}>
        <div style={{ fontWeight: 500, fontSize: 15 }}>{title}</div>
        <div style={{ color: "var(--text-secondary)", fontSize: 13 }}>{who}</div>
      </div>
      <span style={{ fontFamily: "var(--font-mono)", fontSize: 12, color: "var(--text-muted)" }}>{time}</span>
    </div>
  );
}

function WebDashboard() {
  const I = window.Icons;
  const [tasks, setTasks] = React.useState([
    { t: "Buy groceries for dinner", who: "Maya", done: false },
    { t: "Pick up dry cleaning", who: "Dad", done: true },
    { t: "Book summer camp", who: "Maya", done: false },
    { t: "RSVP to the Chen's BBQ", who: "Dad", done: false },
  ]);
  const toggle = (i) => setTasks((p) => p.map((x, k) => k === i ? { ...x, done: !x.done } : x));
  return (
    <div style={{ padding: "36px 48px", display: "flex", flexDirection: "column", gap: 28, height: "100%", boxSizing: "border-box", overflow: "auto" }}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
        <div>
          <h1 style={{ fontFamily: "var(--font-heading)", fontSize: 30, fontWeight: 600 }}>Good afternoon, Maya</h1>
          <p style={{ color: "var(--text-secondary)", fontSize: 15, marginTop: 5 }}>Tuesday, July 15 · 3 things need your attention today</p>
        </div>
        <div style={{ display: "flex", gap: 12 }}>
          <FWButton variant="secondary" size="sm" leadingIcon={<I.userPlus size={16} />}>Invite</FWButton>
          <FWButton variant="primary" size="sm" leadingIcon={<I.plus size={16} />}>Quick add</FWButton>
        </div>
      </div>
      <div style={{ display: "flex", gap: 24, flex: 1, minHeight: 0 }}>
        <div style={{ flex: 1, display: "flex", flexDirection: "column", gap: 24 }}>
          <div style={{ display: "flex", gap: 16 }}>
            <Stat icon={<I.listChecks size={22} />} well="var(--accent-subtle)" iconColor="var(--accent)" num="5" label="Open tasks" />
            <Stat icon={<I.cart size={22} />} well="var(--warning-subtle)" iconColor="var(--warning)" num="12" label="Items to buy" />
            <Stat icon={<I.calendar size={22} />} well="var(--accent-subtle)" iconColor="var(--accent)" num="3" label="Events today" />
          </div>
          <Card radius="lg" padding={24} style={{ flex: 1 }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 8 }}>
              <h2 style={{ fontFamily: "var(--font-heading)", fontSize: 20, fontWeight: 600 }}>Today's schedule</h2>
              <a href="#" style={{ fontSize: 14 }}>Open calendar</a>
            </div>
            <EventRow bar="var(--accent)" title="School drop-off" who="Emma" time="9:00" />
            <EventRow bar="var(--warning)" title="Dentist appointment" who="Leo" time="11:30" />
            <EventRow bar="var(--accent-light)" title="Soccer practice" who="Emma" time="16:00" />
          </Card>
        </div>
        <div style={{ width: 340, flex: "0 0 auto", display: "flex", flexDirection: "column", gap: 24 }}>
          <Card radius="lg" padding={24}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 14 }}>
              <h2 style={{ fontFamily: "var(--font-heading)", fontSize: 20, fontWeight: 600 }}>Shared tasks</h2>
              <Badge>{tasks.filter((x) => !x.done).length} left</Badge>
            </div>
            <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
              {tasks.map((t, i) => (
                <div key={i} style={{ display: "flex", alignItems: "center", gap: 12 }}>
                  <Checkbox checked={t.done} onChange={() => toggle(i)} />
                  <div style={{ flex: 1 }}>
                    <div style={{ fontSize: 15, color: t.done ? "var(--text-muted)" : "var(--text-primary)", textDecoration: t.done ? "line-through" : "none" }}>{t.t}</div>
                    <div style={{ fontSize: 12, color: "var(--text-muted)" }}>{t.who}</div>
                  </div>
                </div>
              ))}
            </div>
          </Card>
          <Card radius="lg" padding={0} style={{ overflow: "hidden" }}>
            <div style={{ height: 120, backgroundImage: "url(../../assets/img/scene-2.png)", backgroundSize: "cover", backgroundPosition: "center" }} />
            <div style={{ padding: 20 }}>
              <h3 style={{ fontFamily: "var(--font-heading)", fontSize: 17, fontWeight: 600 }}>Weekend at the cabin</h3>
              <p style={{ color: "var(--text-secondary)", fontSize: 13, marginTop: 4 }}>Sat–Sun · 4 going · Packing list started</p>
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}
window.WebDashboard = WebDashboard;
