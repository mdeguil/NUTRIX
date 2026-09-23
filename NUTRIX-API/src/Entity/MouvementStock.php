<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'MOUVEMENT_STOCK')]
#[ApiResource(
    openapi: false,
    security: "is_granted('ROLE_USER')",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_FERME')"),
        new Put(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_FERME')"),
        new Patch(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_FERME')"),
        new Delete(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_FERME')"),
    ],
)]
class MouvementStock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'Id_MOUVEMENT_STOCK')]
    private ?int $id = null;

    /**
     * entree / sortie / perte — a confirmer avec l'equipe, pas de contrainte Choice
     * en base (colonne VARCHAR libre) donc pas de valeurs figees ici pour l'instant.
     */
    #[ORM\Column(name: 'type_mouvement', length: 50)]
    #[Assert\NotBlank]
    private ?string $typeMouvement = null;

    #[ORM\Column(name: 'quantite_g', type: 'decimal', precision: 15, scale: 2)]
    #[Assert\NotBlank]
    private ?string $quantiteG = null;

    #[ORM\Column(name: 'date_mouvement', type: 'datetime')]
    private ?\DateTime $dateMouvement = null;

    #[ORM\ManyToOne(targetEntity: LotStock::class)]
    #[ORM\JoinColumn(name: 'Id_LOT_STOCK', referencedColumnName: 'Id_LOT_STOCK', nullable: false)]
    #[Assert\NotNull]
    private ?LotStock $lotStock = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTypeMouvement(): ?string
    {
        return $this->typeMouvement;
    }

    public function setTypeMouvement(string $typeMouvement): static
    {
        $this->typeMouvement = $typeMouvement;

        return $this;
    }

    public function getQuantiteG(): ?string
    {
        return $this->quantiteG;
    }

    public function setQuantiteG(string $quantiteG): static
    {
        $this->quantiteG = $quantiteG;

        return $this;
    }

    public function getDateMouvement(): ?\DateTime
    {
        return $this->dateMouvement;
    }

    public function setDateMouvement(\DateTime $dateMouvement): static
    {
        $this->dateMouvement = $dateMouvement;

        return $this;
    }

    public function getLotStock(): ?LotStock
    {
        return $this->lotStock;
    }

    public function setLotStock(?LotStock $lotStock): static
    {
        $this->lotStock = $lotStock;

        return $this;
    }
}
