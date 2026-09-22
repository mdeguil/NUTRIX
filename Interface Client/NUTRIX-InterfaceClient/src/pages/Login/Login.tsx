import { Link } from 'react-router-dom';
import './Login.css';

export default function Login() {
  return (
    <div className="login">
      <div className="login__card">
        <div className="login__brand">
          <img 
            src="/images/Logos/svg/nutrix-logo-transparent-pour-fond-sombre.svg" 
            alt="NUTRIX Logo" 
            className="login__logo-img"
          />
        </div>
        <p className="login__subtitle">Système autonome de planification alimentaire</p>

        <form className="login__form" onSubmit={(e) => e.preventDefault()}>
          <div className="login__field">
            <label htmlFor="login-username">Identifiant</label>
            <input id="login-username" type="text" placeholder="Votre identifiant" autoComplete="username" />
          </div>
          <div className="login__field">
            <label htmlFor="login-password">Mot de passe</label>
            <input id="login-password" type="password" placeholder="••••••••" autoComplete="current-password" />
          </div>
          <Link to="/dashboard" className="login__btn" id="login-submit">
            Accéder au système
          </Link>
          <Link to="/register" className="login__register_btn" id="login-register_btn">
            Créer un compte
          </Link>
        </form>

        <p className="login__version">NUTRIX v1.0 — Autonomie spatiale</p>
      </div>
    </div>
  );
}
