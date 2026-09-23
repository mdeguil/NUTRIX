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
#[ORM\Table(name: 'JOURNAL_REPAS')]
#[ApiResource(
    openapi: false,
    security: "is_granted('ROLE_USER')",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_OCCUPANT')"),
        new Put(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_OCCUPANT')"),
        new Patch(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_OCCUPANT')"),
        new Delete(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_OCCUPANT')"),
    ],
)]
class JournalRepas
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'Id_JOURNAL_REPAS')]
    private ?int $id = null;

    #[ORM\Column(name: 'date_heure', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dateHeure = null;

    #[ORM\Column(name: 'portion_g', type: 'float', nullable: true)]
    #[Assert\PositiveOrZero]
    private ?float $portionG = null;

    #[ORM\ManyToOne(targetEntity: Recette::class)]
    #[ORM\JoinColumn(name: 'Id_Recette', referencedColumnName: 'Id_Recette', nullable: false)]
    #[Assert\NotNull]
    private ?Recette $recette = null;

    #[ORM\ManyToOne(targetEntity: Equipage::class)]
    #[ORM\JoinColumn(name: 'Id_Equipage', referencedColumnName: 'Id_Equipage', nullable: false)]
    #[Assert\NotNull]
    private ?Equipage $equipage = null;

    #[ORM\ManyToOne(targetEntity: TypeRepas::class)]
    #[ORM\JoinColumn(name: 'Id_type_repas', referencedColumnName: 'Id_type_repas', nullable: false)]
    #[Assert\NotNull]
    private ?TypeRepas $typeRepas = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDateHeure(): ?\DateTimeImmutable
    {
        return $this->dateHeure;
    }

    public function setDateHeure(\DateTimeImmutable $dateHeure): static
    {
        $this->dateHeure = $dateHeure;

        return $this;
    }

    public function getPortionG(): ?float
    {
        return $this->portionG;
    }

    public function setPortionG(?float $portionG): static
    {
        $this->portionG = $portionG;

        return $this;
    }

    public function getRecette(): ?Recette
    {
        return $this->recette;
    }

    public function setRecette(?Recette $recette): static
    {
        $this->recette = $recette;

        return $this;
    }

    public function getEquipage(): ?Equipage
    {
        return $this->equipage;
    }

    public function setEquipage(?Equipage $equipage): static
    {
        $this->equipage = $equipage;

        return $this;
    }

    public function getTypeRepas(): ?TypeRepas
    {
        return $this->typeRepas;
    }

    public function setTypeRepas(?TypeRepas $typeRepas): static
    {
        $this->typeRepas = $typeRepas;

        return $this;
    }
}
