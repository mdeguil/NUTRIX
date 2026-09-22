import { Link } from 'react-router-dom';
import './Register.css';

export default function Register() {
  return (
    <div className="register">
      <div className="register__card">
        <div className="register__brand">
          <img 
            src="/images/Logos/svg/nutrix-logo-transparent-pour-fond-sombre.svg" 
            alt="NUTRIX Logo" 
            className="register__logo-img"
          />
        </div>
        <p className="register__subtitle">Création de profil occupant / système</p>

        <form className="register__form" onSubmit={(e) => e.preventDefault()}>
          <div className="register__field">
            <label htmlFor="register-nom">Nom</label>
            <input id="register-nom" type="text" placeholder="Votre nom" autoComplete="family-name" />
          </div>
          <div className="register__field">
            <label htmlFor="register-prenom">Prénom</label>
            <input id="register-prenom" type="text" placeholder="Votre prénom" autoComplete="given-name" />
          </div>
          <div className="register__field">
            <label htmlFor="register-username">Identifiant</label>
            <input id="register-username" type="text" placeholder="Votre identifiant" autoComplete="username" />
          </div>
          <div className="register__field">
            <label htmlFor="register-password">Mot de passe</label>
            <input id="register-password" type="password" placeholder="••••••••" autoComplete="new-password" />
          </div>
          <Link to="/dashboard" className="register__btn" id="register-submit">
            Créer mon compte
          </Link>
          <Link to="/" className="register__back_btn" id="register-back_btn">
            Retour à la connexion
          </Link>
        </form>

        <p className="register__version">NUTRIX v1.0 — Autonomie spatiale</p>
      </div>
    </div>
  );
}
