<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'LOT_STOCK')]
#[ApiResource]
class LotStock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'Id_LOT_STOCK')]
    private ?int $id = null;

    #[ORM\Column(name: 'qr_code', length: 50)]
    #[Assert\NotBlank]
    private ?string $qrCode = null;

    #[ORM\Column(name: 'quantite_initiale_g', type: 'float')]
    #[Assert\PositiveOrZero]
    private ?float $quantiteInitialeG = null;

    #[ORM\Column(name: 'quantite_disponible_g', type: 'float')]
    #[Assert\PositiveOrZero]
    private ?float $quantiteDisponibleG = null;

    #[ORM\Column(name: 'date_entree', type: 'date_immutable')]
    private ?\DateTimeImmutable $dateEntree = null;

    #[ORM\Column(name: 'date_peremption', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $datePeremption = null;

    #[ORM\Column(name: 'emplacement', length: 50)]
    #[Assert\NotBlank]
    private ?string $emplacement = null;

    /**
     * courante / securite / urgence / strategique — voir SCHEMA_BDD.md §3.2.
     */
    #[ORM\Column(name: 'type_reserve', length: 50)]
    #[Assert\Choice(choices: ['courante', 'securite', 'urgence', 'strategique'])]
    private ?string $typeReserve = null;

    #[ORM\Column(name: 'statut', length: 50)]
    #[Assert\Choice(choices: ['frais', 'transforme', 'congele', 'epuise', 'perime'])]
    private ?string $statut = null;

    #[ORM\ManyToOne(targetEntity: Aliment::class)]
    #[ORM\JoinColumn(name: 'Id_Aliment', referencedColumnName: 'Id_Aliment', nullable: false)]
    #[Assert\NotNull]
    private ?Aliment $aliment = null;

    #[ORM\ManyToOne(targetEntity: Recolte::class)]
    #[ORM\JoinColumn(name: 'Id_RECOLTE', referencedColumnName: 'Id_RECOLTE', nullable: true)]
    private ?Recolte $recolte = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQrCode(): ?string
    {
        return $this->qrCode;
    }

    public function setQrCode(string $qrCode): static
    {
        $this->qrCode = $qrCode;

        return $this;
    }

    public function getQuantiteInitialeG(): ?float
    {
        return $this->quantiteInitialeG;
    }

    public function setQuantiteInitialeG(float $quantiteInitialeG): static
    {
        $this->quantiteInitialeG = $quantiteInitialeG;

        return $this;
    }

    public function getQuantiteDisponibleG(): ?float
    {
        return $this->quantiteDisponibleG;
    }

    public function setQuantiteDisponibleG(float $quantiteDisponibleG): static
    {
        $this->quantiteDisponibleG = $quantiteDisponibleG;

        return $this;
    }

    public function getDateEntree(): ?\DateTimeImmutable
    {
        return $this->dateEntree;
    }

    public function setDateEntree(\DateTimeImmutable $dateEntree): static
    {
        $this->dateEntree = $dateEntree;

        return $this;
    }

    public function getDatePeremption(): ?\DateTimeImmutable
    {
        return $this->datePeremption;
    }

    public function setDatePeremption(?\DateTimeImmutable $datePeremption): static
    {
        $this->datePeremption = $datePeremption;

        return $this;
    }

    public function getEmplacement(): ?string
    {
        return $this->emplacement;
    }

    public function setEmplacement(string $emplacement): static
    {
        $this->emplacement = $emplacement;

        return $this;
    }

    public function getTypeReserve(): ?string
    {
        return $this->typeReserve;
    }

    public function setTypeReserve(string $typeReserve): static
    {
        $this->typeReserve = $typeReserve;

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

    public function getAliment(): ?Aliment
    {
        return $this->aliment;
    }

    public function setAliment(?Aliment $aliment): static
    {
        $this->aliment = $aliment;

        return $this;
    }

    public function getRecolte(): ?Recolte
    {
        return $this->recolte;
    }

    public function setRecolte(?Recolte $recolte): static
    {
        $this->recolte = $recolte;

        return $this;
    }
}
