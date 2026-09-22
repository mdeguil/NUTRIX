import { useState } from 'react';
import './Dashboard.css';

interface MacroGaugeProps {
  title: string;
  current: number;
  target: number;
  unit: string;
}

function LinearGaugeCard({ title, current, target, unit }: MacroGaugeProps) {
  const percentage = Math.min(100, Math.round((current / target) * 100));

  return (
    <div className="macro-card">
      <div className="macro-card__header">
        <span className="macro-card__title">{title}</span>
        <span className="macro-card__percentage font-ui">{percentage}%</span>
      </div>

      <div className="macro-card__values">
        <div className="macro-card__current font-subtitle">
          {current.toLocaleString('fr-FR')} <span className="macro-card__unit">{unit}</span>
        </div>
        <div className="macro-card__target font-ui">
          / {target.toLocaleString('fr-FR')} {unit}
        </div>
      </div>

      <div className="macro-card__bar-track">
        <div
          className="macro-card__bar-fill"
          style={{ width: `${percentage}%` }}
        />
      </div>
    </div>
  );
}

export default function Dashboard() {
  const [data] = useState({
    calories: { current: 2150, target: 2500 },
    proteines: { current: 92, target: 110, unit: 'g' },
    lipides: { current: 65, target: 80, unit: 'g' },
    glucides: { current: 245, target: 300, unit: 'g' },
    fibres: { current: 28, target: 35, unit: 'g' },
    eau: { current: 2.7, target: 3.5, unit: 'L' },
  });

  const caloriesPercent = Math.round((data.calories.current / data.calories.target) * 100);
  const caloriesRemaining = Math.max(0, data.calories.target - data.calories.current);

  // SVG circular calculations
  const radius = 82;
  const circumference = 2 * Math.PI * radius;
  const strokeDashoffset = circumference - (caloriesPercent / 100) * circumference;

  return (
    <div className="dashboard">
      <div className="dashboard__header">
        <div>
          <h2 className="dashboard__title">Aujourd'hui — Bilan Nutritionnel</h2>
          <p className="dashboard__subtitle font-body">
            Suivi quotidien des besoins nutritionnels.
          </p>
        </div>
      </div>

      {/* Jauge Principale : Calories (Circulaire avec détails Consommé / Restant / Objectif) */}
      <section className="dashboard__section">
        <h3 className="dashboard__section-title">Apport Énergétique (Calories)</h3>
        <div className="circular-card">
          <div className="circular-card__gauge-container">
            <svg className="circular-gauge__svg" viewBox="0 0 200 200">
              <circle
                className="circular-gauge__bg"
                cx="100"
                cy="100"
                r={radius}
              />
              <circle
                className="circular-gauge__fill"
                cx="100"
                cy="100"
                r={radius}
                style={{
                  strokeDasharray: circumference,
                  strokeDashoffset: strokeDashoffset,
                }}
              />
            </svg>
            <div className="circular-gauge__content">
              <span className="circular-gauge__value font-title">
                {data.calories.current.toLocaleString('fr-FR')}
              </span>
              <span className="circular-gauge__unit font-ui">kcal</span>
              <span className="circular-gauge__percent font-ui">{caloriesPercent}%</span>
            </div>
          </div>

          <div className="circular-card__summary">
            <div className="summary-box">
              <span className="summary-box__label font-ui">Consommés</span>
              <span className="summary-box__value font-title">
                {data.calories.current.toLocaleString('fr-FR')} <span className="summary-box__unit">kcal</span>
              </span>
            </div>

            <div className="summary-box">
              <span className="summary-box__label font-ui">Restants</span>
              <span className="summary-box__value font-title summary-box__value--remaining">
                {caloriesRemaining.toLocaleString('fr-FR')} <span className="summary-box__unit">kcal</span>
              </span>
            </div>

            <div className="summary-box">
              <span className="summary-box__label font-ui">Objectif Journalier</span>
              <span className="summary-box__value font-title">
                {data.calories.target.toLocaleString('fr-FR')} <span className="summary-box__unit">kcal</span>
              </span>
            </div>
          </div>
        </div>
      </section>

      {/* Jauges Linéaires : Macronutriments */}
      <section className="dashboard__section">
        <h3 className="dashboard__section-title">Macronutriments principaux</h3>
        <div className="dashboard__grid-3">
          <LinearGaugeCard
            title="Protéines"
            current={data.proteines.current}
            target={data.proteines.target}
            unit={data.proteines.unit}
          />
          <LinearGaugeCard
            title="Lipides"
            current={data.lipides.current}
            target={data.lipides.target}
            unit={data.lipides.unit}
          />
          <LinearGaugeCard
            title="Glucides"
            current={data.glucides.current}
            target={data.glucides.target}
            unit={data.glucides.unit}
          />
        </div>
      </section>

      {/* Jauges Linéaires : Hydratation & Fibres */}
      <section className="dashboard__section">
        <h3 className="dashboard__section-title">Fibres & Hydratation</h3>
        <div className="dashboard__grid-2">
          <LinearGaugeCard
            title="Fibres alimentaires"
            current={data.fibres.current}
            target={data.fibres.target}
            unit={data.fibres.unit}
          />
          <LinearGaugeCard
            title="Eau & Hydratation"
            current={data.eau.current}
            target={data.eau.target}
            unit={data.eau.unit}
          />
        </div>
      </section>
    </div>
  );
}
