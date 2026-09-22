<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Attention : type_repas est ici un simple VARCHAR (pas de FK vers la table type_repas,
 * contrairement a JOURNAL_REPAS.Id_type_repas) — incoherence du schema actuel documentee
 * dans MOTEUR_CALCUL.md §1 (ecart #7).
 */
#[ORM\Entity]
#[ORM\Table(name: 'PLANNING_REPAS')]
#[ApiResource]
class PlanningRepas
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'Id_PLANNING_REPAS')]
    private ?int $id = null;

    #[ORM\Column(name: 'date_', type: 'date_immutable')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(name: 'portions_prevues', type: 'float')]
    #[Assert\PositiveOrZero]
    private ?float $portionsPrevues = null;

    #[ORM\Column(name: 'type_repas', length: 50)]
    #[Assert\NotBlank]
    private ?string $typeRepas = null;

    #[ORM\ManyToOne(targetEntity: Recette::class)]
    #[ORM\JoinColumn(name: 'Id_Recette', referencedColumnName: 'Id_Recette', nullable: false)]
    #[Assert\NotNull]
    private ?Recette $recette = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getPortionsPrevues(): ?float
    {
        return $this->portionsPrevues;
    }

    public function setPortionsPrevues(float $portionsPrevues): static
    {
        $this->portionsPrevues = $portionsPrevues;

        return $this;
    }

    public function getTypeRepas(): ?string
    {
        return $this->typeRepas;
    }

    public function setTypeRepas(string $typeRepas): static
    {
        $this->typeRepas = $typeRepas;

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
}
