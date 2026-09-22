import { useParams } from 'react-router-dom';

export default function OccupantDetail() {
  const { id } = useParams();

  return (
    <div>
      <h2>Profil occupant #{id}</h2>
      <p>Détail du profil nutritionnel et historique — à implémenter.</p>
    </div>
  );
}
