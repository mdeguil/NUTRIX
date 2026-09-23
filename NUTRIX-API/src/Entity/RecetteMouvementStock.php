<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'Asso_11')]
#[ApiResource(
    openapi: false,
    uriTemplate: '/recette_mouvement_stocks/{recetteId}/{mouvementStockId}',
    uriVariables: [
        'recetteId' => new Link(fromClass: Recette::class, identifiers: ['id'], toProperty: 'recette'),
        'mouvementStockId' => new Link(fromClass: MouvementStock::class, identifiers: ['id'], toProperty: 'mouvementStock'),
    ],
    security: "is_granted('ROLE_USER')",
    operations: [
        new Get(),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
)]
#[ApiResource(
    openapi: false,
    uriTemplate: '/recettes/{recetteId}/mouvements_stock',
    uriVariables: [
        'recetteId' => new Link(fromClass: Recette::class, identifiers: ['id'], toProperty: 'recette'),
    ],
    security: "is_granted('ROLE_USER')",
    operations: [new GetCollection()],
)]
class RecetteMouvementStock
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Recette::class)]
    #[ORM\JoinColumn(name: 'Id_Recette', referencedColumnName: 'Id_Recette', nullable: false)]
    #[Assert\NotNull]
    private ?Recette $recette = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: MouvementStock::class)]
    #[ORM\JoinColumn(name: 'Id_MOUVEMENT_STOCK', referencedColumnName: 'Id_MOUVEMENT_STOCK', nullable: false)]
    #[Assert\NotNull]
    private ?MouvementStock $mouvementStock = null;

    public function getRecette(): ?Recette
    {
        return $this->recette;
    }

    public function setRecette(?Recette $recette): static
    {
        $this->recette = $recette;

        return $this;
    }

    public function getMouvementStock(): ?MouvementStock
    {
        return $this->mouvementStock;
    }

    public function setMouvementStock(?MouvementStock $mouvementStock): static
    {
        $this->mouvementStock = $mouvementStock;

        return $this;
    }
}
