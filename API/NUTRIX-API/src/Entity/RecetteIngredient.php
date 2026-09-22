<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ATTENTION : GET direct sur /recette_ingredients/{recetteId}/{alimentId} renvoie une 500.
 * API Platform 5 ne resout pas correctement l'identite composite quand deux Link ciblent
 * des classes differentes convergeant sur la meme entite (la requete DQL generee part de
 * la mauvaise entite racine). La route reste declaree uniquement pour permettre a
 * l'IriConverter de generer les "@id" des items dans /recettes/{recetteId}/ingredients
 * (qui, elle, fonctionne). A corriger si des operations d'edition individuelles deviennent
 * necessaires (probablement via un state provider custom).
 */
#[ORM\Entity]
#[ORM\Table(name: 'recette_ingredient')]
#[ApiResource(
    uriTemplate: '/recette_ingredients/{recetteId}/{alimentId}',
    uriVariables: [
        'recetteId' => new Link(fromClass: Recette::class, identifiers: ['id'], toProperty: 'recette'),
        'alimentId' => new Link(fromClass: Aliment::class, identifiers: ['id'], toProperty: 'aliment'),
    ],
)]
#[ApiResource(
    uriTemplate: '/recettes/{recetteId}/ingredients',
    uriVariables: [
        'recetteId' => new Link(fromClass: Recette::class, identifiers: ['id'], toProperty: 'recette'),
    ],
    operations: [new GetCollection()],
)]
class RecetteIngredient
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Recette::class)]
    #[ORM\JoinColumn(name: 'Id_Recette', referencedColumnName: 'Id_Recette', nullable: false)]
    #[Assert\NotNull]
    private ?Recette $recette = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Aliment::class)]
    #[ORM\JoinColumn(name: 'Id_Aliment', referencedColumnName: 'Id_Aliment', nullable: false)]
    #[Assert\NotNull]
    private ?Aliment $aliment = null;

    #[ORM\Column(name: 'quantite_g', type: 'float')]
    #[Assert\PositiveOrZero]
    private ?float $quantiteG = null;

    public function getRecette(): ?Recette
    {
        return $this->recette;
    }

    public function setRecette(?Recette $recette): static
    {
        $this->recette = $recette;

        return $this;
    }

    public function getAliment(): ?Aliment
    {
        return $this->aliment;
    }

    public function setAliment(?Aliment $aliment): static
    {
        $this->aliment = $aliment;

        return $this;
    }

    public function getQuantiteG(): ?float
    {
        return $this->quantiteG;
    }

    public function setQuantiteG(float $quantiteG): static
    {
        $this->quantiteG = $quantiteG;

        return $this;
    }
}
