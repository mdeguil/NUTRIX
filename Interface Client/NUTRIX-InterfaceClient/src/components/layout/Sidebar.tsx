import { NavLink } from 'react-router-dom';
import './Sidebar.css';

const navItems = [
  { to: '/dashboard', icon: '◈', label: 'Dashboard' },
  { to: '/occupants', icon: '◉', label: 'Occupants' },
  { to: '/journal', icon: '◷', label: 'Journal alimentaire' },
  { to: '/stock', icon: '▦', label: 'Stock' },
  { to: '/previsionnel', icon: '◻', label: 'Prévisionnel' },
  { to: '/planificateur', icon: '◈', label: 'Planificateur' },
  { to: '/agriculture', icon: '◑', label: 'Agriculture' },
];

export default function Sidebar() {
  return (
    <aside className="sidebar">
      <div className="sidebar__brand">
        <img
          src="/images/Logos/svg/nutrix-logo-transparent-pour-fond-sombre.svg"
          alt="NUTRIX Logo"
          className="sidebar__logo-img"
        />
      </div>

      <nav className="sidebar__nav">
        {navItems.map(({ to, icon, label }) => (
          <NavLink
            key={to}
            to={to}
            className={({ isActive }) =>
              `sidebar__link${isActive ? ' sidebar__link--active' : ''}`
            }
          >
            <span className="sidebar__icon">{icon}</span>
            <span className="sidebar__label">{label}</span>
          </NavLink>
        ))}
      </nav>

      <div className="sidebar__footer">
        <span className="sidebar__logout_btn" onClick={() => { }}>⏼ Déconnexion</span>
        <span className="sidebar__status">● Système actif</span>
      </div>
    </aside>
  );
}
