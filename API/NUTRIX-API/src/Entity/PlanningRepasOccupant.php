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
#[ORM\Table(name: 'PLANNING_REPAS_OCCUPANT')]
#[ApiResource(
    security: "is_granted('ROLE_USER')",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Put(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
)]
class PlanningRepasOccupant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'Id_PLANNING_REPAS_OCCUPANT')]
    private ?int $id = null;

    #[ORM\Column(name: 'portion_ratio', type: 'decimal', precision: 6, scale: 2, nullable: true)]
    private ?string $portionRatio = null;

    #[ORM\ManyToOne(targetEntity: Equipage::class)]
    #[ORM\JoinColumn(name: 'Id_Equipage', referencedColumnName: 'Id_Equipage', nullable: false)]
    #[Assert\NotNull]
    private ?Equipage $equipage = null;

    #[ORM\ManyToOne(targetEntity: PlanningRepas::class)]
    #[ORM\JoinColumn(name: 'Id_PLANNING_REPAS', referencedColumnName: 'Id_PLANNING_REPAS', nullable: false)]
    #[Assert\NotNull]
    private ?PlanningRepas $planningRepas = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPortionRatio(): ?string
    {
        return $this->portionRatio;
    }

    public function setPortionRatio(?string $portionRatio): static
    {
        $this->portionRatio = $portionRatio;

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

    public function getPlanningRepas(): ?PlanningRepas
    {
        return $this->planningRepas;
    }

    public function setPlanningRepas(?PlanningRepas $planningRepas): static
    {
        $this->planningRepas = $planningRepas;

        return $this;
    }
}
