// onsplan Web · Login — split brand panel + form.
const { Button, Input } = window.OnsplanDesignSystem_c7911f;
function WebLogin({ onSignIn }) {
  const I = window.Icons;
  return (
    <div style={{ display: "flex", height: "100%", background: "var(--bg)" }}>
      <div style={{
        width: 600, flex: "0 0 auto", background: "var(--surface-dark)",
        padding: 56, display: "flex", flexDirection: "column", justifyContent: "space-between",
      }}>
        <div style={{ display: "flex", alignItems: "center", gap: 10, color: "var(--accent-light)" }}>
          <I.leaf size={26} />
          <span style={{ fontFamily: "var(--font-heading)", fontSize: 24, fontWeight: 600, color: "var(--text-inverse)" }}>onsplan</span>
        </div>
        <div style={{
          height: 340, borderRadius: "var(--radius-lg)", overflow: "hidden",
          backgroundImage: "url(../../assets/img/scene-1.png)", backgroundSize: "cover", backgroundPosition: "center",
        }} />
        <div style={{ maxWidth: 400 }}>
          <h1 style={{ fontFamily: "var(--font-heading)", fontSize: 34, fontWeight: 500, color: "var(--text-inverse)", lineHeight: 1.15 }}>
            Where your family finds its rhythm.
          </h1>
          <p style={{ color: "var(--accent-light)", fontSize: 15, marginTop: 12 }}>
            Calm, shared organization for the people who matter most.
          </p>
        </div>
      </div>
      <div style={{ flex: 1, display: "flex", alignItems: "center", justifyContent: "center", padding: 48 }}>
        <form style={{ width: 420, display: "flex", flexDirection: "column", gap: 24 }}
          onSubmit={(e) => { e.preventDefault(); onSignIn(); }}>
          <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
            <h2 style={{ fontFamily: "var(--font-heading)", fontSize: 32, fontWeight: 600 }}>Welcome back</h2>
            <p style={{ color: "var(--text-secondary)", fontSize: 15 }}>Sign in to keep your family in sync.</p>
          </div>
          <div style={{ display: "flex", flexDirection: "column", gap: 18 }}>
            <Input label="Email" type="email" placeholder="you@example.com" defaultValue="maya@rivera.com" />
            <Input label="Password" type="password" placeholder="••••••••" defaultValue="password" />
          </div>
          <Button variant="primary" fullWidth type="submit">Sign in</Button>
          <p style={{ textAlign: "center", fontSize: 14, color: "var(--text-muted)" }}>
            New here? <a href="#" onClick={(e) => { e.preventDefault(); onSignIn(); }}>Create a family</a>
          </p>
        </form>
      </div>
    </div>
  );
}
window.WebLogin = WebLogin;
