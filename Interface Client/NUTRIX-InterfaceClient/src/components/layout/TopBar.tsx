import { useLocation } from 'react-router-dom';
import './TopBar.css';

const PAGE_TITLES: Record<string, string> = {
  '/dashboard':     'Dashboard — Vue d\'ensemble',
  '/occupants':     'Occupants',
  '/journal':       'Journal alimentaire',
  '/stock':         'Stock alimentaire',
  '/previsionnel':  'Prévision sur 8 semaines',
  '/simulation':    'Simulation de crise',
  '/planificateur': 'Planificateur de repas',
  '/agriculture':   'Agriculture & Récoltes',
};

export default function TopBar() {
  const { pathname } = useLocation();

  // Match base path (e.g. /occupants/42 → /occupants)
  const basePath = '/' + pathname.split('/')[1];
  const title = PAGE_TITLES[basePath] ?? 'NUTRIX';

  return (
    <header className="topbar">
      <div className="topbar__left">
        <h1 className="topbar__title">{title}</h1>
      </div>
      <div className="topbar__right">
        <span className="topbar__date">
          {new Date().toLocaleDateString('fr-FR', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
          })}
        </span>
        <span className="topbar__badge topbar__badge--ok">Système nominal</span>
      </div>
    </header>
  );
}
