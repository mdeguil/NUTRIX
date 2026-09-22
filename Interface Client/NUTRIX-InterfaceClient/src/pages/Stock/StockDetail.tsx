import { useParams } from 'react-router-dom';

export default function StockDetail() {
  const { id } = useParams();

  return (
    <div>
      <h2>Fiche produit #{id}</h2>
      <p>Valeurs nutritionnelles, péremption, emplacement, historique — à implémenter.</p>
    </div>
  );
}
