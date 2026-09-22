<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'RECOLTE')]
#[ApiResource]
class Recolte
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'Id_RECOLTE')]
    private ?int $id = null;

    #[ORM\Column(name: 'module_culture', length: 50)]
    #[Assert\NotBlank]
    private ?string $moduleCulture = null;

    #[ORM\Column(name: 'date_semis', type: 'date_immutable')]
    private ?\DateTimeImmutable $dateSemis = null;

    #[ORM\Column(name: 'date_recolte_prevue', type: 'date_immutable')]
    private ?\DateTimeImmutable $dateRecoltePrevue = null;

    #[ORM\Column(name: 'date_recolte_reelle', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateRecolteReelle = null;

    #[ORM\Column(name: 'quantite_prevue_g', type: 'float')]
    #[Assert\PositiveOrZero]
    private ?float $quantitePrevueG = null;

    #[ORM\Column(name: 'quantite_reelle_g', type: 'float', nullable: true)]
    #[Assert\PositiveOrZero]
    private ?float $quantiteReelleG = null;

    #[ORM\Column(name: 'statut', length: 50)]
    #[Assert\Choice(choices: ['semis', 'croissance', 'recolte', 'perdue', 'replantee'])]
    private ?string $statut = null;

    #[ORM\Column(name: 'taux_perte_pct', type: 'float', nullable: true)]
    #[Assert\Range(min: 0, max: 100)]
    private ?float $tauxPertePct = null;

    #[ORM\ManyToOne(targetEntity: Aliment::class)]
    #[ORM\JoinColumn(name: 'Id_Aliment', referencedColumnName: 'Id_Aliment', nullable: false)]
    #[Assert\NotNull]
    private ?Aliment $aliment = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModuleCulture(): ?string
    {
        return $this->moduleCulture;
    }

    public function setModuleCulture(string $moduleCulture): static
    {
        $this->moduleCulture = $moduleCulture;

        return $this;
    }

    public function getDateSemis(): ?\DateTimeImmutable
    {
        return $this->dateSemis;
    }

    public function setDateSemis(\DateTimeImmutable $dateSemis): static
    {
        $this->dateSemis = $dateSemis;

        return $this;
    }

    public function getDateRecoltePrevue(): ?\DateTimeImmutable
    {
        return $this->dateRecoltePrevue;
    }

    public function setDateRecoltePrevue(\DateTimeImmutable $dateRecoltePrevue): static
    {
        $this->dateRecoltePrevue = $dateRecoltePrevue;

        return $this;
    }

    public function getDateRecolteReelle(): ?\DateTimeImmutable
    {
        return $this->dateRecolteReelle;
    }

    public function setDateRecolteReelle(?\DateTimeImmutable $dateRecolteReelle): static
    {
        $this->dateRecolteReelle = $dateRecolteReelle;

        return $this;
    }

    public function getQuantitePrevueG(): ?float
    {
        return $this->quantitePrevueG;
    }

    public function setQuantitePrevueG(float $quantitePrevueG): static
    {
        $this->quantitePrevueG = $quantitePrevueG;

        return $this;
    }

    public function getQuantiteReelleG(): ?float
    {
        return $this->quantiteReelleG;
    }

    public function setQuantiteReelleG(?float $quantiteReelleG): static
    {
        $this->quantiteReelleG = $quantiteReelleG;

        return $this;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getTauxPertePct(): ?float
    {
        return $this->tauxPertePct;
    }

    public function setTauxPertePct(?float $tauxPertePct): static
    {
        $this->tauxPertePct = $tauxPertePct;

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
}
