<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'Aliment')]
#[ApiResource]
class Aliment
{
    /**
     * Cle naturelle (code ingredient), assignee a l'import/la saisie — pas auto-generee.
     */
    #[ORM\Id]
    #[ORM\Column(name: 'Id_Aliment', length: 50)]
    #[Assert\NotBlank]
    private ?string $id = null;

    #[ORM\Column(name: 'Libelle', length: 50)]
    #[Assert\NotBlank]
    private ?string $libelle = null;

    #[ORM\Column(name: 'Kcal_100g', type: 'float')]
    #[Assert\PositiveOrZero]
    private ?float $kcal100g = null;

    #[ORM\Column(name: 'Proteines_100g', type: 'float')]
    #[Assert\PositiveOrZero]
    private ?float $proteines100g = null;

    #[ORM\Column(name: 'Glucides_100g', type: 'float')]
    #[Assert\PositiveOrZero]
    private ?float $glucides100g = null;

    #[ORM\Column(name: 'Lipides_100g', type: 'float')]
    #[Assert\PositiveOrZero]
    private ?float $lipides100g = null;

    #[ORM\Column(name: 'Fibres_100g', type: 'float')]
    #[Assert\PositiveOrZero]
    private ?float $fibres100g = null;

    #[ORM\Column(name: 'Cycle_jours_min', type: 'smallint')]
    #[Assert\PositiveOrZero]
    private ?int $cycleJoursMin = null;

    #[ORM\Column(name: 'rendement_g_m2_j', type: 'float', nullable: true)]
    private ?float $rendementGM2J = null;

    #[ORM\Column(name: 'partie_replantable', length: 50, nullable: true)]
    private ?string $partieReplantable = null;

    #[ORM\Column(name: 'Cycle_jours_max', type: 'smallint')]
    #[Assert\PositiveOrZero]
    private ?int $cycleJoursMax = null;

    #[ORM\ManyToOne(targetEntity: UniteStock::class)]
    #[ORM\JoinColumn(name: 'Id_unite_stock', referencedColumnName: 'Id_unite_stock', nullable: true)]
    private ?UniteStock $uniteStock = null;

    #[ORM\ManyToOne(targetEntity: CategorieIngredient::class)]
    #[ORM\JoinColumn(name: 'Id_Categorie_ingredient', referencedColumnName: 'Id_Categorie_ingredient', nullable: true)]
    private ?CategorieIngredient $categorieIngredient = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getKcal100g(): ?float
    {
        return $this->kcal100g;
    }

    public function setKcal100g(float $kcal100g): static
    {
        $this->kcal100g = $kcal100g;

        return $this;
    }

    public function getProteines100g(): ?float
    {
        return $this->proteines100g;
    }

    public function setProteines100g(float $proteines100g): static
    {
        $this->proteines100g = $proteines100g;

        return $this;
    }

    public function getGlucides100g(): ?float
    {
        return $this->glucides100g;
    }

    public function setGlucides100g(float $glucides100g): static
    {
        $this->glucides100g = $glucides100g;

        return $this;
    }

    public function getLipides100g(): ?float
    {
        return $this->lipides100g;
    }

    public function setLipides100g(float $lipides100g): static
    {
        $this->lipides100g = $lipides100g;

        return $this;
    }

    public function getFibres100g(): ?float
    {
        return $this->fibres100g;
    }

    public function setFibres100g(float $fibres100g): static
    {
        $this->fibres100g = $fibres100g;

        return $this;
    }

    public function getCycleJoursMin(): ?int
    {
        return $this->cycleJoursMin;
    }

    public function setCycleJoursMin(int $cycleJoursMin): static
    {
        $this->cycleJoursMin = $cycleJoursMin;

        return $this;
    }

    public function getRendementGM2J(): ?float
    {
        return $this->rendementGM2J;
    }

    public function setRendementGM2J(?float $rendementGM2J): static
    {
        $this->rendementGM2J = $rendementGM2J;

        return $this;
    }

    public function getPartieReplantable(): ?string
    {
        return $this->partieReplantable;
    }

    public function setPartieReplantable(?string $partieReplantable): static
    {
        $this->partieReplantable = $partieReplantable;

        return $this;
    }

    public function getCycleJoursMax(): ?int
    {
        return $this->cycleJoursMax;
    }

    public function setCycleJoursMax(int $cycleJoursMax): static
    {
        $this->cycleJoursMax = $cycleJoursMax;

        return $this;
    }

    public function getUniteStock(): ?UniteStock
    {
        return $this->uniteStock;
    }

    public function setUniteStock(?UniteStock $uniteStock): static
    {
        $this->uniteStock = $uniteStock;

        return $this;
    }

    public function getCategorieIngredient(): ?CategorieIngredient
    {
        return $this->categorieIngredient;
    }

    public function setCategorieIngredient(?CategorieIngredient $categorieIngredient): static
    {
        $this->categorieIngredient = $categorieIngredient;

        return $this;
    }
}
