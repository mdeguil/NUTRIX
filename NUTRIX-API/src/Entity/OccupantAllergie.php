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
#[ORM\Table(name: 'OCCUPANT_ALLERGIE')]
#[ApiResource(
    openapi: false,
    uriTemplate: '/occupant_allergies/{equipageId}/{allergeneId}',
    uriVariables: [
        'equipageId' => new Link(fromClass: Equipage::class, identifiers: ['id'], toProperty: 'equipage'),
        'allergeneId' => new Link(fromClass: Allergene::class, identifiers: ['id'], toProperty: 'allergene'),
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
    uriTemplate: '/equipages/{equipageId}/allergies',
    uriVariables: [
        'equipageId' => new Link(fromClass: Equipage::class, identifiers: ['id'], toProperty: 'equipage'),
    ],
    security: "is_granted('ROLE_USER')",
    operations: [new GetCollection()],
)]
class OccupantAllergie
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Equipage::class)]
    #[ORM\JoinColumn(name: 'Id_Equipage', referencedColumnName: 'Id_Equipage', nullable: false)]
    #[Assert\NotNull]
    private ?Equipage $equipage = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Allergene::class)]
    #[ORM\JoinColumn(name: 'Id_ALLERGENE', referencedColumnName: 'Id_ALLERGENE', nullable: false)]
    #[Assert\NotNull]
    private ?Allergene $allergene = null;

    public function getEquipage(): ?Equipage
    {
        return $this->equipage;
    }

    public function setEquipage(?Equipage $equipage): static
    {
        $this->equipage = $equipage;

        return $this;
    }

    public function getAllergene(): ?Allergene
    {
        return $this->allergene;
    }

    public function setAllergene(?Allergene $allergene): static
    {
        $this->allergene = $allergene;

        return $this;
    }
}
