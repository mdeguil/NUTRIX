import { Link } from 'react-router-dom';

export default function NotFound() {
  return (
    <div style={{ textAlign: 'center', padding: '80px 20px' }}>
      <p style={{ fontSize: 72, margin: 0, opacity: 0.3 }}>404</p>
      <h2 style={{ color: '#c8d8ff', marginTop: 16 }}>Page introuvable</h2>
      <p style={{ color: '#4a5878', marginTop: 8 }}>Cette route n'existe pas dans NUTRIX.</p>
      <Link to="/dashboard" style={{ color: '#4af0b0', marginTop: 24, display: 'inline-block' }}>
        ← Retour au Dashboard
      </Link>
    </div>
  );
}
