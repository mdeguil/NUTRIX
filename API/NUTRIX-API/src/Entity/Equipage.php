<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'Equipage')]
#[ApiResource]
class Equipage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'Id_Equipage')]
    private ?int $id = null;

    /**
     * Convention : true = Homme (M), false = Femme (F) — voir MOTEUR_CALCUL.md §1 (ecart #6).
     */
    #[ORM\Column(name: 'sexe')]
    private ?bool $sexe = null;

    #[ORM\Column(name: 'age', type: 'smallint')]
    #[Assert\PositiveOrZero]
    private ?int $age = null;

    #[ORM\Column(name: 'poids_kilo', type: 'float')]
    #[Assert\Positive]
    private ?float $poidsKilo = null;

    #[ORM\Column(name: 'taille_cm', type: 'smallint')]
    #[Assert\Positive]
    private ?int $tailleCm = null;

    #[ORM\Column(name: 'bmi', type: 'float')]
    private ?float $bmi = null;

    #[ORM\Column(name: 'pal', type: 'float')]
    private ?float $pal = null;

    #[ORM\ManyToOne(targetEntity: ActivityLabel::class)]
    #[ORM\JoinColumn(name: 'Id_Activity_label', referencedColumnName: 'Id_Activity_label', nullable: false)]
    #[Assert\NotNull]
    private ?ActivityLabel $activityLabel = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isSexe(): ?bool
    {
        return $this->sexe;
    }

    public function setSexe(bool $sexe): static
    {
        $this->sexe = $sexe;

        return $this;
    }

    public function getAge(): ?int
    {
        return $this->age;
    }

    public function setAge(int $age): static
    {
        $this->age = $age;

        return $this;
    }

    public function getPoidsKilo(): ?float
    {
        return $this->poidsKilo;
    }

    public function setPoidsKilo(float $poidsKilo): static
    {
        $this->poidsKilo = $poidsKilo;

        return $this;
    }

    public function getTailleCm(): ?int
    {
        return $this->tailleCm;
    }

    public function setTailleCm(int $tailleCm): static
    {
        $this->tailleCm = $tailleCm;

        return $this;
    }

    public function getBmi(): ?float
    {
        return $this->bmi;
    }

    public function setBmi(float $bmi): static
    {
        $this->bmi = $bmi;

        return $this;
    }

    public function getPal(): ?float
    {
        return $this->pal;
    }

    public function setPal(float $pal): static
    {
        $this->pal = $pal;

        return $this;
    }

    public function getActivityLabel(): ?ActivityLabel
    {
        return $this->activityLabel;
    }

    public function setActivityLabel(?ActivityLabel $activityLabel): static
    {
        $this->activityLabel = $activityLabel;

        return $this;
    }
}
